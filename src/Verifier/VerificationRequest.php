<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Verifier;

/**
 * A verification session opened on the verifier.
 */
final class VerificationRequest {

  public function __construct(
    // Verifier path of the result, e.g. "transaction/<hex>". Keep it
    // server-side: anyone who knows it can read the verified claims.
    public readonly string $transactionId,
    // QR code image as a data: URL, for wallets on another device.
    public readonly string $qrCode,
    // openid4vp:// link, for a wallet on the same device.
    public readonly string $intentUrl,
  ) {}

}
