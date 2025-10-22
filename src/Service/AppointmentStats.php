<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates appointment statistics per facilitator.
 */
class AppointmentStats {

  protected LoggerInterface $logger;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('appointment_facilitator');
  }

  /**
   * Builds aggregated statistics for appointments.
   */
  public function summarize(?DrupalDateTime $start = NULL, ?DrupalDateTime $end = NULL, array $options = []): array {
    $summary = [
      'total_appointments' => 0,
      'total_badge_appointments' => 0,
      'total_badges' => 0,
      'cancelled_total' => 0,
      'facilitators' => [],
      'purpose_totals' => [],
      'result_totals' => [],
      'status_totals' => [],
      'badge_ids' => [],
    ];

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1);

    $date_field = $this->resolveDateField();
    $using_range_field = $date_field === 'field_appointment_timerange';

    $date_filters_applied = FALSE;
    if ($date_field) {
      if ($start) {
        $query->condition($date_field . '.value', $this->formatDateForQuery($start, $using_range_field), '>=');
        $date_filters_applied = TRUE;
      }
      if ($end) {
        $query->condition($date_field . '.value', $this->formatDateForQuery($end, $using_range_field), '<=');
        $date_filters_applied = TRUE;
      }
    }

    if (!$date_filters_applied && ($start || $end)) {
      $this->logger->warning('Timeline filters requested but appointment date field not found; applying filters in-memory.');
    }

    if (!empty($options['purpose'])) {
      $query->condition('field_appointment_purpose.value', $options['purpose']);
    }

    $include_cancelled = !empty($options['include_cancelled']);
    if (!$include_cancelled && $this->fieldExists('field_appointment_status')) {
      $query->condition('field_appointment_status.value', 'canceled', '<>');
    }

    try {
      $nids = $query->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Appointment stats query failed: @msg', ['@msg' => $e->getMessage()]);
      return $summary;
    }

    if (!$nids) {
      return $summary;
    }

    $nodes = $storage->loadMultiple($nids);

    foreach ($nodes as $node) {
      if (!$date_filters_applied && ($start || $end)) {
        $appointment_date = $this->extractDate($node);
        if ($appointment_date) {
          if ($start && $appointment_date < $start) {
            continue;
          }
          if ($end && $appointment_date > $end) {
            continue;
          }
        }
      }

      $purpose = $this->extractFieldValue($node, 'field_appointment_purpose') ?? '_none';
      if (!empty($options['purpose']) && $purpose !== $options['purpose']) {
        continue;
      }

      $host_id = $this->extractHostId($node) ?? 0;
      if (!isset($summary['facilitators'][$host_id])) {
        $summary['facilitators'][$host_id] = [
          'uid' => $host_id,
          'appointments' => 0,
          'badge_sessions' => 0,
          'badges' => 0,
          'purpose_counts' => [],
          'result_counts' => [],
          'status_counts' => [],
          'badges_breakdown' => [],
          'latest' => NULL,
          'cancelled' => 0,
          'day_map' => [],
        ];
      }

      $summary['total_appointments']++;
      $summary['facilitators'][$host_id]['appointments']++;
      $summary['purpose_totals'][$purpose] = ($summary['purpose_totals'][$purpose] ?? 0) + 1;
      $summary['facilitators'][$host_id]['purpose_counts'][$purpose] = ($summary['facilitators'][$host_id]['purpose_counts'][$purpose] ?? 0) + 1;

      $result = $this->extractFieldValue($node, 'field_appointment_result') ?? '_none';
      $summary['result_totals'][$result] = ($summary['result_totals'][$result] ?? 0) + 1;
      $summary['facilitators'][$host_id]['result_counts'][$result] = ($summary['facilitators'][$host_id]['result_counts'][$result] ?? 0) + 1;

      $status = $this->extractFieldValue($node, 'field_appointment_status') ?? '_none';
      $summary['status_totals'][$status] = ($summary['status_totals'][$status] ?? 0) + 1;
      $summary['facilitators'][$host_id]['status_counts'][$status] = ($summary['facilitators'][$host_id]['status_counts'][$status] ?? 0) + 1;
      if ($status === 'canceled') {
        $summary['cancelled_total']++;
        $summary['facilitators'][$host_id]['cancelled']++;
      }

      $badge_ids = $this->extractBadgeIds($node);
      if ($badge_ids) {
        $summary['total_badge_appointments']++;
        $summary['facilitators'][$host_id]['badge_sessions']++;
      }

      $summary['total_badges'] += count($badge_ids);
      $summary['facilitators'][$host_id]['badges'] += count($badge_ids);

      foreach ($badge_ids as $bid) {
        $summary['badge_ids'][$bid] = TRUE;
        $summary['facilitators'][$host_id]['badges_breakdown'][$bid] = ($summary['facilitators'][$host_id]['badges_breakdown'][$bid] ?? 0) + 1;
      }

      $appointment_date = $this->extractDate($node);
      if ($appointment_date) {
        $day_key = $appointment_date->format('Y-m-d');
        if ($day_key) {
          $summary['facilitators'][$host_id]['day_map'][$day_key] = TRUE;
        }
        $current_latest = $summary['facilitators'][$host_id]['latest'];
        if (!$current_latest || $appointment_date > $current_latest) {
          $summary['facilitators'][$host_id]['latest'] = $appointment_date;
        }
      }
    }

    foreach ($summary['facilitators'] as &$facilitator) {
      $facilitator['appointment_day_count'] = isset($facilitator['day_map']) ? count($facilitator['day_map']) : 0;
      unset($facilitator['day_map']);
    }
    unset($facilitator);

    return $summary;
  }

  protected function resolveDateField(): ?string {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'appointment');
    if (isset($definitions['field_appointment_date'])) {
      return 'field_appointment_date';
    }
    if (isset($definitions['field_appointment_timerange'])) {
      return 'field_appointment_timerange';
    }
    return NULL;
  }

  protected function fieldExists(string $field_name): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'appointment');
    return isset($definitions[$field_name]);
  }

  protected function formatDateForQuery(DrupalDateTime $value, bool $include_time): string {
    return $include_time ? $value->format('Y-m-d\TH:i:s') : $value->format('Y-m-d');
  }

  protected function extractDate(NodeInterface $node): ?DrupalDateTime {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $value = $node->get('field_appointment_timerange')->value;
      if ($value) {
        return DrupalDateTime::createFromFormat('Y-m-d\TH:i:s', $value) ?: NULL;
      }
    }
    if ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $value = $node->get('field_appointment_date')->value;
      if ($value) {
        return DrupalDateTime::createFromFormat('Y-m-d', $value) ?: NULL;
      }
    }
    return NULL;
  }

  protected function extractHostId(NodeInterface $node): ?int {
    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $target = $node->get('field_appointment_host')->target_id;
      return $target !== NULL ? (int) $target : NULL;
    }
    return NULL;
  }

  protected function extractFieldValue(NodeInterface $node, string $field_name): ?string {
    if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
      return (string) $node->get($field_name)->value;
    }
    return NULL;
  }

  protected function extractBadgeIds(NodeInterface $node): array {
    if (!$node->hasField('field_appointment_badges') || $node->get('field_appointment_badges')->isEmpty()) {
      return [];
    }
    $values = $node->get('field_appointment_badges')->getValue();
    $ids = [];
    foreach ($values as $value) {
      if (!empty($value['target_id'])) {
        $ids[] = (int) $value['target_id'];
      }
    }
    return $ids;
  }

}
