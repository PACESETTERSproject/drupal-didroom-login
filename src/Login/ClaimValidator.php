<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Element\Email;
use Drupal\didroom_login\Verifier\VerifierClient;

/**
 * Checks the claims the verifier accepted before they are trusted.
 *
 * The verifier checks signatures, holder binding and expiry. This class
 * checks what it does not: that the credential is the one configured, that a
 * trusted issuer signed it (the DCQL pin is defence in depth, not the only
 * check), and that it carries a usable email address.
 */
class ClaimValidator {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EmailValidatorInterface $emailValidator,
  ) {}

  /**
   * Extracts the verified identity from the verifier's claims.
   *
   * @param array<string, mixed> $claims
   *   Claims keyed by DCQL credential id, as in VerificationResult::$claims.
   *
   * @throws \Drupal\didroom_login\Login\LoginException
   *   When the claims must not be used to log in.
   */
  public function validate(array $claims): VerifiedIdentity {
    $config = $this->configFactory->get('didroom_login.settings');
    $query = json_decode((string) $config->get('dcql_query'), TRUE);
    $credential = is_array($query) ? ($query['credentials'][0] ?? NULL) : NULL;
    if (!is_array($credential) || !is_string($credential['id'] ?? NULL)) {
      throw new LoginException(LoginFailure::InvalidCredential, 'The DCQL query is not configured.');
    }

    // One credential is requested, so exactly one claim set must come back.
    $sets = $claims[$credential['id']] ?? NULL;
    if (!is_array($sets) || count($sets) !== 1 || !is_array(reset($sets))) {
      throw new LoginException(LoginFailure::InvalidCredential, sprintf('Expected one "%s" credential in the presentation.', $credential['id']));
    }
    $set = reset($sets);

    $issuer = $set[VerifierClient::issuerClaim($credential['format'] ?? NULL)] ?? NULL;
    // W3C credentials may give the issuer as an object with an id.
    if (is_array($issuer)) {
      $issuer = $issuer['id'] ?? NULL;
    }
    $trusted = (array) $config->get('trusted_issuers');
    if (!is_string($issuer) || !in_array($issuer, $trusted, TRUE)) {
      throw new LoginException(LoginFailure::UntrustedIssuer, 'The credential was not issued by a trusted issuer.');
    }

    $email = $this->claimAt($set, (string) $config->get('email_claim_path'));
    $email = is_string($email) ? mb_strtolower(trim($email)) : '';
    if ($email === '' || mb_strlen($email) > Email::EMAIL_MAX_LENGTH || !$this->emailValidator->isValid($email)) {
      throw new LoginException(LoginFailure::InvalidCredential, 'The credential has no valid email address.');
    }

    return new VerifiedIdentity($email, $issuer);
  }

  /**
   * Returns the claim at a dot-separated path, or NULL.
   *
   * @param array<string, mixed> $set
   *   A claim set.
   * @param string $path
   *   The path, e.g. "email" or "credentialSubject.email".
   */
  protected function claimAt(array $set, string $path): mixed {
    $value = $set;
    foreach (explode('.', $path) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
        return NULL;
      }
      $value = $value[$key];
    }
    return $value;
  }

}
