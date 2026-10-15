<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Verifier;

/**
 * Thrown when the verifier cannot be used or answers unexpectedly.
 *
 * Messages are meant for logs and administrators, not for end users.
 */
final class VerifierException extends \RuntimeException {}
