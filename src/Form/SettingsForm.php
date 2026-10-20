<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Form;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the verifier, accepted credential and account rules.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Credential formats the DIDroom verifier can check.
   */
  public const SUPPORTED_FORMATS = ['dc+sd-jwt', 'ldp_vc'];

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'didroom_login_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['didroom_login.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['verifier'] = [
      '#type' => 'details',
      '#title' => $this->t('Verifier'),
      '#open' => TRUE,
    ];
    $form['verifier']['verifier_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Verifier URL'),
      '#description' => $this->t('Base URL of the self-hosted DIDroom verifier microservice, e.g. <code>https://verifier.example.org/verifier/</code>. Wallets must be able to reach it over HTTPS.'),
      '#required' => TRUE,
      '#config_target' => new ConfigTarget(
        'didroom_login.settings',
        'verifier_url',
        toConfig: fn(string $value): string => $value === '' ? '' : rtrim($value, '/') . '/',
      ),
    ];
    $form['verifier']['session_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Login attempt lifetime'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 60,
      '#max' => 1800,
      '#description' => $this->t('How long the QR code stays valid. Keep it short: a QR code shown on another site could be scanned by someone else while it is valid. The DIDroom verifier itself expires requests after 300 seconds.'),
      '#config_target' => new ConfigTarget(
        'didroom_login.settings',
        'session_ttl',
        toConfig: fn(string|int $value): int => (int) $value,
      ),
    ];

    $form['credential'] = [
      '#type' => 'details',
      '#title' => $this->t('Accepted credential'),
      '#open' => TRUE,
    ];
    $form['credential']['dcql_query'] = [
      '#type' => 'textarea',
      '#title' => $this->t('DCQL query'),
      '#rows' => 16,
      '#required' => TRUE,
      '#attributes' => ['class' => ['didroom-login-code'], 'spellcheck' => 'false'],
      '#description' => $this->t('JSON <a href=":dcql">DCQL query</a> describing the credential to request. The first entry in <code>credentials</code> is used for login; its format must be one of: %formats. Trusted issuers below are also pinned into this query when a login starts.', [
        ':dcql' => 'https://openid.net/specs/openid-4-verifiable-presentations-1_0.html#name-digital-credentials-query-l',
        '%formats' => implode(', ', self::SUPPORTED_FORMATS),
      ]),
      '#config_target' => 'didroom_login.settings:dcql_query',
    ];
    $form['credential']['email_claim_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email claim path'),
      '#description' => $this->t('Dot-separated path of the email address in the verified claims, e.g. <code>email</code> or <code>credentialSubject.email</code>.'),
      '#required' => TRUE,
      '#config_target' => 'didroom_login.settings:email_claim_path',
    ];
    $form['credential']['trusted_issuers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Trusted issuers'),
      '#rows' => 4,
      '#required' => TRUE,
      '#description' => $this->t('One issuer per line: the <code>iss</code> (SD-JWT) or <code>issuer</code> (W3C VC) value of credentials you accept, e.g. <code>https://issuer.example.org/credential_issuer</code>. Only issuers that verify email addresses should be listed: anyone holding a credential from these issuers can log in as that email.'),
      '#config_target' => new ConfigTarget(
        'didroom_login.settings',
        'trusted_issuers',
        fromConfig: fn(array $issuers): string => implode("\n", $issuers),
        toConfig: fn(string $value): array => array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])))),
      ),
    ];

    $roles = $this->roleOptions();
    $form['accounts'] = [
      '#type' => 'details',
      '#title' => $this->t('Accounts'),
      '#open' => TRUE,
    ];
    $form['accounts']['auto_create'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create an account when no user has the verified email'),
      '#description' => $this->t('The site-wide <a href=":url">registration setting</a> still applies: nobody is created when only administrators may register, and new accounts wait for approval when it is required.', [
        ':url' => Url::fromRoute('entity.user.admin_form')->toString(),
      ]),
      '#config_target' => 'didroom_login.settings:auto_create',
    ];
    $form['accounts']['default_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles for new accounts'),
      '#options' => $roles,
      '#states' => [
        'visible' => [':input[name="auto_create"]' => ['checked' => TRUE]],
      ],
      '#config_target' => new ConfigTarget(
        'didroom_login.settings',
        'default_roles',
        toConfig: [self::class, 'checkedValues'],
      ),
    ];
    $form['accounts']['excluded_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles that may not use wallet login'),
      '#options' => $roles,
      '#description' => $this->t('Users with any of these roles must log in with their password. Keep administrators here.'),
      '#config_target' => new ConfigTarget(
        'didroom_login.settings',
        'excluded_roles',
        toConfig: [self::class, 'checkedValues'],
      ),
    ];

    $form['accounts']['link_unverified'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link existing accounts even though registration does not verify email addresses'),
      '#description' => $this->t('An existing account with the verified email is linked to the wallet and logged in. When the site does not require email verification on registration, anyone could have registered that address first and would then share the account. Leave this off unless every existing account has a verified email.'),
      '#config_target' => 'didroom_login.settings:link_unverified',
    ];
    $form['accounts']['reset_password_on_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace the password when an existing account is first linked'),
      '#description' => $this->t('Signs out any other sessions and stops a password set by someone else from working. The user can set a new one with "Reset your password".'),
      '#config_target' => 'didroom_login.settings:reset_password_on_link',
    ];
    $form['accounts']['notify_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email users each time they log in with a wallet'),
      '#description' => $this->t('Lets users notice a login they did not make, e.g. after scanning a QR code shown by another site.'),
      '#config_target' => 'didroom_login.settings:notify_login',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = (string) $form_state->getValue('verifier_url');
    if ($url !== '' && !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], TRUE)) {
      $form_state->setErrorByName('verifier_url', $this->t('The verifier URL must start with https:// (or http:// for local development).'));
    }

    $error = $this->validateDcqlQuery((string) $form_state->getValue('dcql_query'));
    if ($error !== NULL) {
      $form_state->setErrorByName('dcql_query', $error);
    }

    if (!preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/', (string) $form_state->getValue('email_claim_path'))) {
      $form_state->setErrorByName('email_claim_path', $this->t('Use claim names separated by dots, e.g. <code>credentialSubject.email</code>.'));
    }

    if ($form_state->getValue('auto_create')) {
      $conflict = array_intersect(
        self::checkedValues($form_state->getValue('default_roles') ?? []),
        self::checkedValues($form_state->getValue('excluded_roles') ?? []),
      );
      if ($conflict) {
        $form_state->setErrorByName('default_roles', $this->t('New accounts cannot get a role that is excluded from wallet login: %roles.', [
          '%roles' => implode(', ', $conflict),
        ]));
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * Checks that a DCQL query is usable for login.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   An error message, or NULL when the query is valid.
   */
  private function validateDcqlQuery(string $json): ?\Stringable {
    try {
      $query = json_decode($json, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return $this->t('The DCQL query is not valid JSON: @message', ['@message' => $e->getMessage()]);
    }
    $credentials = is_array($query) ? ($query['credentials'] ?? NULL) : NULL;
    $credential = is_array($credentials) && array_is_list($credentials) ? ($credentials[0] ?? NULL) : NULL;
    if (!is_array($credential)) {
      return $this->t('The DCQL query needs a non-empty <code>credentials</code> list.');
    }
    if (!is_string($credential['id'] ?? NULL) || !preg_match('/^[A-Za-z0-9_\-]+$/', $credential['id'])) {
      return $this->t('The first credential needs an <code>id</code> made of letters, digits, <code>_</code> or <code>-</code>.');
    }
    if (!in_array($credential['format'] ?? NULL, self::SUPPORTED_FORMATS, TRUE)) {
      return $this->t('The first credential format must be one of: %formats.', ['%formats' => implode(', ', self::SUPPORTED_FORMATS)]);
    }
    return NULL;
  }

  /**
   * Converts a checkboxes value into a list of the checked keys.
   *
   * @return string[]
   *   The checked option keys.
   */
  public static function checkedValues(array $value): array {
    return array_map('strval', array_keys(array_filter($value)));
  }

  /**
   * Builds role options, leaving out the anonymous and authenticated roles.
   *
   * @return array<string, string>
   *   Role labels keyed by role ID.
   */
  private function roleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      if (!in_array($role->id(), [RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID], TRUE)) {
        $options[$role->id()] = $role->label();
      }
    }
    return $options;
  }

}
