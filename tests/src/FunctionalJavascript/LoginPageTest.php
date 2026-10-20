<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\didroom_login\Traits\WalletLoginTestTrait;
use Drupal\didroom_login_test\FakeVerifierClient;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the polling of the wallet login page in a browser.
 *
 * @group didroom_login
 */
#[Group('didroom_login')]
final class LoginPageTest extends WebDriverTestBase {

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
   * Milliseconds to wait for the page to react: a few polling intervals.
   */
  private const WAIT = 12000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('user.settings')->set('register', UserInterface::REGISTER_VISITORS)->save();
    $this->configureWalletLogin();
  }

  /**
   * The page follows the redirect once the wallet answers.
   */
  public function testLogin(): void {
    $account = $this->drupalCreateUser();
    $destination = Url::fromRoute('entity.user.edit_form', ['user' => $account->id()])->toString();
    $this->drupalGet(Url::fromRoute('didroom_login.login', [], ['query' => ['destination' => $destination]]));
    $this->assertSession()->pageTextContains('Waiting for your wallet.');

    FakeVerifierClient::answer($this->walletClaims($account->getEmail()));
    $this->assertTrue($this->getSession()->wait(self::WAIT, 'window.location.pathname === ' . json_encode($destination)), 'Redirected to the destination.');
    $this->assertSession()->fieldValueEquals('mail', $account->getEmail());
  }

  /**
   * A refusal is shown with a way to try again.
   */
  public function testRefusal(): void {
    $this->drupalGet(Url::fromRoute('didroom_login.login'));
    FakeVerifierClient::answer('Invalid presentation');
    $this->assertSession()->waitForText('Your wallet could not prove the requested credential.', self::WAIT);
    $this->assertFalse($this->getSession()->getPage()->find('css', 'img.didroom-login__qr')->isVisible(), 'The QR code is hidden.');
    $this->assertFalse($this->getSession()->getPage()->findLink('Open in wallet app')->isVisible());

    // Followed by URL: clicking it fails in the WebDriver of Drupal 10.
    $retry = $this->assertSession()->waitForLink('Try again');
    $this->assertNotNull($retry);
    $this->assertSame(Url::fromRoute('didroom_login.login')->toString(), $retry->getAttribute('href'));
    $this->drupalGet($retry->getAttribute('href'));
    $this->assertSession()->pageTextContains('Waiting for your wallet.');
    $this->assertTrue($this->getSession()->getPage()->find('css', 'img.didroom-login__qr')->isVisible());
    $this->assertCount(2, array_filter(FakeVerifierClient::requests(), fn(array $request) => $request['method'] === 'POST'), 'A new session was opened.');
  }

  /**
   * The QR code is replaced by a message once it expires.
   */
  public function testExpiry(): void {
    $this->config('didroom_login.settings')->set('session_ttl', 2)->save();
    $this->drupalGet(Url::fromRoute('didroom_login.login'));
    $this->assertSession()->pageTextContains('The QR code is valid for 2 seconds.');
    $this->assertSession()->waitForText('The QR code has expired.', self::WAIT);
    $this->assertSession()->linkExists('Try again');
    $this->assertFalse($this->getSession()->getPage()->find('css', 'img.didroom-login__qr')->isVisible());
  }

}
