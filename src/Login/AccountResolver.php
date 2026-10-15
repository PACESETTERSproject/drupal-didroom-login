<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Login;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds, links or creates the Drupal account for a verified identity.
 *
 * The verified email is the external auth name. An account already linked to
 * it wins; otherwise an account with that email is linked, or a new one is
 * registered when the module and the site's registration policy allow it.
 */
class AccountResolver {

  /**
   * The externalauth provider name.
   */
  public const PROVIDER = 'didroom_login';

  public function __construct(
    protected ExternalAuthInterface $externalAuth,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the account that may log in with the identity.
   *
   * @throws \Drupal\didroom_login\Login\LoginException
   *   When no account may log in.
   * @throws \Drupal\externalauth\Exception\ExternalAuthRegisterException
   *   When registering a new account fails.
   */
  public function resolve(VerifiedIdentity $identity): UserInterface {
    $account = $this->externalAuth->load($identity->email, self::PROVIDER);
    if ($account instanceof UserInterface) {
      $this->assertMayLogIn($account);
      return $account;
    }

    $matches = $this->userStorage()->loadByProperties(['mail' => $identity->email]);
    if (count($matches) > 1) {
      throw new LoginException(LoginFailure::NoAccount, 'Several accounts share the verified email address.');
    }
    $account = reset($matches);
    if ($account instanceof UserInterface) {
      // Check before linking, so excluded accounts never get a wallet link.
      $this->assertMayLogIn($account);
      $this->externalAuth->linkExistingAccount($identity->email, self::PROVIDER, $account);
      $this->logger->notice('Linked the wallet credential to the existing account %name.', ['%name' => $account->getAccountName()]);
      return $account;
    }

    return $this->register($identity);
  }

  /**
   * Registers a new account for the identity, if allowed.
   *
   * @throws \Drupal\didroom_login\Login\LoginException
   *   When registration is not allowed, or the account needs approval.
   */
  protected function register(VerifiedIdentity $identity): UserInterface {
    $settings = $this->configFactory->get('didroom_login.settings');
    // The site's registration policy applies to wallet sign-ups too.
    $register = $this->configFactory->get('user.settings')->get('register');
    if (!$settings->get('auto_create') || $register === UserInterface::REGISTER_ADMINISTRATORS_ONLY) {
      throw new LoginException(LoginFailure::NoAccount, 'No account matches the verified email address and registration is closed.');
    }

    $roles = array_diff((array) $settings->get('default_roles'), (array) $settings->get('excluded_roles'));
    $roles = array_values(array_intersect($roles, array_keys($this->entityTypeManager->getStorage('user_role')->loadMultiple())));
    $approved = $register === UserInterface::REGISTER_VISITORS;

    $account = $this->externalAuth->register($identity->email, self::PROVIDER, [
      'name' => $this->uniqueUsername($identity->email),
      'mail' => $identity->email,
      'init' => $identity->email,
      'status' => (int) $approved,
      'roles' => $roles,
    ]);
    if (!$approved) {
      throw new LoginException(LoginFailure::PendingApproval, sprintf('Created the account %s, which waits for administrator approval.', $account->getAccountName()));
    }
    return $account;
  }

  /**
   * Throws unless the account may log in with a wallet.
   *
   * @throws \Drupal\didroom_login\Login\LoginException
   */
  protected function assertMayLogIn(UserInterface $account): void {
    if ($account->isBlocked()) {
      throw new LoginException(LoginFailure::AccountBlocked, sprintf('The account %s is blocked.', $account->getAccountName()));
    }
    $excluded = (array) $this->configFactory->get('didroom_login.settings')->get('excluded_roles');
    if (array_intersect($account->getRoles(TRUE), $excluded)) {
      throw new LoginException(LoginFailure::AccountExcluded, sprintf('The account %s has a role excluded from wallet login.', $account->getAccountName()));
    }
  }

  /**
   * Derives an unused username from the local part of an email address.
   *
   * The full address is not used, as usernames are shown publicly.
   */
  protected function uniqueUsername(string $email): string {
    $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', strstr($email, '@', TRUE) ?: $email);
    $base = trim((string) $base, '._-');
    if ($base === '') {
      $base = 'user';
    }
    // Leave room for a numeric suffix.
    $base = mb_substr($base, 0, UserInterface::USERNAME_MAX_LENGTH - 6);

    $name = $base;
    for ($i = 2; $this->usernameExists($name); $i++) {
      $name = $base . '_' . $i;
    }
    return $name;
  }

  /**
   * Whether an account already uses the username.
   */
  protected function usernameExists(string $name): bool {
    return (bool) $this->userStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('name', $name)
      ->count()
      ->execute();
  }

  /**
   * Returns the user storage.
   */
  protected function userStorage(): UserStorageInterface {
    return $this->entityTypeManager->getStorage('user');
  }

}
