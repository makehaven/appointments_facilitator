<?php

namespace Drupal\appointment_facilitator\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

class AppointmentFacilitatorCommands extends DrushCommands {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityFieldManagerInterface $entityFieldManager;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * Backfill facilitator arrival status for appointments.
   *
   * @command appointment-facilitator:backfill-arrivals
   * @option start Start date (YYYY-MM-DD). Defaults to Jan 1 of the current year.
   * @option end End date (YYYY-MM-DD). Defaults to today.
   * @option force Overwrite existing arrival status values.
   * @usage drush appointment-facilitator:backfill-arrivals --start=2025-01-01 --end=2025-03-31
   */
  public function backfillArrivals(array $args, array $options = [
    // NULL, not InputOption::VALUE_OPTIONAL: that constant is the integer 2,
    // which is truthy, so an unpassed --start was used as the literal date
    // string "2" instead of falling back to Jan 1.
    'start' => NULL,
    'end' => NULL,
    // FALSE, not InputOption::VALUE_NONE: that constant is the integer 4, so
    // it made --force permanently on and every run overwrote arrival statuses
    // that were already set.
    'force' => FALSE,
  ]): void {
    if (!$this->entityTypeManager->hasDefinition('access_control_log')) {
      $this->logger()->warning('Access control log entity not available. Aborting.');
      return;
    }

    $access_fields = $this->entityFieldManager->getFieldDefinitions('access_control_log', 'access_control_request');
    if (!isset($access_fields['field_access_request_user'])) {
      $this->logger()->warning('Access control log is missing field_access_request_user. Aborting.');
      return;
    }

    $node_fields = $this->entityFieldManager->getFieldDefinitions('node', 'appointment');
    if (!isset($node_fields['field_facilitator_arrival_status'])) {
      $this->logger()->warning('Appointment field_facilitator_arrival_status is missing. Aborting.');
      return;
    }
    if (!isset($node_fields['field_appointment_date'])) {
      $this->logger()->warning('Appointment field_appointment_date is missing. Aborting.');
      return;
    }

    $site_timezone = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
    $tz = new \DateTimeZone($site_timezone);
    $start_input = $options['start'] ?? NULL;
    $end_input = $options['end'] ?? NULL;

    $start_date = $start_input ?: (new \DateTimeImmutable('now', $tz))->format('Y-01-01');
    $end_date = $end_input ?: (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

    $start = $this->normalizeDate($start_date, $tz);
    $end = $this->normalizeDate($end_date, $tz);
    if (!$start || !$end || $start > $end) {
      $this->logger()->warning('Invalid date range provided. Use YYYY-MM-DD for start/end.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_date.value', $start->format('Y-m-d'), '>=')
      ->condition('field_appointment_date.value', $end->format('Y-m-d'), '<=');

    if (isset($node_fields['field_appointment_status'])) {
      $query->condition('field_appointment_status.value', 'canceled', '<>');
    }

    $nids = $query->execute();
    if (!$nids) {
      $this->logger()->notice('No appointments found in the requested date range.');
      return;
    }

    $now = new \DateTimeImmutable('now', $tz);
    $force = !empty($options['force']);
    $updated = 0;
    $skipped = 0;

    $nodes = $storage->loadMultiple($nids);
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || $node->bundle() !== 'appointment') {
        continue;
      }

      if (!$force && !$node->get('field_facilitator_arrival_status')->isEmpty()) {
        $skipped++;
        continue;
      }

      $window = _appointment_facilitator_get_arrival_window($node, $site_timezone);
      if (!$window) {
        $skipped++;
        continue;
      }

      if ($window['end_ts'] > $now->getTimestamp()) {
        $skipped++;
        continue;
      }

      $scan = _appointment_facilitator_find_arrival_scan($node, $window, $site_timezone);
      $grace = (int) (\Drupal::config('appointment_facilitator.settings')->get('arrival_grace_minutes') ?? 5);
      $status = _appointment_facilitator_classify_arrival($window['start'], $window['end'], $scan, $grace);

      $node->set('field_facilitator_arrival_status', $status);
      if ($scan && $node->hasField('field_facilitator_arrival_time')) {
        $node->set('field_facilitator_arrival_time', $scan->format('Y-m-d\TH:i:s'));
      }
      $node->save();
      $updated++;
    }

    $this->logger()->notice('Arrival backfill complete. Updated: @updated. Skipped: @skipped.', [
      '@updated' => $updated,
      '@skipped' => $skipped,
    ]);
  }

  protected function normalizeDate(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable {
    try {
      $date = new \DateTimeImmutable($value, $timezone);
      return $date->setTime(0, 0, 0);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
