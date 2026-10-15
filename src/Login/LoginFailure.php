<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

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

  // The account was created but waits for administrator approval.
  case PendingApproval = 'pending_approval';

  // The matching account is blocked.
  case AccountBlocked = 'account_blocked';

  // The matching account has a role that must not use wallet login.
  case AccountExcluded = 'account_excluded';

}
