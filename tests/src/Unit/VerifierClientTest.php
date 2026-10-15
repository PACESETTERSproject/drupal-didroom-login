<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Unit;

use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\didroom_login\Verifier\VerificationStatus;
use Drupal\didroom_login\Verifier\VerifierClient;
use Drupal\didroom_login\Verifier\VerifierException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Tests the DIDroom verifier client against a mocked HTTP layer.
 *
 * @group didroom_login
 */
#[CoversClass(VerifierClient::class)]
#[Group('didroom_login')]
final class VerifierClientTest extends UnitTestCase {

  private const BASE_URL = 'https://verifier.example.org/verifier/';

  private const ISSUER = 'https://issuer.example.org/credential_issuer';

  private const TRANSACTION_ID = 'transaction/0a1b2c3d4e5f';

  /**
   * Requests sent through the mocked client.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}>
   */
  private array $history = [];

  /**
   * Builds a client whose HTTP layer answers with the given responses.
   *
   * @param array<int, \Psr\Http\Message\ResponseInterface|\Throwable> $responses
   *   Queued responses or exceptions.
   * @param array<string, mixed> $config
   *   Overrides for didroom_login.settings.
   * @param string $token
   *   The verifier token in settings.php.
   */
  private function client(array $responses, array $config = [], string $token = 's3cret'): VerifierClient {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $config += [
      'verifier_url' => self::BASE_URL,
      'trusted_issuers' => [self::ISSUER],
      'dcql_query' => json_encode([
        'credentials' => [
          [
            'id' => 'email_credential',
            'format' => 'dc+sd-jwt',
            'claims' => [
              ['path' => ['email']],
              ['path' => ['iss'], 'values' => ['https://attacker.example']],
            ],
            'claim_sets' => [['email']],
          ],
        ],
      ]),
    ];
    return new VerifierClient(
      new Client(['handler' => $stack]),
      $this->getConfigFactoryStub(['didroom_login.settings' => $config]),
      new Settings([VerifierClient::TOKEN_SETTING => $token]),
      new NullLogger(),
    );
  }

  /**
   * Returns the only request that was sent.
   */
  private function sentRequest(): RequestInterface {
    $this->assertCount(1, $this->history);
    return $this->history[0]['request'];
  }

  /**
   * A valid session response from the verifier.
   *
   * @param array<string, mixed> $overrides
   *   Fields to replace.
   */
  private static function sessionResponse(array $overrides = []): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($overrides + [
      'transaction_id' => self::TRANSACTION_ID,
      'qr_code' => 'data:image/png;base64,iVBORw0KGgo=',
      'intent_url' => 'openid4vp://?client_id=decentralized_identifier%3Adid%3Adyne%3Aexample&request_uri_method=get&request_uri=https%3A%2F%2Fverifier.example.org%2Fverifier%2Frequest%2Fabc',
      'params_json' => ['client_id' => 'decentralized_identifier:did:dyne:example'],
    ]));
  }

  /**
   * Opening a session sends the expected request and parses the answer.
   */
  public function testCreateSession(): void {
    $request = $this->client([self::sessionResponse()])->createSession();

    $this->assertSame(self::TRANSACTION_ID, $request->transactionId);
    $this->assertStringStartsWith('data:image/png', $request->qrCode);
    $this->assertStringStartsWith('openid4vp://', $request->intentUrl);

    $sent = $this->sentRequest();
    $this->assertSame('POST', $sent->getMethod());
    $this->assertSame(self::BASE_URL . 'generate_authorization_request', (string) $sent->getUri());
    $this->assertSame('Bearer s3cret', $sent->getHeaderLine('Authorization'));
    $this->assertSame(10, $this->history[0]['options']['timeout']);

    $body = json_decode((string) $sent->getBody(), TRUE);
    $this->assertSame('direct_post', $body['response_mode']);
    $this->assertSame('vp_token', $body['response_type']);
    $this->assertSame(self::BASE_URL, $body['url']);
    // The configured issuer claim is replaced by the trusted issuers, and
    // claim_sets is dropped so the issuer claim cannot be skipped.
    $credential = $body['dcql_query']['credentials'][0];
    $this->assertSame([
      ['path' => ['email']],
      ['path' => ['iss'], 'values' => [self::ISSUER]],
    ], $credential['claims']);
    $this->assertArrayNotHasKey('claim_sets', $credential);
  }

  /**
   * W3C credentials are pinned on the issuer claim.
   */
  public function testW3cCredentialsPinIssuer(): void {
    $query = $this->client([], [
      'trusted_issuers' => [self::ISSUER, 'did:dyne:sandbox.issuer:abc'],
      'dcql_query' => '{"credentials":[{"id":"vc","format":"ldp_vc"}]}',
    ])->buildDcqlQuery();

    $this->assertSame([
      ['path' => ['issuer'], 'values' => [self::ISSUER, 'did:dyne:sandbox.issuer:abc']],
    ], $query['credentials'][0]['claims']);
  }

  /**
   * Without a token, no Authorization header is sent.
   */
  public function testNoTokenSendsNoAuthorization(): void {
    $this->client([self::sessionResponse()], token: '')->createSession();
    $this->assertFalse($this->sentRequest()->hasHeader('Authorization'));
  }

  /**
   * Missing configuration fails before anything is sent.
   *
   * @param array<string, mixed> $config
   *   Overrides for didroom_login.settings.
   * @param string $message
   *   Part of the expected exception message.
   *
   * @dataProvider providerMissingConfig
   */
  #[DataProvider('providerMissingConfig')]
  public function testMissingConfig(array $config, string $message): void {
    $client = $this->client([], $config);
    try {
      $client->createSession();
      $this->fail('Expected a VerifierException.');
    }
    catch (VerifierException $e) {
      $this->assertStringContainsString($message, $e->getMessage());
    }
    $this->assertSame([], $this->history);
  }

  /**
   * Data provider for testMissingConfig().
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   Config overrides and the expected message.
   */
  public static function providerMissingConfig(): array {
    return [
      'no verifier URL' => [['verifier_url' => ''], 'verifier URL is not configured'],
      'no trusted issuers' => [['trusted_issuers' => []], 'No trusted issuers'],
      'no DCQL query' => [['dcql_query' => ''], 'DCQL query is not configured'],
    ];
  }

  /**
   * Unusable verifier answers are rejected.
   *
   * @dataProvider providerBadSessionResponse
   */
  #[DataProvider('providerBadSessionResponse')]
  public function testBadSessionResponse(Response|\Throwable $response, string $message): void {
    $this->expectException(VerifierException::class);
    $this->expectExceptionMessage($message);
    $this->client([$response])->createSession();
  }

  /**
   * Data provider for testBadSessionResponse().
   *
   * @return array<string, array{\GuzzleHttp\Psr7\Response|\Throwable, string}>
   *   The verifier answer and the expected message.
   */
  public static function providerBadSessionResponse(): array {
    $unexpected = 'unexpected session response';
    return [
      'server error' => [new Response(500), 'HTTP 500'],
      'unauthorized' => [new Response(401), 'HTTP 401'],
      'not JSON' => [new Response(200, [], 'oops'), $unexpected],
      'missing transaction id' => [
        self::sessionResponse(['transaction_id' => NULL]),
        $unexpected,
      ],
      'transaction id with path traversal' => [
        self::sessionResponse(['transaction_id' => 'transaction/../request/x']),
        $unexpected,
      ],
      'QR code is not an image' => [
        self::sessionResponse(['qr_code' => 'javascript:alert(1)']),
        $unexpected,
      ],
      'intent URL is not openid4vp' => [
        self::sessionResponse(['intent_url' => 'https://evil.example']),
        $unexpected,
      ],
      'network error' => [
        new ConnectException('Connection refused', new Request('POST', self::BASE_URL)),
        'could not be reached: Connection refused',
      ],
    ];
  }

  /**
   * A 404 from the verifier means the wallet has not answered yet.
   */
  public function testFetchPending(): void {
    $result = $this->client([new Response(404, [], 'File not found')])->fetchResult(self::TRANSACTION_ID);
    $this->assertSame(VerificationStatus::Pending, $result->status);

    $sent = $this->sentRequest();
    $this->assertSame('GET', $sent->getMethod());
    $this->assertSame(self::BASE_URL . self::TRANSACTION_ID, (string) $sent->getUri());
    $this->assertSame('Bearer s3cret', $sent->getHeaderLine('Authorization'));
  }

  /**
   * A JSON object result carries the verified claims.
   */
  public function testFetchVerified(): void {
    $claims = ['email_credential' => [['iss' => self::ISSUER, 'email' => 'ada@example.org']]];
    $result = $this->client([new Response(200, ['Content-Type' => 'application/json'], json_encode($claims))])
      ->fetchResult(self::TRANSACTION_ID);

    $this->assertSame(VerificationStatus::Verified, $result->status);
    $this->assertSame($claims, $result->claims);
    $this->assertNull($result->error);
  }

  /**
   * The verifier reports failures as a string instead of a claims object.
   *
   * @dataProvider providerFetchFailed
   */
  #[DataProvider('providerFetchFailed')]
  public function testFetchFailed(string $body, string $reason): void {
    $result = $this->client([new Response(200, [], $body)])->fetchResult(self::TRANSACTION_ID);
    $this->assertSame(VerificationStatus::Failed, $result->status);
    $this->assertSame([], $result->claims);
    $this->assertSame($reason, $result->error);
  }

  /**
   * Data provider for testFetchFailed().
   *
   * @return array<string, array{string, string}>
   *   The result body and the expected reason.
   */
  public static function providerFetchFailed(): array {
    return [
      'plain text' => ["Cryptographic verification failed\n", 'Cryptographic verification failed'],
      'JSON string' => ['"Custom code verification email failed"', 'Custom code verification email failed'],
      'empty' => ['', 'Empty result'],
      'empty object' => ['{}', '{}'],
      'list instead of object' => ['[1,2]', '[1,2]'],
      'very long' => [str_repeat('x', 1000), str_repeat('x', 255)],
    ];
  }

  /**
   * Verifier errors and unreachable verifiers raise an exception.
   *
   * @dataProvider providerFetchError
   */
  #[DataProvider('providerFetchError')]
  public function testFetchError(Response|\Throwable $response, string $message): void {
    $this->expectException(VerifierException::class);
    $this->expectExceptionMessage($message);
    $this->client([$response])->fetchResult(self::TRANSACTION_ID);
  }

  /**
   * Data provider for testFetchError().
   *
   * @return array<string, array{\GuzzleHttp\Psr7\Response|\Throwable, string}>
   *   The verifier answer and the expected message.
   */
  public static function providerFetchError(): array {
    return [
      'server error' => [new Response(502), 'HTTP 502'],
      'forbidden' => [new Response(403), 'HTTP 403'],
      'redirect is not followed' => [
        new Response(302, ['Location' => 'https://elsewhere.example']),
        'HTTP 302',
      ],
      'timeout' => [
        new ConnectException('Operation timed out', new Request('GET', self::BASE_URL)),
        'Operation timed out',
      ],
    ];
  }

  /**
   * Transaction ids that could reach other verifier paths are refused.
   */
  public function testFetchMalformedId(): void {
    $client = $this->client([]);
    $ids = [
      '',
      'request/abc',
      'transaction/../request/abc',
      'https://evil.example/transaction/1',
      'transaction/abc?x=1',
    ];
    foreach ($ids as $id) {
      try {
        $client->fetchResult($id);
        $this->fail("Accepted malformed id '$id'.");
      }
      catch (VerifierException $e) {
        $this->assertSame('Malformed transaction id.', $e->getMessage());
      }
    }
    $this->assertSame([], $this->history);
  }

}
