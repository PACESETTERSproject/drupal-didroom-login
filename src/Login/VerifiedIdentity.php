<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

/**
 * The identity proven by a presentation from a trusted issuer.
 */
final class VerifiedIdentity {

  public function __construct(
    // Trimmed, lowercased and validated email address.
    public readonly string $email,
    // The trusted issuer that vouched for the email.
    public readonly string $issuer,
  ) {}

}
