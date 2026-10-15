<?php

declare(strict_types=1);

namespace Drupal\Tests\didroom_login\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\didroom_login\Login\AccountResolver;
use Drupal\didroom_login\Login\ClaimValidator;
use Drupal\didroom_login\Verifier\VerifierClient;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the module's services are wired up.
 *
 * @group didroom_login
 */
#[Group('didroom_login')]
final class ServicesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['didroom_login', 'externalauth', 'user'];

  /**
   * The service resolves by id and by class, and reads installed config.
   */
  public function testService(): void {
    $this->installConfig(['didroom_login']);
    $client = $this->container->get('didroom_login.verifier_client');
    $this->assertInstanceOf(VerifierClient::class, $client);
    $this->assertSame($client, $this->container->get(VerifierClient::class));

    $this->config('didroom_login.settings')->set('trusted_issuers', ['https://issuer.example.org'])->save();
    $query = $client->buildDcqlQuery();
    $this->assertSame('email_credential', $query['credentials'][0]['id']);
    $this->assertSame(['path' => ['iss'], 'values' => ['https://issuer.example.org']], end($query['credentials'][0]['claims']));

    // The login services resolve by id and by class too.
    $services = [
      ClaimValidator::class => 'didroom_login.claim_validator',
      AccountResolver::class => 'didroom_login.account_resolver',
    ];
    foreach ($services as $class => $id) {
      $this->assertInstanceOf($class, $this->container->get($id));
      $this->assertSame($this->container->get($id), $this->container->get($class));
    }
  }

}
