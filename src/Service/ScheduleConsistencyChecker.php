<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Detects appointments whose stored time falls outside the host's posted hours.
 *
 * An appointment's time is not entered directly: it is computed as
 * "the shift's base start time + the selected slot offsets". The base comes
 * from a `start_time` parameter on the booking link, which is rendered from the
 * facilitator's `field_coordinator_hours` rows. Nothing has ever re-checked the
 * result against those hours afterwards, so when the hours rows drift from the
 * recurrence rule that generates them, every appointment booked in the
 * meantime is silently recorded at the drifted time and stays there.
 *
 * That happened: for months one facilitator's profile rows read three hours
 * later than the rule that generates them, so 44 bookings were recorded three
 * hours after the real Thursday shift. `appointment_facilitator_update_9025`
 * re-synced the rows on 2026-08-19, which corrected the schedule but left the
 * already-booked appointments on the old times — surfacing as a water-jet
 * checkout displayed at 7:30pm for a session that really ran inside 3–6pm.
 *
 * This service is the check that was missing. It answers two questions:
 * does an appointment sit inside its host's posted hours, and do a
 * facilitator's posted hours still agree with the rule that generates them.
 */
class ScheduleConsistencyChecker {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Checks an appointment against its host's posted hours for that day.
   *
   * @param \Drupal\node\NodeInterface $appointment
   *   The appointment node.
   *
   * @return array|null
   *   NULL when the appointment cannot be checked (no host, no time, no
   *   coordinator profile, or no posted shift that day) or when it sits inside
   *   a posted shift. Otherwise an array with keys:
   *   - start, end: appointment timestamps.
   *   - shifts: list of ['start' => ts, 'end' => ts] posted that day.
   *   - offset_minutes: signed difference between the appointment start and the
   *     nearest shift start, which is the drift when one exists.
   */
  public function checkAppointment(NodeInterface $appointment): ?array {
    if ($appointment->bundle() !== 'appointment') {
      return NULL;
    }
    if (!$appointment->hasField('field_appointment_timerange')
      || $appointment->get('field_appointment_timerange')->isEmpty()
      || !$appointment->hasField('field_appointment_host')
      || $appointment->get('field_appointment_host')->isEmpty()) {
      return NULL;
    }

    $item = $appointment->get('field_appointment_timerange')->first();
    $start = (int) $item->value;
    $end = (int) ($item->end_value ?? 0);
    if ($start <= 0 || $end <= 0) {
      return NULL;
    }

    $shifts = $this->getPostedShifts((int) $appointment->get('field_appointment_host')->target_id, $start);
    if (!$shifts) {
      return NULL;
    }

    foreach ($shifts as $shift) {
      if ($start >= $shift['start'] && $end <= $shift['end']) {
        return NULL;
      }
    }

    // Report the drift against whichever posted shift is nearest, so a caller
    // can say "three hours late" rather than only "outside".
    $offset = NULL;
    foreach ($shifts as $shift) {
      $diff = (int) round(($start - $shift['start']) / 60);
      if ($offset === NULL || abs($diff) < abs($offset)) {
        $offset = $diff;
      }
    }

    return [
      'start' => $start,
      'end' => $end,
      'shifts' => $shifts,
      'offset_minutes' => $offset,
    ];
  }

  /**
   * Returns the shifts a facilitator has posted on the day of a timestamp.
   *
   * @return array<int, array{start: int, end: int}>
   *   Posted shifts, chronological. Empty when the host has no coordinator
   *   profile or nothing posted that day.
   */
  public function getPostedShifts(int $host_uid, int $timestamp): array {
    if ($host_uid <= 0) {
      return [];
    }

    $profile_id = $this->getCoordinatorProfileId($host_uid);
    if (!$profile_id) {
      return [];
    }

    $timezone = new \DateTimeZone(date_default_timezone_get());
    $day_start = (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone($timezone)
      ->setTime(0, 0)
      ->getTimestamp();

    $table = 'profile__field_coordinator_hours';
    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $rows = $this->database->select($table, 'h')
      ->fields('h', ['field_coordinator_hours_value', 'field_coordinator_hours_end_value'])
      ->condition('h.entity_id', $profile_id)
      ->condition('h.field_coordinator_hours_value', [$day_start, $day_start + 86400], 'BETWEEN')
      ->orderBy('h.field_coordinator_hours_value')
      ->execute()
      ->fetchAll();

    $shifts = [];
    foreach ($rows as $row) {
      $shifts[] = [
        'start' => (int) $row->field_coordinator_hours_value,
        'end' => (int) $row->field_coordinator_hours_end_value,
      ];
    }

    return $shifts;
  }

  /**
   * Reports facilitators whose posted hours disagree with their rule.
   *
   * The posted rows are generated from the Smart Date rule, so any row whose
   * time-of-day differs from the rule's is drift — the state that silently
   * mis-times every booking made against it.
   *
   * @return array<int, array>
   *   One entry per drifting rule, keyed by rule id.
   */
  public function findScheduleDrift(): array {
    if (!$this->entityTypeManager->hasDefinition('smart_date_rule')) {
      return [];
    }

    $rule_ids = $this->entityTypeManager->getStorage('smart_date_rule')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_name', 'field_coordinator_hours')
      ->execute();
    if (!$rule_ids) {
      return [];
    }

    $timezone = new \DateTimeZone(date_default_timezone_get());
    $drift = [];
    foreach ($this->entityTypeManager->getStorage('smart_date_rule')->loadMultiple($rule_ids) as $rule) {
      $rule_start = (int) $rule->get('start')->value;
      if ($rule_start <= 0) {
        continue;
      }
      $entity = $rule->getParentEntity();
      if (!$entity || !$entity->hasField('field_coordinator_hours')) {
        continue;
      }

      $rule_time = (new \DateTimeImmutable('@' . $rule_start))->setTimezone($timezone)->format('H:i');
      // A facilitator moving a single week creates a Smart Date override for
      // that instance. Those are supposed to differ from the rule, so skip
      // them — otherwise every rescheduled shift reads as drift.
      $overridden = $this->getOverriddenIndexes((int) $rule->id());
      $mismatched = [];
      foreach ($entity->get('field_coordinator_hours') as $item) {
        $value = $item->getValue();
        if ((int) ($value['rrule'] ?? 0) !== (int) $rule->id()) {
          continue;
        }
        if (isset($overridden[(int) ($value['rrule_index'] ?? -1)])) {
          continue;
        }
        $row_time = (new \DateTimeImmutable('@' . $value['value']))->setTimezone($timezone)->format('H:i');
        if ($row_time !== $rule_time) {
          $mismatched[$row_time] = ($mismatched[$row_time] ?? 0) + 1;
        }
      }

      if ($mismatched) {
        $drift[(int) $rule->id()] = [
          'rule_id' => (int) $rule->id(),
          'uid' => (int) $entity->getOwnerId(),
          'rule_time' => $rule_time,
          'row_times' => $mismatched,
        ];
      }
    }

    return $drift;
  }

  /**
   * Returns the rrule_index values that carry a Smart Date override.
   *
   * @return array<int, true>
   *   Overridden indexes, for O(1) lookup.
   */
  protected function getOverriddenIndexes(int $rule_id): array {
    if (!$this->database->schema()->tableExists('smart_date_override')) {
      return [];
    }

    $indexes = $this->database->select('smart_date_override', 'o')
      ->fields('o', ['rrule_index'])
      ->condition('o.rrule', $rule_id)
      ->execute()
      ->fetchCol();

    return $indexes ? array_fill_keys(array_map('intval', $indexes), TRUE) : [];
  }

  /**
   * Returns the coordinator profile id for a user, or 0.
   */
  protected function getCoordinatorProfileId(int $uid): int {
    if (!$this->entityTypeManager->hasDefinition('profile')) {
      return 0;
    }

    $ids = $this->entityTypeManager->getStorage('profile')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('type', 'coordinator')
      ->range(0, 1)
      ->execute();

    return $ids ? (int) reset($ids) : 0;
  }

}
