<?php

declare(strict_types=1);

namespace Drupal\didroom_login;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\didroom_login\Verifier\VerifierClient;

/**
 * Builds the module's lines on the status report.
 *
 * Severities are the integers shared by the legacy REQUIREMENT_* constants
 * and the RequirementSeverity enum, so both requirements hooks can use them.
 */
class StatusReport {

  use StringTranslationTrait;

  public const INFO = -1;

  public const OK = 0;

  public const WARNING = 1;

  public const ERROR = 2;

  public function __construct(
    protected VerifierClient $verifier,
    protected ConfigFactoryInterface $configFactory,
    protected Settings $settings,
  ) {}

  /**
   * Returns the requirements, with integer severities.
   *
   * @return array<string, array<string, mixed>>
   *   Requirements as hook_requirements() returns them.
   */
  public function requirements(): array {
    $title = $this->t('Wallet login');
    $settings_url = Url::fromRoute('didroom_login.settings')->toString();

    if (!$this->verifier->isConfigured()) {
      return [
        'didroom_login' => [
          'title' => $title,
          'value' => $this->t('Not configured'),
          'description' => $this->t('Set the verifier URL, DCQL query and trusted issuers on the <a href=":url">wallet login settings</a>. Until then the wallet login link is hidden.', [':url' => $settings_url]),
          'severity' => self::ERROR,
        ],
      ];
    }

    $requirements['didroom_login'] = $this->verifier->isReachable()
      ? [
        'title' => $title,
        'value' => $this->t('Verifier reachable'),
        'severity' => self::OK,
      ]
      : [
        'title' => $title,
        'value' => $this->t('Verifier unreachable'),
        'description' => $this->t('The verifier did not answer as expected, so wallet logins fail. Check the verifier URL, the token, and the logs of the <em>didroom_login</em> channel.'),
        'severity' => self::ERROR,
      ];

    if ((string) $this->settings->get(VerifierClient::TOKEN_SETTING, '') === '') {
      $requirements['didroom_login_token'] = [
        'title' => $this->t('Wallet login verifier token'),
        'value' => $this->t('Not set'),
        'description' => $this->t('Set <code>@setting</code> in settings.php, and make the proxy in front of the verifier require it, so that only this site can open verification sessions with the identity of the verifier.', ['@setting' => "\$settings['" . VerifierClient::TOKEN_SETTING . "']"]),
        'severity' => self::WARNING,
      ];
    }

    $verify_mail = (bool) $this->configFactory->get('user.settings')->get('verify_mail');
    $link_unverified = (bool) $this->configFactory->get('didroom_login.settings')->get('link_unverified');
    if (!$verify_mail) {
      $requirements['didroom_login_linking'] = $link_unverified
        ? [
          'title' => $this->t('Wallet login account linking'),
          'value' => $this->t('Unverified emails are linked'),
          'description' => $this->t("Registration does not verify email addresses, yet wallet logins are linked to existing accounts by email. Someone who registered a victim's address first would share the victim's account. Turn on email verification or turn off linking on the <a href=\":url\">wallet login settings</a>.", [':url' => $settings_url]),
          'severity' => self::WARNING,
        ]
        : [
          'title' => $this->t('Wallet login account linking'),
          'value' => $this->t('Existing accounts are not linked'),
          'description' => $this->t('Registration does not verify email addresses, so wallet logins are not linked to existing accounts by email. Users of those accounts must log in with their password.'),
          'severity' => self::INFO,
        ];
    }

    return $requirements;
  }

}
