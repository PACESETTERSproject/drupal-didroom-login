<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the DIDroom Login settings form.
 *
 * @group didroom_login
 */
#[Group('didroom_login')]
final class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['didroom_login'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A valid DCQL query asking for an SD-JWT email credential.
   */
  private const VALID_DCQL = '{"credentials":[{"id":"email_credential","format":"dc+sd-jwt","claims":[{"path":["email"]}]}]}';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateRole([], 'administrator', 'Administrator');
    $this->drupalCreateRole([], 'member', 'Member');
  }

  /**
   * Only users with the module permission can reach the form.
   */
  public function testAccess(): void {
    $url = Url::fromRoute('didroom_login.settings');

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer didroom login']));
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxChecked('excluded_roles[administrator]');
    $this->assertSession()->checkboxChecked('auto_create');
    $this->assertSession()->fieldValueEquals('session_ttl', '300');
  }

  /**
   * Invalid input is rejected and valid input is normalized and saved.
   */
  public function testValidationAndSave(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer didroom login']));
    $this->drupalGet(Url::fromRoute('didroom_login.settings'));

    $valid = [
      'verifier_url' => 'https://verifier.example.org/verifier',
      'dcql_query' => self::VALID_DCQL,
      'email_claim_path' => 'email',
      'trusted_issuers' => "https://issuer.example.org/credential_issuer\n\n  https://other.example.org  \nhttps://issuer.example.org/credential_issuer",
      'session_ttl' => '120',
      'auto_create' => TRUE,
      'default_roles[member]' => TRUE,
      'excluded_roles[administrator]' => TRUE,
      // A failed submission re-renders the form with what was posted, so
      // reset the checkbox that the "role conflict" case ticks.
      'excluded_roles[member]' => FALSE,
    ];

    $cases = [
      'invalid JSON' => [['dcql_query' => '{not json'], 'The DCQL query is not valid JSON'],
      'missing credentials' => [['dcql_query' => '{"credentials":[]}'], 'needs a non-empty'],
      'unsupported format' => [
        ['dcql_query' => '{"credentials":[{"id":"x","format":"jwt_vc_json"}]}'],
        'format must be one of',
      ],
      'bad claim path' => [['email_claim_path' => 'credentialSubject..email'], 'Use claim names separated by dots'],
      'role conflict' => [['excluded_roles[member]' => TRUE], 'cannot get a role that is excluded'],
      'ttl too short' => [['session_ttl' => '10'], 'must be higher than or equal to'],
    ];
    foreach ($cases as $case => [$override, $message]) {
      $this->submitForm($override + $valid, 'Save configuration');
      $this->assertSession()->pageTextContains($message);
      $this->assertSame('', $this->config('didroom_login.settings')->get('verifier_url'), "Nothing saved for: $case");
    }

    $this->submitForm($valid, 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('didroom_login.settings');
    $this->assertSame('https://verifier.example.org/verifier/', $config->get('verifier_url'));
    $this->assertSame(self::VALID_DCQL, $config->get('dcql_query'));
    $this->assertSame(['https://issuer.example.org/credential_issuer', 'https://other.example.org'], $config->get('trusted_issuers'));
    $this->assertSame(120, $config->get('session_ttl'));
    $this->assertTrue($config->get('auto_create'));
    $this->assertSame(['member'], $config->get('default_roles'));
    $this->assertSame(['administrator'], $config->get('excluded_roles'));
  }

}
