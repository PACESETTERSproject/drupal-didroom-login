<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Verifier;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Talks to a self-hosted DIDroom verifier microservice.
 *
 * The verifier runs the OpenID4VP exchange with the wallet. This client opens
 * a session (POST generate_authorization_request) and polls its result
 * (GET transaction/<id>: 404 while pending, then JSON claims or an error
 * string).
 *
 * @see https://github.com/ForkbombEu/DIDroom_microservices
 */
class VerifierClient {

  /**
   * Settings key holding the bearer token the verifier's proxy expects.
   */
  public const TOKEN_SETTING = 'didroom_login_verifier_token';

  /**
   * Seconds to wait for the verifier before giving up.
   */
  private const TIMEOUT = 10;

  /**
   * Shape of the transaction id returned by the verifier.
   */
  private const TRANSACTION_ID_PATTERN = '/^transaction\/[A-Za-z0-9]+$/';

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected Settings $settings,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Opens a verification session for the configured credential.
   *
   * @throws \Drupal\didroom_login\Verifier\VerifierException
   *   When the module is not configured or the verifier fails.
   */
  public function createSession(): VerificationRequest {
    $base_url = $this->baseUrl();
    $body = [
      'response_mode' => 'direct_post',
      'response_type' => 'vp_token',
      'dcql_query' => $this->buildDcqlQuery(),
      // The verifier builds the wallet-facing request_uri and response_uri
      // from this, so it must be the public URL wallets can reach.
      'url' => $base_url,
    ];
    $response = $this->send('POST', $base_url . 'generate_authorization_request', [RequestOptions::JSON => $body]);
    if ($response->getStatusCode() !== 200) {
      throw $this->error('Opening a session failed with HTTP @status.', ['@status' => $response->getStatusCode()]);
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    $transaction_id = $data['transaction_id'] ?? NULL;
    $qr_code = $data['qr_code'] ?? NULL;
    $intent_url = $data['intent_url'] ?? NULL;
    if (!is_string($transaction_id) || !preg_match(self::TRANSACTION_ID_PATTERN, $transaction_id)
      || !is_string($qr_code) || !str_starts_with($qr_code, 'data:image/')
      || !is_string($intent_url) || !str_starts_with($intent_url, 'openid4vp://')) {
      throw $this->error('The verifier returned an unexpected session response.');
    }
    return new VerificationRequest($transaction_id, $qr_code, $intent_url);
  }

  /**
   * Polls the result of a verification session.
   *
   * @param string $transaction_id
   *   The id from VerificationRequest::$transactionId.
   *
   * @throws \Drupal\didroom_login\Verifier\VerifierException
   *   When the id is malformed or the verifier cannot be reached.
   */
  public function fetchResult(string $transaction_id): VerificationResult {
    if (!preg_match(self::TRANSACTION_ID_PATTERN, $transaction_id)) {
      throw $this->error('Malformed transaction id.');
    }
    $response = $this->send('GET', $this->baseUrl() . $transaction_id);
    $status = $response->getStatusCode();
    if ($status === 404) {
      return VerificationResult::pending();
    }
    if ($status !== 200) {
      throw $this->error('Fetching a result failed with HTTP @status.', ['@status' => $status]);
    }

    // Success is a JSON object of claims; failure is a plain-text reason.
    $body = trim((string) $response->getBody());
    $data = json_decode($body, TRUE);
    if (is_array($data) && $data !== [] && !array_is_list($data)) {
      return VerificationResult::verified($data);
    }
    $reason = is_string($data) ? $data : $body;
    $reason = mb_substr($reason !== '' ? $reason : 'Empty result', 0, 255);
    $this->logger->notice('The verifier rejected a presentation: @reason', ['@reason' => $reason]);
    return VerificationResult::failed($reason);
  }

  /**
   * Whether enough is configured to start a wallet login.
   *
   * Cheap and silent, for deciding whether to offer wallet login at all.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('didroom_login.settings');
    $query = json_decode((string) $config->get('dcql_query'), TRUE);
    return (string) $config->get('verifier_url') !== ''
      && array_filter((array) $config->get('trusted_issuers'), 'is_string') !== []
      && is_array($query) && is_array($query['credentials'][0] ?? NULL);
  }

  /**
   * Whether the verifier answers result requests as expected.
   *
   * Asks for the result of a session that does not exist, which the verifier
   * answers with 404. A proxy rejecting the token answers 401 or 403 instead.
   */
  public function isReachable(): bool {
    try {
      return $this->fetchResult('transaction/0')->status === VerificationStatus::Pending;
    }
    catch (VerifierException) {
      return FALSE;
    }
  }

  /**
   * Builds the DCQL query, pinning the trusted issuers into it.
   *
   * The verifier accepts any issuer unless the query restricts it, so the
   * issuer claim of the first credential is always replaced with the trusted
   * issuers list.
   *
   * @return array<string, mixed>
   *   The DCQL query.
   *
   * @throws \Drupal\didroom_login\Verifier\VerifierException
   *   When the query or the trusted issuers are not configured.
   */
  public function buildDcqlQuery(): array {
    $config = $this->configFactory->get('didroom_login.settings');
    $issuers = array_values(array_filter((array) $config->get('trusted_issuers'), 'is_string'));
    if ($issuers === []) {
      throw $this->error('No trusted issuers are configured.');
    }
    $query = json_decode((string) $config->get('dcql_query'), TRUE);
    if (!is_array($query) || !is_array($query['credentials'][0] ?? NULL)) {
      throw $this->error('The DCQL query is not configured.');
    }

    $credential = $query['credentials'][0];
    $issuer_claim = self::issuerClaim($credential['format'] ?? NULL);
    $claims = array_filter(
      is_array($credential['claims'] ?? NULL) ? $credential['claims'] : [],
      fn($claim) => !is_array($claim) || ($claim['path'] ?? NULL) !== [$issuer_claim],
    );
    $claims[] = ['path' => [$issuer_claim], 'values' => $issuers];
    $credential['claims'] = array_values($claims);
    // Claim sets would let a wallet answer without the issuer claim.
    unset($credential['claim_sets']);
    $query['credentials'][0] = $credential;
    return $query;
  }

  /**
   * Returns the name of the claim holding the issuer for a credential format.
   *
   * @param mixed $format
   *   The DCQL credential format, e.g. "dc+sd-jwt" or "ldp_vc".
   */
  public static function issuerClaim(mixed $format): string {
    return $format === 'ldp_vc' ? 'issuer' : 'iss';
  }

  /**
   * Returns the configured verifier base URL, ending in a slash.
   *
   * @throws \Drupal\didroom_login\Verifier\VerifierException
   *   When no URL is configured.
   */
  protected function baseUrl(): string {
    $url = (string) $this->configFactory->get('didroom_login.settings')->get('verifier_url');
    if ($url === '') {
      throw $this->error('The verifier URL is not configured.');
    }
    return rtrim($url, '/') . '/';
  }

  /**
   * Sends a request to the verifier without throwing on HTTP error codes.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $url
   *   The absolute URL.
   * @param array<string, mixed> $options
   *   Extra Guzzle request options.
   *
   * @throws \Drupal\didroom_login\Verifier\VerifierException
   *   When the verifier cannot be reached.
   */
  protected function send(string $method, string $url, array $options = []): ResponseInterface {
    try {
      return $this->httpClient->request($method, $url, $this->requestOptions($options));
    }
    catch (GuzzleException $e) {
      throw $this->error('The verifier could not be reached: @message', ['@message' => $e->getMessage()], $e);
    }
  }

  /**
   * Adds the timeouts, headers and token to the options of a request.
   *
   * @param array<string, mixed> $options
   *   Extra Guzzle request options.
   *
   * @return array<string, mixed>
   *   The complete Guzzle request options.
   */
  protected function requestOptions(array $options): array {
    $options += [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::TIMEOUT => self::TIMEOUT,
      RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT,
      RequestOptions::ALLOW_REDIRECTS => FALSE,
      RequestOptions::HEADERS => [],
    ];
    $options[RequestOptions::HEADERS]['Accept'] = 'application/json, text/plain';
    $token = (string) $this->settings->get(self::TOKEN_SETTING, '');
    if ($token !== '') {
      $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $token;
    }
    return $options;
  }

  /**
   * Logs a verifier problem and returns the exception to throw.
   *
   * @param string $message
   *   Message with placeholders.
   * @param array<string, string|int> $context
   *   Placeholder values.
   * @param \Throwable|null $previous
   *   The underlying error, if any.
   */
  protected function error(string $message, array $context = [], ?\Throwable $previous = NULL): VerifierException {
    $this->logger->error($message, $context);
    return new VerifierException(strtr($message, $context), 0, $previous);
  }

}
