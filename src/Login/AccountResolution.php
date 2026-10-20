<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

use Drupal\user\UserInterface;

/**
 * The account that may log in, and how it was found.
 */
final class AccountResolution {

  public function __construct(
    public readonly UserInterface $account,
    public readonly AccountOutcome $outcome,
  ) {}

}
