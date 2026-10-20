<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Why a verified presentation did not lead to a login.
 */
enum LoginFailure: string {

  // The presentation lacks the expected credential or a usable email claim.
  case InvalidCredential = 'invalid_credential';

  // The credential was not issued by a trusted issuer.
  case UntrustedIssuer = 'untrusted_issuer';

  // No account matches and none may be created.
  case NoAccount = 'no_account';

  // An account has the email, but may not be linked to a wallet by email.
  case LinkNotAllowed = 'link_not_allowed';

  // The account was created but waits for administrator approval.
  case PendingApproval = 'pending_approval';

  // The matching account is blocked.
  case AccountBlocked = 'account_blocked';

  // The matching account has a role that must not use wallet login.
  case AccountExcluded = 'account_excluded';

  // The account's user turned wallet login off.
  case WalletDisabled = 'wallet_disabled';

  /**
   * Returns the message to show the person trying to log in.
   */
  public function userMessage(): TranslatableMarkup {
    return match ($this) {
      self::InvalidCredential => new TranslatableMarkup('The credential from your wallet cannot be used to log in here. It must contain a verified email address.'),
      self::UntrustedIssuer => new TranslatableMarkup('The credential in your wallet was not issued by a provider this site trusts.'),
      self::NoAccount => new TranslatableMarkup('There is no account for this email address, and new accounts cannot be created with a wallet.'),
      self::LinkNotAllowed => new TranslatableMarkup('An account with this email address already exists. Log in with your password instead.'),
      self::PendingApproval => new TranslatableMarkup('Your account has been created and is waiting for approval by an administrator. You will receive an email once it is approved.'),
      self::AccountBlocked => new TranslatableMarkup('This account is blocked or has not been activated yet.'),
      self::AccountExcluded => new TranslatableMarkup('This account cannot log in with a wallet. Log in with your password instead.'),
      self::WalletDisabled => new TranslatableMarkup('Wallet login is turned off for this account. Log in with your password, then turn it on under the Wallet login tab of your account.'),
    };
  }

}
