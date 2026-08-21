<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Psr\Log\LoggerInterface;

/**
 * Aggregates appointment statistics per facilitator.
 */
class AppointmentStats {

  protected const ARRIVAL_TRACKING_START = '2026-01-01 00:00:00';

  protected LoggerInterface $logger;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly CacheBackendInterface $cacheBackend,
  ) {
    $this->logger = $loggerFactory->get('appointment_facilitator');
  }

  /**
   * Builds aggregated statistics for appointments.
   */
  public function summarize(?DrupalDateTime $start = NULL, ?DrupalDateTime $end = NULL, array $options = []): array {
    $cache_id = $this->buildSummaryCacheId($start, $end, $options);
    if ($cache = $this->cacheBackend->get($cache_id)) {
      return $cache->data;
    }

    $summary = [
      'total_appointments' => 0,
      'total_badge_appointments' => 0,
      'total_badges' => 0,
      'total_attendees' => 0,
      'total_feedback' => 0,
      'feedback_rate' => 0,
      'cancelled_total' => 0,
      'total_appointment_days' => 0,
      'total_tracked_appointment_days' => 0,
      'total_arrival_days' => 0,
      'arrival_rate' => NULL,
      'arrival_available' => FALSE,
      'arrival_tracking_start' => self::ARRIVAL_TRACKING_START,
      'arrival_status_totals' => [],
      'arrival_status_available' => FALSE,
      'facilitators' => [],
      'purpose_totals' => [],
      'result_totals' => [],
      'status_totals' => [],
      'badge_ids' => [],
      'facilitator_rate_averages' => [
        'appointments_per_week' => 0,
        'appointments_per_month' => 0,
        'feedback_rate' => 0,
        'arrival_rate' => 0,
      ],
    ];

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1);

    $host_filter = isset($options['host_id']) ? (int) $options['host_id'] : NULL;
    $use_facilitator_terms = !empty($options['use_facilitator_terms']);
    $now_ts = \Drupal::time()->getRequestTime();
    $term_cache = [];
    $arrival_status_available = $this->fieldExists('field_facilitator_arrival_status');
    $arrival_tracking_start_ts = (new \DateTimeImmutable(self::ARRIVAL_TRACKING_START, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();

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

    if ($host_filter) {
      $query->condition('field_appointment_host.target_id', $host_filter);
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
      if ($host_filter !== NULL && $host_id !== $host_filter) {
        continue;
      }
      if (!isset($summary['facilitators'][$host_id])) {
        $summary['facilitators'][$host_id] = [
          'uid' => $host_id,
          'appointments' => 0,
          'badge_sessions' => 0,
          'badges' => 0,
          'attendees' => 0,
          'feedback' => 0,
          'feedback_rate' => 0,
          'purpose_counts' => [],
          'result_counts' => [],
          'status_counts' => [],
          'badges_breakdown' => [],
          'latest' => NULL,
          'cancelled' => 0,
          'day_map' => [],
          'tracked_day_map' => [],
          'tracked_appointment_day_count' => 0,
          'arrival_tracking_start' => self::ARRIVAL_TRACKING_START,
          'arrival_days' => NULL,
          'arrival_rate' => NULL,
          'arrival_status_counts' => [],
          'term_start' => NULL,
          'term_open_ended' => FALSE,
          'term_single_shift' => FALSE,
          'term_end' => NULL,
          'term_elapsed_weeks' => NULL,
          'term_elapsed_months' => NULL,
          'appointments_per_week' => NULL,
          'appointments_per_month' => NULL,
        ];
      }

      if ($use_facilitator_terms) {
        if (!array_key_exists($host_id, $term_cache)) {
          $term_cache[$host_id] = $this->getFacilitatorTermRange($host_id);
        }
        $term_range = $term_cache[$host_id];
        if ($term_range) {
          $summary['facilitators'][$host_id]['term_start'] = $term_range['start'];
          $summary['facilitators'][$host_id]['term_end'] = $term_range['end'];
          $summary['facilitators'][$host_id]['term_open_ended'] = !empty($term_range['open_ended']);
          $summary['facilitators'][$host_id]['term_single_shift'] = !empty($term_range['single_shift']);
          $term_date = $this->extractDate($node);
          if ($term_date) {
            $appointment_ts = $term_date->getTimestamp();
            if ($appointment_ts < $term_range['start_ts'] || $appointment_ts > $term_range['effective_end_ts']) {
              continue;
            }
          }
        }
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

      if ($arrival_status_available) {
        $arrival_status = $this->extractFieldValue($node, 'field_facilitator_arrival_status');
        if ($arrival_status !== NULL && $arrival_status !== '') {
          $summary['arrival_status_totals'][$arrival_status] = ($summary['arrival_status_totals'][$arrival_status] ?? 0) + 1;
          $summary['facilitators'][$host_id]['arrival_status_counts'][$arrival_status] = ($summary['facilitators'][$host_id]['arrival_status_counts'][$arrival_status] ?? 0) + 1;
        }
      }

      $attendees = $this->countAttendees($node);
      $summary['total_attendees'] += $attendees;
      $summary['facilitators'][$host_id]['attendees'] += $attendees;

      if ($this->hasFeedback($node)) {
        $summary['total_feedback']++;
        $summary['facilitators'][$host_id]['feedback']++;
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
          if ($appointment_date->getTimestamp() >= $arrival_tracking_start_ts) {
            $summary['facilitators'][$host_id]['tracked_day_map'][$day_key] = TRUE;
          }
        }
        $current_latest = $summary['facilitators'][$host_id]['latest'];
        if (!$current_latest || $appointment_date > $current_latest) {
          $summary['facilitators'][$host_id]['latest'] = $appointment_date;
        }
      }
    }

    $rate_accumulator = [
      'appointments_per_week' => [],
      'appointments_per_month' => [],
      'feedback_rate' => [],
      'arrival_rate' => [],
    ];

    $arrival_presence = $this->loadArrivalPresence($summary['facilitators']);
    $arrival_available = is_array($arrival_presence);
    $summary['arrival_available'] = $arrival_available;
    $summary['arrival_status_available'] = $arrival_status_available;

    foreach ($summary['facilitators'] as &$facilitator) {
      $facilitator['appointment_day_count'] = isset($facilitator['day_map']) ? count($facilitator['day_map']) : 0;
      $facilitator['tracked_appointment_day_count'] = isset($facilitator['tracked_day_map']) ? count($facilitator['tracked_day_map']) : 0;
      $summary['total_appointment_days'] += $facilitator['appointment_day_count'];
      $summary['total_tracked_appointment_days'] += $facilitator['tracked_appointment_day_count'];
      unset($facilitator['day_map']);
      unset($facilitator['tracked_day_map']);
      if ($facilitator['appointments'] > 0) {
        $facilitator['feedback_rate'] = round(($facilitator['feedback'] / $facilitator['appointments']) * 100, 1);
      }

      if ($arrival_available && $facilitator['tracked_appointment_day_count'] > 0) {
        $uid = $facilitator['uid'];
        $arrival_days = isset($arrival_presence[$uid]) ? count($arrival_presence[$uid]) : 0;
        $facilitator['arrival_days'] = $arrival_days;
        $facilitator['arrival_rate'] = round(($arrival_days / $facilitator['tracked_appointment_day_count']) * 100, 1);
        $summary['total_arrival_days'] += $arrival_days;
      }

      $range = NULL;
      if ($use_facilitator_terms && $facilitator['term_start'] instanceof \DateTimeInterface && $facilitator['term_end'] instanceof \DateTimeInterface) {
        $range = [
          'start' => $facilitator['term_start']->getTimestamp(),
          'end' => min($facilitator['term_end']->getTimestamp(), $now_ts),
        ];
      }
      elseif ($start && $end) {
        $range = [
          'start' => $start->getTimestamp(),
          'end' => $end->getTimestamp(),
        ];
      }

      if ($range && $range['end'] >= $range['start']) {
        $elapsed = $this->calculateElapsedWindows($range['start'], $range['end']);
        $facilitator['term_elapsed_weeks'] = $elapsed['weeks'];
        $facilitator['term_elapsed_months'] = $elapsed['months'];
        $facilitator['appointments_per_week'] = $elapsed['weeks'] > 0
          ? round($facilitator['appointments'] / $elapsed['weeks'], 2)
          : NULL;
        $facilitator['appointments_per_month'] = $elapsed['months'] > 0
          ? round($facilitator['appointments'] / $elapsed['months'], 2)
          : NULL;
      }

      if ($facilitator['appointments_per_week'] !== NULL) {
        $rate_accumulator['appointments_per_week'][] = $facilitator['appointments_per_week'];
      }
      if ($facilitator['appointments_per_month'] !== NULL) {
        $rate_accumulator['appointments_per_month'][] = $facilitator['appointments_per_month'];
      }
      $rate_accumulator['feedback_rate'][] = $facilitator['feedback_rate'];
      if ($arrival_available && $facilitator['tracked_appointment_day_count'] > 0) {
        $rate_accumulator['arrival_rate'][] = $facilitator['arrival_rate'];
      }
    }
    unset($facilitator);

    if ($summary['total_appointments'] > 0) {
      $summary['feedback_rate'] = round(($summary['total_feedback'] / $summary['total_appointments']) * 100, 1);
    }
    if ($arrival_available && $summary['total_tracked_appointment_days'] > 0) {
      $summary['arrival_rate'] = round(($summary['total_arrival_days'] / $summary['total_tracked_appointment_days']) * 100, 1);
    }

    foreach ($rate_accumulator as $key => $values) {
      if ($values) {
        $summary['facilitator_rate_averages'][$key] = round(array_sum($values) / count($values), 2);
      }
    }

    $this->cacheBackend->set(
      $cache_id,
      $summary,
      \Drupal::time()->getRequestTime() + 300,
      [
        'node_list',
        'node_list:appointment',
        'config:appointment_facilitator.settings',
        'config:system.date',
        'access_control_log_list',
      ]
    );

    return $summary;
  }

  /**
   * Builds a deterministic cache key for summary requests.
   */
  protected function buildSummaryCacheId(?DrupalDateTime $start, ?DrupalDateTime $end, array $options): string {
    $normalized_options = $options;
    ksort($normalized_options);

    // Bucket current time so term-based rates stay reasonably fresh.
    $time_bucket = (int) floor(\Drupal::time()->getRequestTime() / 300);

    $parts = [
      'start' => $start?->format('Y-m-d H:i:s') ?? '',
      'end' => $end?->format('Y-m-d H:i:s') ?? '',
      'options' => $normalized_options,
      'time_bucket' => $time_bucket,
    ];

    return 'appointment_facilitator:summary:' . hash('sha256', serialize($parts));
  }

  protected function loadArrivalPresence(array $facilitators): ?array {
    $presence = [];
    if (!$facilitators) {
      return $presence;
    }

    if (!$this->entityTypeManager->hasDefinition('access_control_log')) {
      return NULL;
    }

    $definitions = $this->entityFieldManager->getFieldDefinitions('access_control_log', 'access_control_request');
    if (!isset($definitions['field_access_request_user'])) {
      return NULL;
    }

    $uids = [];
    $day_map = [];
    foreach ($facilitators as $facilitator) {
      $uid = $facilitator['uid'] ?? NULL;
      $tracked_days = $facilitator['tracked_day_map'] ?? [];
      if (!$uid || empty($tracked_days)) {
        continue;
      }
      $uids[] = (int) $uid;
      $day_map[$uid] = $tracked_days;
    }

    $uids = array_values(array_unique(array_filter($uids)));
    if (!$uids) {
      return $presence;
    }

    $timezone = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
    $tz = new \DateTimeZone($timezone);
    $range = $this->calculateDayRange($day_map, $tz);
    if (!$range) {
      return $presence;
    }

    $storage = $this->entityTypeManager->getStorage('access_control_log');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'access_control_request')
      ->condition('field_access_request_user.target_id', $uids, 'IN')
      ->condition('created', $range['start'], '>=')
      ->condition('created', $range['end'], '<=');

    try {
      $ids = $query->execute();
    }
    catch (\Exception $e) {
      $this->logger->warning('Unable to query access log presence: @message', ['@message' => $e->getMessage()]);
      return $presence;
    }

    if (!$ids) {
      return $presence;
    }

    $logs = $storage->loadMultiple($ids);
    foreach ($logs as $log) {
      if (!$log->hasField('field_access_request_user') || $log->get('field_access_request_user')->isEmpty()) {
        continue;
      }
      $uid = (int) $log->get('field_access_request_user')->target_id;
      if (!$uid || empty($day_map[$uid])) {
        continue;
      }

      $timestamp = NULL;
      if (method_exists($log, 'getCreatedTime')) {
        $timestamp = (int) $log->getCreatedTime();
      }
      elseif ($log->hasField('created') && !$log->get('created')->isEmpty()) {
        $timestamp = (int) $log->get('created')->value;
      }

      if (!$timestamp) {
        continue;
      }

      $day_key = (new \DateTimeImmutable('@' . $timestamp))
        ->setTimezone($tz)
        ->format('Y-m-d');
      if (!isset($day_map[$uid][$day_key])) {
        continue;
      }
      $presence[$uid][$day_key] = TRUE;
    }

    return $presence;
  }

  protected function calculateDayRange(array $day_map, \DateTimeZone $timezone): ?array {
    $min = NULL;
    $max = NULL;
    foreach ($day_map as $days) {
      foreach (array_keys($days) as $day) {
        if (!is_string($day) || $day === '') {
          continue;
        }
        try {
          $start = new \DateTimeImmutable($day . ' 00:00:00', $timezone);
          $end = new \DateTimeImmutable($day . ' 23:59:59', $timezone);
        }
        catch (\Exception $e) {
          continue;
        }
        $min = $min ? min($min, $start->getTimestamp()) : $start->getTimestamp();
        $max = $max ? max($max, $end->getTimestamp()) : $end->getTimestamp();
      }
    }

    if ($min === NULL || $max === NULL) {
      return NULL;
    }

    return [
      'start' => $min,
      'end' => $max,
    ];
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

  protected function formatDateForQuery(DrupalDateTime $value, bool $using_range_field): string|int {
    return $using_range_field ? $value->getTimestamp() : $value->format('Y-m-d');
  }

  protected function extractDate(NodeInterface $node): ?DrupalDateTime {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $item = $node->get('field_appointment_timerange')->first();
      $value = $item->value;
      if ($value !== NULL && $value !== '') {
        $timezone = $item->timezone ?: date_default_timezone_get();
        try {
          return DrupalDateTime::createFromTimestamp((int) $value, new \DateTimeZone($timezone));
        }
        catch (\Exception $e) {
          // Fall through to the legacy date field.
        }
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

  protected function countAttendees(NodeInterface $node): int {
    $count = 1;
    if (!$node->hasField('field_appointment_attendees') || $node->get('field_appointment_attendees')->isEmpty()) {
      return $count;
    }
    $author_id = $node->getOwnerId();
    foreach ($node->get('field_appointment_attendees')->getValue() as $item) {
      $target = $item['target_id'] ?? NULL;
      if ($target && (int) $target !== (int) $author_id) {
        $count++;
      }
    }
    return $count;
  }

  protected function hasFeedback(NodeInterface $node): bool {
    if ($node->hasField('field_appointment_result') && !$node->get('field_appointment_result')->isEmpty()) {
      $result = trim((string) $node->get('field_appointment_result')->value);
      if ($result !== '') {
        return TRUE;
      }
    }

    if (!$node->hasField('field_appointment_feedback') || $node->get('field_appointment_feedback')->isEmpty()) {
      return FALSE;
    }
    $value = trim((string) $node->get('field_appointment_feedback')->value);
    return $value !== '';
  }

  protected function calculateElapsedWindows(int $start_ts, int $end_ts): array {
    $elapsed_seconds = max(0, $end_ts - $start_ts);
    $elapsed_days = max(1, (int) ceil($elapsed_seconds / 86400));
    $weeks = max(1, round($elapsed_days / 7, 2));
    $months = max(1, round($elapsed_days / 30.4375, 2));
    return [
      'weeks' => $weeks,
      'months' => $months,
    ];
  }

  public function getFacilitatorTermRange(int $uid): ?array {
    if ($uid <= 0) {
      return NULL;
    }
    if (!\Drupal::moduleHandler()->moduleExists('profile')) {
      return NULL;
    }
    $bundle = \Drupal::config('appointment_facilitator.settings')->get('facilitator_profile_bundle') ?: 'coordinator';
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account) {
      return NULL;
    }
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByUser($account, $bundle);
    if (!$profiles) {
      return NULL;
    }

    $profile = NULL;
    if (is_object($profiles)) {
      $profile = $profiles;
    }
    elseif (is_array($profiles)) {
      $first_key = array_key_first($profiles);
      $profile = $first_key !== NULL ? $profiles[$first_key] : NULL;
    }
    elseif ($profiles instanceof \Traversable) {
      foreach ($profiles as $item) {
        $profile = $item;
        break;
      }
    }
    elseif (is_scalar($profiles)) {
      $profile = $this->entityTypeManager->getStorage('profile')->load($profiles);
    }

    if (!$profile || !is_object($profile) || !$profile->hasField('field_coordinator_hours') || $profile->get('field_coordinator_hours')->isEmpty()) {
      return NULL;
    }

    $now_ts = \Drupal::time()->getRequestTime();
    $items = $profile->get('field_coordinator_hours')->getValue();

    // A recurring rule's LAST generated instance is the only way to bound a
    // COUNT=n rule, so index every item's end by the rule it belongs to before
    // building candidates.
    $last_instance_by_rule = [];
    foreach ($items as $item) {
      $rrule_id = (int) ($item['rrule'] ?? 0);
      $item_end = (int) ($item['end_value'] ?? 0);
      if ($rrule_id > 0 && $item_end > 0) {
        $last_instance_by_rule[$rrule_id] = max($last_instance_by_rule[$rrule_id] ?? 0, $item_end);
      }
    }

    $candidates = [];
    $seen_rules = [];
    foreach ($items as $item) {
      $timezone = $item['timezone'] ?? date_default_timezone_get();
      $rrule_id = (int) ($item['rrule'] ?? 0);

      if ($rrule_id <= 0 || !class_exists(SmartDateRule::class)) {
        // A one-off shift with no rule: it is its own (very short) range. This
        // is rare — on live only a handful of rows have no rule at all.
        $start_ts = (int) ($item['value'] ?? 0);
        $end_ts = (int) ($item['end_value'] ?? 0);
        if (!$start_ts || !$end_ts) {
          continue;
        }
        $candidates[] = [
          'start_ts' => $start_ts,
          'end_ts' => $end_ts,
          'open_ended' => FALSE,
          'single_shift' => TRUE,
          'timezone' => $timezone,
        ];
        continue;
      }

      // Every instance of a rule yields the same term, so evaluate each rule
      // once rather than 100+ times.
      if (isset($seen_rules[$rrule_id])) {
        continue;
      }
      $seen_rules[$rrule_id] = TRUE;

      $rule = SmartDateRule::load($rrule_id);
      if (!$rule) {
        continue;
      }
      $timezone = $rule->getTimeZone() ?? $timezone;

      // NOTE: SmartDateRule::start/::end are the FIRST INSTANCE's bounds — a
      // single shift — not the span of the rule. Reading ::end as the term end
      // is what made every facilitator's "current term" a one-day window. The
      // term start is the first instance's start; the term end has to come from
      // the rule's limit.
      $start_ts = (int) $rule->get('start')->value;
      if (!$start_ts) {
        continue;
      }

      $end_ts = NULL;
      $open_ended = FALSE;
      $limit = trim((string) $rule->get('limit')->value);

      if ((int) $rule->get('unlimited')->value === 1 || $limit === '') {
        // No end was ever set: the rule runs forward indefinitely. This is the
        // majority case on live, and it means the recurrence honestly does not
        // record a term end — do not invent one.
        $open_ended = TRUE;
      }
      elseif (stripos($limit, 'UNTIL=') === 0) {
        $until = strtotime(substr($limit, 6));
        if ($until) {
          $end_ts = $until;
        }
        else {
          $open_ended = TRUE;
        }
      }
      elseif (stripos($limit, 'COUNT=') === 0) {
        // Bounded by a number of occurrences: the last generated instance is
        // the effective end.
        $end_ts = $last_instance_by_rule[$rrule_id] ?? NULL;
        if (!$end_ts) {
          $open_ended = TRUE;
        }
      }
      else {
        $open_ended = TRUE;
      }

      $candidates[] = [
        'start_ts' => $start_ts,
        'end_ts' => $end_ts,
        'open_ended' => $open_ended,
        'single_shift' => FALSE,
        'timezone' => $timezone,
      ];
    }

    if (!$candidates) {
      return NULL;
    }

    $current = [];
    $past = [];
    $future = [];
    foreach ($candidates as $candidate) {
      $started = $candidate['start_ts'] <= $now_ts;
      $finished = !$candidate['open_ended'] && $candidate['end_ts'] < $now_ts;
      if ($started && !$finished) {
        $current[] = $candidate;
      }
      elseif ($finished) {
        $past[] = $candidate;
      }
      else {
        $future[] = $candidate;
      }
    }

    $chosen = NULL;
    if ($current) {
      usort($current, static fn($a, $b) => $b['start_ts'] <=> $a['start_ts']);
      $chosen = $current[0];
    }
    elseif ($past) {
      usort($past, static fn($a, $b) => $b['end_ts'] <=> $a['end_ts']);
      $chosen = $past[0];
    }
    elseif ($future) {
      usort($future, static fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);
      $chosen = $future[0];
    }

    if (!$chosen) {
      return NULL;
    }

    $timezone = new \DateTimeZone($chosen['timezone'] ?: date_default_timezone_get());
    $start = (new \DateTimeImmutable('@' . $chosen['start_ts']))->setTimezone($timezone);

    // An open-ended term has no end date to show. Report "through today" so
    // callers that filter appointments still get a usable window, and flag it
    // so the UI can say "ongoing" instead of printing today as an end date.
    // A term that has not started yet must not report an end before its start
    // (an open-ended rule beginning next month would otherwise read as a
    // negative window).
    $end_ts = $chosen['open_ended']
      ? max($now_ts, (int) $chosen['start_ts'])
      : (int) $chosen['end_ts'];
    $end = (new \DateTimeImmutable('@' . $end_ts))->setTimezone($timezone);
    $effective_end_ts = max((int) $chosen['start_ts'], min($end_ts, $now_ts));

    return [
      'start' => $start,
      'end' => $end,
      'start_ts' => $chosen['start_ts'],
      'end_ts' => $end_ts,
      'effective_end_ts' => $effective_end_ts,
      'open_ended' => $chosen['open_ended'],
      'single_shift' => !empty($chosen['single_shift']),
    ];
  }

}
