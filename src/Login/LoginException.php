<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

/**
 * Thrown when a verified presentation must not log anyone in.
 *
 * The message is meant for logs and never contains claim values; show users
 * a text chosen from the reason instead.
 */
final class LoginException extends \RuntimeException {

  public function __construct(
    public readonly LoginFailure $reason,
    string $message,
  ) {
    parent::__construct($message);
  }

}
