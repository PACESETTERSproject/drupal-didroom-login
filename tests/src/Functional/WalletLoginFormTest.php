<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\didroom_login\Traits\WalletLoginTestTrait;
use Drupal\didroom_login\Login\AccountResolver;
use Drupal\didroom_login\Login\LoginFailure;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests turning wallet login off and on from the account's tab.
 *
 * @group didroom_login
 */
#[Group('didroom_login')]
final class WalletLoginFormTest extends BrowserTestBase {

  use WalletLoginTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'didroom_login', 'didroom_login_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalCreateRole([], 'administrator', 'Administrator');
    $this->configureWalletLogin();
  }

  /**
   * Returns the URL of the account's wallet login tab.
   */
  private function tab(UserInterface $account): Url {
    return Url::fromRoute('didroom_login.user_wallet', ['user' => $account->id()]);
  }

  /**
   * The tab is shown to the account itself and to user administrators.
   */
  public function testAccess(): void {
    $account = $this->drupalCreateUser();
    $other = $this->drupalCreateUser();

    $this->drupalGet($this->tab($account));
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($other);
    $this->drupalGet($this->tab($account));
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($account);
    $this->drupalGet(Url::fromRoute('entity.user.canonical', ['user' => $account->id()]));
    $this->clickLink('Wallet login');
    $this->assertSession()->addressEquals($this->tab($account));
    $this->assertSession()->pageTextContains('This account is not linked to a wallet yet');

    // Not offered until wallet login is configured.
    $this->config('didroom_login.settings')->set('verifier_url', '')->save();
    $this->drupalGet($this->tab($account));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(Url::fromRoute('entity.user.canonical', ['user' => $account->id()]));
    $this->assertSession()->linkNotExists('Wallet login');
    $this->configureWalletLogin();

    $admin = $this->drupalCreateUser(['administer users']);
    $admin->addRole('administrator')->save();
    $this->drupalLogin($admin);
    $this->drupalGet($this->tab($account));
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($this->tab($admin));
    $this->assertSession()->pageTextContains('This account has a role that cannot log in with a wallet.');
  }

  /**
   * Turning wallet login off unlinks the account and refuses wallet logins.
   */
  public function testTurnOffAndOn(): void {
    $account = $this->drupalCreateUser();
    $authmap = $this->container->get('externalauth.authmap');

    $this->assertSame('done', $this->walletLogin($this->walletClaims($account->getEmail()))['status']);
    $this->drupalGet($this->tab($account));
    $this->assertSession()->pageTextContains('This account is linked to a wallet credential for ' . $account->getEmail() . '.');

    $this->submitForm([], 'Turn off wallet login');
    $this->assertSession()->pageTextContains('Wallet login is turned off for this account.');
    $this->assertSession()->buttonExists('Turn on wallet login');
    $this->assertSame([], $authmap->getAll((int) $account->id()));

    $this->walletLogout();
    $refused = ['status' => 'error', 'message' => (string) LoginFailure::WalletDisabled->userMessage()];
    $this->assertSame($refused, $this->walletLogin($this->walletClaims($account->getEmail())));
    $this->assertSame([], $authmap->getAll((int) $account->id()));
    $this->assertWalletLoggedOut();

    // Turned on again, the next wallet login links the account again.
    $this->drupalLogin($account);
    $this->drupalGet($this->tab($account));
    $this->submitForm([], 'Turn on wallet login');
    $this->assertSession()->pageTextContains('Wallet login is turned on for this account.');
    $this->assertSession()->buttonExists('Turn off wallet login');
    $this->walletLogout();
    $this->assertSame('done', $this->walletLogin($this->walletClaims($account->getEmail()))['status']);
    $this->assertSame((int) $account->id(), (int) $authmap->getUid($account->getEmail(), AccountResolver::PROVIDER));
  }

}
