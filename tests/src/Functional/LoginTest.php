<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\didroom_login\Traits\WalletLoginTestTrait;
use Drupal\didroom_login\Login\AccountResolver;
use Drupal\didroom_login\Login\LoginFailure;
use Drupal\didroom_login_test\FakeVerifierClient;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests logging in with a wallet, against a fake verifier.
 *
 * @group didroom_login
 */
#[Group('didroom_login')]
final class LoginTest extends BrowserTestBase {

  use AssertMailTrait;
  use WalletLoginTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['didroom_login', 'didroom_login_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateRole([], 'administrator', 'Administrator');
    $this->drupalCreateRole([], 'member', 'Member');
    $this->config('user.settings')
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->set('verify_mail', TRUE)
      ->save();
    $this->configureWalletLogin();
  }

  /**
   * Loads an account fresh from storage.
   */
  private function reload(int|string $uid): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$uid]);
    return $storage->load($uid);
  }

  /**
   * Returns the login notifications sent so far.
   *
   * @return list<array<string, mixed>>
   *   The mails, with the body unwrapped to one line.
   */
  private function notifications(): array {
    return array_map(
      fn(array $mail) => ['body' => preg_replace('/\s+/', ' ', $mail['body'])] + $mail,
      $this->getMails(['id' => 'didroom_login_login_notification']),
    );
  }

  /**
   * The login link and page are offered only once configured, to anonymous.
   */
  public function testEntryPoints(): void {
    $this->config('didroom_login.settings')->set('trusted_issuers', [])->save();
    $this->drupalGet(Url::fromRoute('user.login'));
    $this->assertSession()->linkNotExists('Log in with Pacesetters wallet');
    $this->drupalGet(Url::fromRoute('didroom_login.login'));
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSame([], FakeVerifierClient::requests());

    $this->configureWalletLogin();
    $this->drupalGet(Url::fromRoute('user.login', [], ['query' => ['destination' => '/node/1']]));
    $link = $this->assertSession()->elementExists('named', ['link', 'Log in with Pacesetters wallet']);
    $this->assertStringEndsWith('/didroom/login?destination=/node/1', urldecode($link->getAttribute('href')));

    $link->click();
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementAttributeContains('css', 'img.didroom-login__qr', 'src', FakeVerifierClient::QR_CODE);
    $wallet_link = $this->assertSession()->elementExists('named', ['link', 'Open in wallet app']);
    $this->assertStringStartsWith('openid4vp://', $wallet_link->getAttribute('href'));
    $this->assertSession()->pageTextContains('The QR code is valid for 120 seconds.');
    $settings = $this->getDrupalSettings()['didroomLogin'];
    $this->assertSame(Url::fromRoute('didroom_login.status')->toString(), $settings['statusUrl']);
    $this->assertSame(120, $settings['expiresIn']);

    // The session asks for the credential of a trusted issuer only.
    $request = FakeVerifierClient::requests()[0];
    $this->assertSame('POST', $request['method']);
    $this->assertSame('https://verifier.example.org/verifier/generate_authorization_request', $request['url']);
    $claims = $request['options']['json']['dcql_query']['credentials'][0]['claims'];
    $this->assertContains(['path' => ['iss'], 'values' => [$this->trustedIssuer()]], $claims);

    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet(Url::fromRoute('didroom_login.login'));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(Url::fromRoute('didroom_login.status'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A new person gets an account, is logged in and sent to the destination.
   */
  public function testNewAccount(): void {
    $this->config('didroom_login.settings')->set('default_roles', ['member'])->save();
    $destination = Url::fromRoute('user.page', [], ['query' => ['from' => 'wallet']])->toString();

    $this->startWalletLogin(['destination' => $destination]);
    $this->assertSame(['status' => 'pending'], $this->walletStatus());
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSame(['status' => 'pending'], $this->walletStatus());

    FakeVerifierClient::answer($this->walletClaims('New.Person@Example.org'));
    $this->assertSame(['status' => 'done', 'redirect' => $destination], $this->walletStatus());

    $uid = $this->container->get('externalauth.authmap')->getUid('new.person@example.org', AccountResolver::PROVIDER);
    $this->assertNotFalse($uid);
    $account = $this->reload($uid);
    $this->assertSame('new.person', $account->getAccountName());
    $this->assertSame('new.person@example.org', $account->getEmail());
    $this->assertSame(['member'], $account->getRoles(TRUE));
    $this->assertWalletLoggedIn($uid);

    $mails = $this->notifications();
    $this->assertCount(1, $mails);
    $this->assertSame('new.person@example.org', $mails[0]['to']);
    $this->assertStringContainsString('Your new account at', $mails[0]['subject']);
    $this->assertStringContainsString('An account was created for you', $mails[0]['body']);
    $this->assertStringNotContainsString('your password was replaced', $mails[0]['body']);

    // A result logs in once only.
    $this->walletLogout();
    $this->assertSame('expired', $this->walletStatus()['status']);
    $this->assertWalletLoggedOut();

    // The next login finds the account through the link.
    $this->assertSame('done', $this->walletLogin($this->walletClaims('new.person@example.org'))['status']);
    $this->assertWalletLoggedIn($uid);
    $this->assertStringContainsString('Wallet login to', $this->notifications()[1]['subject']);
  }

  /**
   * Destinations off the site, or back to a login page, are ignored.
   */
  public function testUnsafeDestinations(): void {
    $account = $this->drupalCreateUser();
    $this->container->get('externalauth.authmap')->save($account, AccountResolver::PROVIDER, $account->getEmail());
    $home = Url::fromRoute('entity.user.canonical', ['user' => $account->id()])->toString();

    $destinations = [
      'https://evil.example/',
      '//evil.example/',
      '/\\evil.example/',
      "/a\nb",
      'user/2',
      Url::fromRoute('user.login')->toString(),
      Url::fromRoute('didroom_login.login', [], ['query' => ['x' => 'y']])->toString(),
    ];
    foreach ($destinations as $destination) {
      $status = $this->walletLogin($this->walletClaims($account->getEmail()), ['destination' => $destination]);
      $this->assertSame(['status' => 'done', 'redirect' => $home], $status, $destination);
      $this->walletLogout();
    }
  }

  /**
   * An existing account is linked by email and its password replaced.
   */
  public function testExistingAccount(): void {
    $account = $this->drupalCreateUser();
    $password = $account->passRaw;
    $password_service = $this->container->get('password');

    $home = Url::fromRoute('entity.user.canonical', ['user' => $account->id()])->toString();
    $this->assertSame(['status' => 'done', 'redirect' => $home], $this->walletLogin($this->walletClaims($account->getEmail())));
    $this->assertWalletLoggedIn($account->id());
    $this->assertFalse($password_service->check($password, $this->reload($account->id())->getPassword()), 'The old password no longer works.');

    $mail = $this->notifications()[0];
    $this->assertSame($account->getEmail(), $mail['to']);
    $this->assertStringContainsString('Wallet login to', $mail['subject']);
    $this->assertStringContainsString('Your wallet is now linked to your existing account', $mail['body']);
    $this->assertStringContainsString('your password was replaced', $mail['body']);

    // Without notifications and the password reset, nothing else changes.
    $this->config('didroom_login.settings')
      ->set('reset_password_on_link', FALSE)
      ->set('notify_login', FALSE)
      ->save();
    $other = $this->drupalCreateUser();
    $this->walletLogout();
    $this->assertSame('done', $this->walletLogin($this->walletClaims($other->getEmail()))['status']);
    $this->assertWalletLoggedIn($other->id());
    $this->assertTrue($password_service->check($other->passRaw, $this->reload($other->id())->getPassword()));
    $this->assertCount(1, $this->notifications());
  }

  /**
   * Presentations that must not log anyone in are refused with a reason.
   */
  public function testRefused(): void {
    $admin = $this->drupalCreateUser();
    $admin->addRole('administrator')->save();
    $blocked = $this->drupalCreateUser();
    $blocked->block()->save();
    $existing = $this->drupalCreateUser();
    $disabled = $this->drupalCreateUser();
    $this->container->get('didroom_login.account_resolver')->setDisabled($disabled, TRUE);

    $error = fn(string $message) => ['status' => 'error', 'message' => $message];
    $failure = fn(LoginFailure $reason) => $error((string) $reason->userMessage());
    $untrusted = $this->walletClaims('eve@example.org', 'https://evil.example');
    $no_email = ['email_credential' => [['iss' => $this->trustedIssuer()]]];
    $cases = [
      'verifier failure' => ['Invalid presentation', $error('Your wallet could not prove the requested credential.')],
      'untrusted issuer' => [$untrusted, $failure(LoginFailure::UntrustedIssuer)],
      'no email' => [$no_email, $failure(LoginFailure::InvalidCredential)],
      'excluded role' => [$this->walletClaims($admin->getEmail()), $failure(LoginFailure::AccountExcluded)],
      'blocked' => [$this->walletClaims($blocked->getEmail()), $failure(LoginFailure::AccountBlocked)],
      'turned off' => [$this->walletClaims($disabled->getEmail()), $failure(LoginFailure::WalletDisabled)],
    ];
    foreach ($cases as $case => [$result, $expected]) {
      $this->assertSame($expected, $this->walletLogin($result), $case);
      // The failed result is not reused.
      $this->assertSame('expired', $this->walletStatus()['status'], $case);
    }

    // Without email verification, existing accounts are not linked.
    $this->config('user.settings')->set('verify_mail', FALSE)->save();
    $this->assertSame($failure(LoginFailure::LinkNotAllowed), $this->walletLogin($this->walletClaims($existing->getEmail())));
    $this->assertSame([], $this->container->get('externalauth.authmap')->getAll((int) $existing->id()));

    // Closed registration stops new accounts.
    $this->config('user.settings')->set('register', UserInterface::REGISTER_ADMINISTRATORS_ONLY)->save();
    $this->assertSame($failure(LoginFailure::NoAccount), $this->walletLogin($this->walletClaims('nobody@example.org')));
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => 'nobody@example.org']));

    $this->assertWalletLoggedOut();
    $this->assertSame([], $this->notifications());
  }

  /**
   * A login is refused once the QR code expires, even if the wallet answers.
   */
  public function testExpired(): void {
    $this->assertSame(['status' => 'expired', 'message' => 'No wallet login is in progress.'], $this->walletStatus());

    $this->config('didroom_login.settings')->set('session_ttl', 1)->save();
    $this->startWalletLogin();
    sleep(2);
    FakeVerifierClient::answer($this->walletClaims('late@example.org'));
    $this->assertSame(['status' => 'expired', 'message' => 'The QR code has expired.'], $this->walletStatus());
    $this->assertSame('expired', $this->walletStatus()['status']);
    $this->assertWalletLoggedOut();
  }

  /**
   * An unreachable verifier shows an error, or keeps the login pending.
   */
  public function testVerifierDown(): void {
    FakeVerifierClient::setDown(TRUE);
    $this->startWalletLogin();
    $this->assertSession()->pageTextContains('Wallet login is unavailable right now.');
    $this->assertSession()->elementNotExists('css', 'img.didroom-login__qr');

    FakeVerifierClient::setDown(FALSE);
    $this->startWalletLogin();
    FakeVerifierClient::answer($this->walletClaims('patient@example.org'));
    FakeVerifierClient::setDown(TRUE);
    $this->assertSame(['status' => 'pending'], $this->walletStatus());
    FakeVerifierClient::setDown(FALSE);
    $this->assertSame('done', $this->walletStatus()['status']);
  }

  /**
   * The status report flags the configuration problems.
   */
  public function testStatusReport(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $report = Url::fromRoute('system.status');

    $this->drupalGet($report);
    $this->assertSession()->pageTextContains('Verifier reachable');
    $this->assertSession()->pageTextContains('Wallet login verifier token');
    $this->assertSession()->pageTextNotContains('Wallet login account linking');

    $this->writeSettings([
      'settings' => [
        'didroom_login_verifier_token' => (object) ['value' => 'test-token', 'required' => TRUE],
      ],
    ]);
    $this->config('user.settings')->set('verify_mail', FALSE)->save();
    $this->drupalGet($report);
    $this->assertSession()->pageTextNotContains('Wallet login verifier token');
    $this->assertSession()->pageTextContains('Existing accounts are not linked');
    // The token goes to the verifier.
    $requests = FakeVerifierClient::requests();
    $this->assertSame('Bearer test-token', end($requests)['options']['headers']['Authorization']);

    $this->config('didroom_login.settings')->set('link_unverified', TRUE)->save();
    FakeVerifierClient::setDown(TRUE);
    $this->drupalGet($report);
    $this->assertSession()->pageTextContains('Unverified emails are linked');
    $this->assertSession()->pageTextContains('Verifier unreachable');

    $this->config('didroom_login.settings')->set('verifier_url', '')->save();
    $this->drupalGet($report);
    $this->assertSession()->pageTextContains('Not configured');
  }

}
