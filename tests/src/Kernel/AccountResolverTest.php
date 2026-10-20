<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\didroom_login\Login\AccountOutcome;
use Drupal\didroom_login\Login\AccountResolution;
use Drupal\didroom_login\Login\AccountResolver;
use Drupal\didroom_login\Login\LoginException;
use Drupal\didroom_login\Login\LoginFailure;
use Drupal\didroom_login\Login\VerifiedIdentity;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests finding, linking and creating accounts for verified identities.
 *
 * @group didroom_login
 */
#[CoversClass(AccountResolver::class)]
#[Group('didroom_login')]
final class AccountResolverTest extends KernelTestBase {

  use AssertMailTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['didroom_login', 'externalauth', 'system', 'user'];

  /**
   * The service under test.
   */
  private AccountResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('externalauth', ['authmap']);
    $this->installConfig(['didroom_login', 'system', 'user']);
    Role::create(['id' => 'administrator', 'label' => 'Administrator'])->save();
    Role::create(['id' => 'member', 'label' => 'Member'])->save();
    // Take uid 1, so test accounts are ordinary users.
    $this->createUser([], 'root');
    $this->config('user.settings')->set('register', UserInterface::REGISTER_VISITORS)->save();
    $this->resolver = $this->container->get('didroom_login.account_resolver');
  }

  /**
   * Resolves an identity for the email.
   */
  private function resolution(string $email): AccountResolution {
    return $this->resolver->resolve(new VerifiedIdentity($email, 'https://issuer.example.org'));
  }

  /**
   * Resolves an identity for the email and returns the account.
   */
  private function resolve(string $email): UserInterface {
    return $this->resolution($email)->account;
  }

  /**
   * Reloads an account from storage.
   */
  private function reload(UserInterface $account): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$account->id()]);
    return $storage->load($account->id());
  }

  /**
   * Asserts that resolving the email fails for the given reason.
   */
  private function assertRefused(string $email, LoginFailure $reason): void {
    try {
      $this->resolve($email);
      $this->fail('An account was returned.');
    }
    catch (LoginException $e) {
      $this->assertSame($reason, $e->reason);
    }
  }

  /**
   * Returns the uid linked to the email, if any.
   */
  private function linkedUid(string $email): int|false {
    $uid = $this->container->get('externalauth.authmap')->getUid($email, AccountResolver::PROVIDER);
    return $uid === FALSE ? FALSE : (int) $uid;
  }

  /**
   * An existing account is matched by email, linked, then found by the link.
   */
  public function testLinkExisting(): void {
    $existing = $this->createUser([], 'alice', FALSE, ['mail' => 'alice@example.org']);
    $hash = $existing->getPassword();
    $resolution = $this->resolution('alice@example.org');
    $this->assertSame($existing->id(), $resolution->account->id());
    $this->assertSame(AccountOutcome::LinkedPasswordReset, $resolution->outcome);
    $this->assertSame((int) $existing->id(), $this->linkedUid('alice@example.org'));
    $this->assertNotSame($hash, $this->reload($existing)->getPassword(), 'The password was replaced.');

    // Once linked, the account is known and its password stays.
    $hash = $this->reload($existing)->getPassword();
    $this->assertSame(AccountOutcome::Known, $this->resolution('alice@example.org')->outcome);
    $this->assertSame($hash, $this->reload($existing)->getPassword());
    $existing = $this->reload($existing);

    // Email matching ignores case.
    $bob = $this->createUser([], 'bob', FALSE, ['mail' => 'Bob@Example.org']);
    $this->assertSame($bob->id(), $this->resolve('bob@example.org')->id());

    // The link wins even after the account's email changes.
    $existing->setEmail('alice@new.example.org')->save();
    $this->assertSame($existing->id(), $this->resolve('alice@example.org')->id());
  }

  /**
   * The password can be kept on linking.
   */
  public function testLinkKeepsPassword(): void {
    $this->config('didroom_login.settings')->set('reset_password_on_link', FALSE)->save();
    $existing = $this->createUser([], 'ivan', FALSE, ['mail' => 'ivan@example.org']);
    $this->assertSame(AccountOutcome::Linked, $this->resolution('ivan@example.org')->outcome);
    $this->assertSame($existing->getPassword(), $this->reload($existing)->getPassword());
  }

  /**
   * Without email verification on registration, linking needs opting in.
   */
  public function testLinkUnverified(): void {
    $this->config('user.settings')->set('verify_mail', FALSE)->save();
    $existing = $this->createUser([], 'judy', FALSE, ['mail' => 'judy@example.org']);
    $this->assertRefused('judy@example.org', LoginFailure::LinkNotAllowed);
    $this->assertFalse($this->linkedUid('judy@example.org'));

    // New accounts are still created.
    $this->assertSame(AccountOutcome::Created, $this->resolution('ken@example.org')->outcome);

    $this->config('didroom_login.settings')->set('link_unverified', TRUE)->save();
    $this->assertSame($existing->id(), $this->resolve('judy@example.org')->id());
  }

  /**
   * A new account gets a username from the email and the default roles.
   */
  public function testCreate(): void {
    $this->config('didroom_login.settings')->set('default_roles', ['member', 'administrator', 'gone'])->save();
    $this->createUser([], 'carol');

    $resolution = $this->resolution('carol@example.org');
    $this->assertSame(AccountOutcome::Created, $resolution->outcome);
    $account = $resolution->account;
    $this->assertFalse($account->isNew());
    $this->assertTrue($account->isActive());
    $this->assertSame('carol_2', $account->getAccountName());
    $this->assertSame('carol@example.org', $account->getEmail());
    $this->assertSame('carol@example.org', $account->getInitialEmail());
    // Excluded and unknown roles are never granted.
    $this->assertSame(['member'], $account->getRoles(TRUE));
    $this->assertSame((int) $account->id(), $this->linkedUid('carol@example.org'));

    // Logging in again returns the same account.
    $this->assertSame($account->id(), $this->resolve('carol@example.org')->id());

    $this->assertSame('carol_3', $this->resolve('carol@other.example.org')->getAccountName());
  }

  /**
   * Usernames are sanitized and fall back to "user".
   */
  public function testUsernames(): void {
    $this->assertSame('o_brien', $this->resolve("o'brien@example.org")->getAccountName());
    $this->assertSame('user', $this->resolve('...@example.org')->getAccountName());
    $this->assertSame('jürgen.k', $this->resolve('jürgen.k@example.org')->getAccountName());
  }

  /**
   * No account is created when wallet or site registration is closed.
   */
  public function testRegistrationClosed(): void {
    $this->config('didroom_login.settings')->set('auto_create', FALSE)->save();
    $this->assertRefused('dave@example.org', LoginFailure::NoAccount);

    $this->config('didroom_login.settings')->set('auto_create', TRUE)->save();
    $this->config('user.settings')->set('register', UserInterface::REGISTER_ADMINISTRATORS_ONLY)->save();
    $this->assertRefused('dave@example.org', LoginFailure::NoAccount);
    $this->assertFalse($this->linkedUid('dave@example.org'));
  }

  /**
   * With admin approval, the account is created blocked.
   */
  public function testApprovalRequired(): void {
    $this->config('user.settings')->set('register', UserInterface::REGISTER_VISITORS_ADMINISTRATIVE_APPROVAL)->save();
    $this->config('system.site')->set('mail', 'site@example.org')->save();
    $this->assertRefused('erin@example.org', LoginFailure::PendingApproval);
    // Core's pending-approval mails go to the user and the site.
    $recipients = array_column($this->getMails(), 'to');
    $this->assertContains('erin@example.org', $recipients);
    $this->assertContains('site@example.org', $recipients);
    $uid = $this->linkedUid('erin@example.org');
    $this->assertNotFalse($uid);
    $this->assertTrue($this->container->get('entity_type.manager')->getStorage('user')->load($uid)->isBlocked());
    $this->assertRefused('erin@example.org', LoginFailure::AccountBlocked);
  }

  /**
   * Blocked accounts and excluded roles are refused, and never linked.
   */
  public function testRefused(): void {
    $this->createUser([], 'frank', FALSE, ['mail' => 'frank@example.org', 'status' => 0]);
    $this->assertRefused('frank@example.org', LoginFailure::AccountBlocked);
    $this->assertFalse($this->linkedUid('frank@example.org'));

    $admin = $this->createUser([], 'grace', FALSE, ['mail' => 'grace@example.org']);
    $admin->addRole('administrator')->save();
    $this->assertRefused('grace@example.org', LoginFailure::AccountExcluded);
    $this->assertFalse($this->linkedUid('grace@example.org'));

    // A linked account that gains an excluded role is refused too.
    $member = $this->createUser([], 'heidi', FALSE, ['mail' => 'heidi@example.org']);
    $this->resolve('heidi@example.org');
    $member->addRole('administrator')->save();
    $this->assertRefused('heidi@example.org', LoginFailure::AccountExcluded);
  }

}
