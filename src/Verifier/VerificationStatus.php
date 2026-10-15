<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Verifier;

/**
 * State of a verification session on the verifier.
 */
enum VerificationStatus: string {

  // The wallet has not presented a credential yet.
  case Pending = 'pending';

  // The presentation passed the verifier's checks.
  case Verified = 'verified';

  // The verifier rejected the presentation.
  case Failed = 'failed';

}
