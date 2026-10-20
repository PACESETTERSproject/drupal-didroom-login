<?php

declare(strict_types=1);

namespace Drupal\didroom_login_test;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\didroom_login\Verifier\VerifierClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Answers verifier requests from state instead of the network.
 *
 * Only the HTTP exchange is faked, so the real client still parses the
 * answers. Tests drive it with the static helpers, which work from the test
 * runner and the site under test alike.
 */
class FakeVerifierClient extends VerifierClient {

  /**
   * State key of the fake verifier: transaction, result, down, requests.
   */
  public const STATE = 'didroom_login_test.verifier';

  /**
   * A 1×1 PNG, standing in for the QR code.
   */
  public const QR_CODE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNgAAAAAgABSK+kcQAAAABJRU5ErkJggg==';

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    Settings $settings,
    LoggerInterface $logger,
    protected StateInterface $state,
  ) {
    parent::__construct($httpClient, $configFactory, $settings, $logger);
  }

  /**
   * Sets what the current transaction returns once the wallet answers.
   *
   * @param array<string, mixed>|string $result
   *   Claims keyed by credential id, or a failure reason.
   */
  public static function answer(array|string $result): void {
    self::update(\Drupal::state(), ['result' => $result]);
  }

  /**
   * Makes the verifier unreachable, or reachable again.
   */
  public static function setDown(bool $down): void {
    self::update(\Drupal::state(), ['down' => $down]);
  }

  /**
   * Returns the requests the verifier received.
   *
   * @return list<array{method: string, url: string, options: array<string, mixed>}>
   *   The requests, oldest first.
   */
  public static function requests(): array {
    return self::read(\Drupal::state())['requests'];
  }

  /**
   * {@inheritdoc}
   */
  protected function send(string $method, string $url, array $options = []): ResponseInterface {
    $verifier = self::read($this->state);
    $verifier['requests'][] = ['method' => $method, 'url' => $url, 'options' => $this->requestOptions($options)];
    self::update($this->state, ['requests' => $verifier['requests']]);
    if ($verifier['down']) {
      throw $this->error('The verifier could not be reached: @message', ['@message' => 'The fake verifier is down.'], new ConnectException('Down', new Request($method, $url)));
    }

    if ($method === 'POST' && str_ends_with($url, '/generate_authorization_request')) {
      // A new session: nothing answered yet.
      $transaction = 'transaction/' . bin2hex(random_bytes(8));
      self::update($this->state, ['transaction' => $transaction, 'result' => NULL]);
      return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'qr_code' => self::QR_CODE,
        'intent_url' => 'openid4vp://?client_id=test&request_uri=' . rawurlencode($url),
        'transaction_id' => $transaction,
      ]));
    }

    if ($method === 'GET' && $verifier['transaction'] !== NULL && str_ends_with($url, '/' . $verifier['transaction'])) {
      $result = $verifier['result'];
      if (is_array($result)) {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($result));
      }
      if (is_string($result)) {
        return new Response(200, ['Content-Type' => 'text/plain'], $result);
      }
    }
    return new Response(404, [], 'Not found');
  }

  /**
   * Reads the fake verifier's state.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   *
   * @return array{transaction: ?string, result: array<string, mixed>|string|null, down: bool, requests: list<array<string, mixed>>}
   *   The state, with defaults.
   */
  protected static function read(StateInterface $state): array {
    // Another process may have changed it.
    $state->resetCache();
    return $state->get(self::STATE, []) + [
      'transaction' => NULL,
      'result' => NULL,
      'down' => FALSE,
      'requests' => [],
    ];
  }

  /**
   * Merges values into the fake verifier's state.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param array<string, mixed> $values
   *   The values to set.
   */
  protected static function update(StateInterface $state, array $values): void {
    $state->set(self::STATE, $values + self::read($state));
  }

}
