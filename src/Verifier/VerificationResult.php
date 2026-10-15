<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Verifier;

/**
 * Outcome of polling a verification session.
 */
final class VerificationResult {

  /**
   * Use the named constructors: pending(), verified() and failed().
   *
   * @param \Drupal\didroom_login\Verifier\VerificationStatus $status
   *   The session state.
   * @param array<string, mixed> $claims
   *   When verified: disclosed claims keyed by DCQL credential id, each a list
   *   of claim sets, e.g. ['email_credential' => [['iss' => ..., 'email' =>
   *   ...]]]. Not yet checked against trusted issuers.
   * @param string|null $error
   *   When failed: the verifier's reason, for logs only.
   */
  private function __construct(
    public readonly VerificationStatus $status,
    public readonly array $claims = [],
    public readonly ?string $error = NULL,
  ) {}

  /**
   * The wallet has not answered yet.
   */
  public static function pending(): self {
    return new self(VerificationStatus::Pending);
  }

  /**
   * The verifier accepted the presentation.
   *
   * @param array<string, mixed> $claims
   *   Disclosed claims keyed by DCQL credential id.
   */
  public static function verified(array $claims): self {
    return new self(VerificationStatus::Verified, $claims);
  }

  /**
   * The verifier rejected the presentation.
   */
  public static function failed(string $error): self {
    return new self(VerificationStatus::Failed, error: $error);
  }

}
