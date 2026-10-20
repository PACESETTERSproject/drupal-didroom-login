<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

/**
 * How the account for a verified identity was found.
 */
enum AccountOutcome {

  // The account was already linked to the wallet's email.
  case Known;

  // An existing account with the email was linked now.
  case Linked;

  // An existing account was linked now and its password replaced.
  case LinkedPasswordReset;

  // A new account was registered.
  case Created;

}
