<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Unit;

use Drupal\Component\Utility\EmailValidator;
use Drupal\Tests\UnitTestCase;
use Drupal\didroom_login\Login\ClaimValidator;
use Drupal\didroom_login\Login\LoginException;
use Drupal\didroom_login\Login\LoginFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the checks on claims accepted by the verifier.
 *
 * @group didroom_login
 */
#[CoversClass(ClaimValidator::class)]
#[Group('didroom_login')]
final class ClaimValidatorTest extends UnitTestCase {

  private const ISSUER = 'https://issuer.example.org/credential_issuer';

  /**
   * Builds a validator for a single-credential DCQL query.
   *
   * @param string $format
   *   The DCQL credential format.
   * @param array<string, mixed> $config
   *   Overrides for didroom_login.settings.
   */
  private function validator(string $format = 'dc+sd-jwt', array $config = []): ClaimValidator {
    $config += [
      'dcql_query' => json_encode([
        'credentials' => [['id' => 'email_credential', 'format' => $format]],
      ]),
      'email_claim_path' => 'email',
      'trusted_issuers' => ['https://other.example.org', self::ISSUER],
    ];
    return new ClaimValidator(
      $this->getConfigFactoryStub(['didroom_login.settings' => $config]),
      new EmailValidator(),
    );
  }

  /**
   * An SD-JWT presentation yields the normalized email and the issuer.
   */
  public function testSdJwt(): void {
    $identity = $this->validator()->validate([
      'email_credential' => [['iss' => self::ISSUER, 'email' => '  Alice@Example.ORG ']],
    ]);
    $this->assertSame('alice@example.org', $identity->email);
    $this->assertSame(self::ISSUER, $identity->issuer);
  }

  /**
   * A W3C presentation is read from "issuer", as a string or an object.
   */
  public function testLdpVc(): void {
    $validator = $this->validator('ldp_vc', ['email_claim_path' => 'credentialSubject.email']);
    foreach ([self::ISSUER, ['id' => self::ISSUER, 'name' => 'Issuer']] as $issuer) {
      $identity = $validator->validate([
        'email_credential' => [['issuer' => $issuer, 'credentialSubject' => ['email' => 'bob@example.org']]],
      ]);
      $this->assertSame('bob@example.org', $identity->email);
      $this->assertSame(self::ISSUER, $identity->issuer);
    }
  }

  /**
   * Claims that must not log anyone in.
   *
   * @param array<string, mixed> $claims
   *   Claims as returned by the verifier.
   * @param \Drupal\didroom_login\Login\LoginFailure $reason
   *   The expected failure.
   *
   * @dataProvider providerRejected
   */
  #[DataProvider('providerRejected')]
  public function testRejected(array $claims, LoginFailure $reason): void {
    try {
      $this->validator()->validate($claims);
      $this->fail('The claims were accepted.');
    }
    catch (LoginException $e) {
      $this->assertSame($reason, $e->reason);
      $this->assertStringNotContainsString('@', $e->getMessage(), 'Messages hold no claim values.');
    }
  }

  /**
   * Data provider for testRejected().
   *
   * @return array<string, array{array<string, mixed>, \Drupal\didroom_login\Login\LoginFailure}>
   *   Claims and the expected failure.
   */
  public static function providerRejected(): array {
    $good = ['iss' => self::ISSUER, 'email' => 'alice@example.org'];
    // Wraps claim sets as the verifier returns them.
    $sets = fn(array ...$sets) => ['email_credential' => $sets];
    $long = str_repeat('a', 64) . '@' . str_repeat('b', 190) . '.org';
    $invalid = LoginFailure::InvalidCredential;
    $untrusted = LoginFailure::UntrustedIssuer;
    return [
      'other credential id' => [['other' => [$good]], $invalid],
      'no claim sets' => [$sets(), $invalid],
      'two claim sets' => [$sets($good, $good), $invalid],
      'claim set not an object' => [['email_credential' => ['alice@example.org']], $invalid],
      'no issuer' => [$sets(['email' => 'alice@example.org']), $untrusted],
      'untrusted issuer' => [$sets(['iss' => 'https://attacker.example'] + $good), $untrusted],
      'issuer prefix' => [$sets(['iss' => self::ISSUER . '/evil'] + $good), $untrusted],
      'issuer not a string' => [$sets(['iss' => [self::ISSUER]] + $good), $untrusted],
      'no email' => [$sets(['iss' => self::ISSUER]), $invalid],
      'invalid email' => [$sets(['email' => 'not an email'] + $good), $invalid],
      'email not a string' => [$sets(['email' => ['alice@example.org']] + $good), $invalid],
      'email too long' => [$sets(['email' => $long] + $good), $invalid],
    ];
  }

  /**
   * Without a configured query nothing is accepted.
   */
  public function testMissingQuery(): void {
    $this->expectException(LoginException::class);
    $this->validator(config: ['dcql_query' => ''])->validate([
      'email_credential' => [['iss' => self::ISSUER, 'email' => 'alice@example.org']],
    ]);
  }

}
