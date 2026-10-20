<?php

declare(strict_types=1);

namespace Drupal\didroom_login_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Swaps the verifier client for the fake one.
 */
final class DidroomLoginTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container->getDefinition('didroom_login.verifier_client')
      ->setClass(FakeVerifierClient::class)
      ->addArgument(new Reference('state'));
  }

}
