<?php

declare(strict_types=1);

namespace Drupal\didroom_login\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\didroom_login\StatusReport;

/**
 * Hook implementations for Drupal 11.2 and later.
 */
final class DidroomLoginHooks {

  public function __construct(
    private readonly StatusReport $statusReport,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The module's status report lines.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return array_map(
      fn(array $requirement): array => ['severity' => RequirementSeverity::from($requirement['severity'])] + $requirement,
      $this->statusReport->requirements(),
    );
  }

}
