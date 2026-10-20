<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\didroom_login\Login\AccountResolver;
use Drupal\didroom_login\Verifier\VerifierClient;
use Drupal\externalauth\AuthmapInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows an account's wallet login state and turns wallet login off or on.
 *
 * Turning it off removes the link to the wallet credential and stops the
 * account from being linked again by its email.
 */
final class WalletLoginForm extends FormBase {

  public function __construct(
    protected AccountResolver $accountResolver,
    protected AuthmapInterface $authmap,
    protected VerifierClient $verifier,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('didroom_login.account_resolver'),
      $container->get('externalauth.authmap'),
      $container->get('didroom_login.verifier_client'),
      $container->get('logger.channel.didroom_login'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'didroom_login_wallet_login';
  }

  /**
   * Allows the tab only once wallet login is configured.
   *
   * Access to the account itself is checked by the route.
   */
  public function access(): AccessResultInterface {
    return AccessResult::allowedIf($this->verifier->isConfigured())
      ->addCacheTags(['config:didroom_login.settings']);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\user\UserInterface|null $user
   *   The account from the route.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    $form_state->set('account', $user);
    $disabled = $this->accountResolver->isDisabled($user);
    $authname = $this->authmap->get((int) $user->id(), AccountResolver::PROVIDER);

    if ($disabled) {
      $state = $this->t('Wallet login is turned off for this account. Wallet credentials are not linked to it, even when their email matches.');
    }
    elseif (is_string($authname)) {
      $state = $this->t('Wallet login is on. This account is linked to a wallet credential for %email.', ['%email' => $authname]);
    }
    else {
      $state = $this->t('Wallet login is on. This account is not linked to a wallet yet: the first wallet login with a credential for its email links it.');
    }
    $form['state'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $state,
    ];

    $excluded = (array) $this->config('didroom_login.settings')->get('excluded_roles');
    if (array_intersect($user->getRoles(TRUE), $excluded)) {
      $form['excluded'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('This account has a role that cannot log in with a wallet.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $disabled ? $this->t('Turn on wallet login') : $this->t('Turn off wallet login'),
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\user\UserInterface $account */
    $account = $form_state->get('account');
    $disable = !$this->accountResolver->isDisabled($account);
    $this->accountResolver->setDisabled($account, $disable);

    $context = ['%name' => $account->getAccountName()];
    if ($disable) {
      $this->logger->notice('Wallet login turned off for %name.', $context);
      $this->messenger()->addStatus($this->t('Wallet login is turned off for this account.'));
    }
    else {
      $this->logger->notice('Wallet login turned on for %name.', $context);
      $this->messenger()->addStatus($this->t('Wallet login is turned on for this account.'));
    }
  }

}
