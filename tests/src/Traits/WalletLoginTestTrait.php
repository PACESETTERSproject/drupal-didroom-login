<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Traits;

use Drupal\Core\Url;
use Drupal\didroom_login_test\FakeVerifierClient;

/**
 * Drives wallet logins against the fake verifier of didroom_login_test.
 */
trait WalletLoginTestTrait {

  /**
   * Returns the issuer the tests trust.
   */
  protected function trustedIssuer(): string {
    return 'https://issuer.example.org/credential_issuer';
  }

  /**
   * Configures the module, so that wallet login is offered.
   */
  protected function configureWalletLogin(): void {
    $this->config('didroom_login.settings')
      ->set('verifier_url', 'https://verifier.example.org/verifier/')
      ->set('trusted_issuers', [$this->trustedIssuer()])
      ->save();
  }

  /**
   * Builds the claims the verifier returns for an email credential.
   *
   * @return array<string, list<array<string, string>>>
   *   Claims keyed by the DCQL credential id.
   */
  protected function walletClaims(string $email, ?string $issuer = NULL): array {
    return ['email_credential' => [['email' => $email, 'iss' => $issuer ?? $this->trustedIssuer()]]];
  }

  /**
   * Opens the wallet login page, which starts a verifier session.
   *
   * @param array<string, string> $query
   *   The query string, e.g. a destination.
   */
  protected function startWalletLogin(array $query = []): void {
    $this->drupalGet(Url::fromRoute('didroom_login.login', [], ['query' => $query]));
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Asks the site for the state of the wallet login in progress.
   *
   * @return array<string, string>
   *   The decoded status response.
   */
  protected function walletStatus(): array {
    $data = json_decode($this->drupalGet(Url::fromRoute('didroom_login.status')), TRUE);
    $this->assertIsArray($data);
    return $data;
  }

  /**
   * Starts a wallet login, lets the wallet answer, and returns the status.
   *
   * @param array<string, mixed>|string $result
   *   Claims, or a failure reason from the verifier.
   * @param array<string, string> $query
   *   The query string of the login page.
   *
   * @return array<string, string>
   *   The decoded status response.
   */
  protected function walletLogin(array|string $result, array $query = []): array {
    $this->startWalletLogin($query);
    FakeVerifierClient::answer($result);
    return $this->walletStatus();
  }

  /**
   * Asserts that the browser is logged in as the user with the uid.
   */
  protected function assertWalletLoggedIn(int|string $uid): void {
    $this->drupalGet(Url::fromRoute('entity.user.edit_form', ['user' => $uid]));
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Asserts that the browser is anonymous.
   */
  protected function assertWalletLoggedOut(): void {
    $this->drupalGet(Url::fromRoute('user.login'));
    $this->assertSession()->fieldExists('name');
  }

  /**
   * Logs the browser out.
   */
  protected function walletLogout(): void {
    $this->drupalGet(Url::fromRoute('user.logout.confirm'));
    $this->submitForm([], 'op', 'user-logout-confirm');
    $this->assertWalletLoggedOut();
  }

}
