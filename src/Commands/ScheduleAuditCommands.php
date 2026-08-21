<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\appointment_facilitator\Service\ScheduleConsistencyChecker;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Audits appointment times against the hours their facilitator posted.
 */
class ScheduleAuditCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ScheduleConsistencyChecker $checker,
  ) {
    parent::__construct();
  }

  /**
   * Lists appointments whose stored time falls outside the host's posted hours.
   *
   * An appointment's time is derived from the facilitator's posted hours at
   * booking time and never re-checked. When those hours change — or drift from
   * the recurrence rule that generates them — existing appointments keep the
   * old time, so the schedule and the appointment disagree and staff cannot
   * tell which is right. This lists every disagreement.
   *
   * @command appointment-facilitator:audit-times
   * @option since Only appointments on or after this date (YYYY-MM-DD). Defaults to today.
   * @option until Only appointments on or before this date (YYYY-MM-DD).
   * @option include-canceled Include canceled appointments.
   * @usage drush appointment-facilitator:audit-times
   *   Upcoming appointments that disagree with their facilitator's hours.
   * @usage drush appointment-facilitator:audit-times --since=2026-01-01
   *   Audit the year so far, to size a historical discrepancy.
   */
  public function auditTimes(
    array $options = [
      'since' => NULL,
      'until' => NULL,
      // A boolean flag defaults to FALSE. InputOption::VALUE_NONE is the
      // integer 4, so using it here makes the flag permanently on — which,
      // on a writing command, silently turns a dry run into a live one.
      'include-canceled' => FALSE,
    ],
  ): int {
    $since = $options['since'] ?: date('Y-m-d');
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('field_appointment_date', $since, '>=')
      ->sort('field_appointment_date');
    if (!empty($options['until'])) {
      $query->condition('field_appointment_date', $options['until'], '<=');
    }
    $ids = $query->execute();
    if (!$ids) {
      $this->output()->writeln('No appointments in range.');
      return self::EXIT_SUCCESS;
    }

    $checked = 0;
    $rows = [];
    foreach (array_chunk($ids, 100) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if (!$node instanceof NodeInterface) {
          continue;
        }
        if (empty($options['include-canceled'])
          && $node->hasField('field_appointment_status')
          && (string) $node->get('field_appointment_status')->value === 'canceled') {
          continue;
        }

        $result = $this->checker->checkAppointment($node);
        $checked++;
        if ($result === NULL) {
          continue;
        }

        $host = $node->get('field_appointment_host')->entity;
        $shift = $result['shifts'][0];
        $offset = $result['offset_minutes'];
        $rows[] = sprintf(
          '%-7s %-18s appt %s–%s | posted %s–%s | %s | host %s | member %s',
          $node->id(),
          $node->get('field_appointment_date')->value,
          date('g:ia', $result['start']),
          date('g:ia', $result['end']),
          date('g:ia', $shift['start']),
          date('g:ia', $shift['end']),
          $offset === NULL ? 'offset ?' : sprintf('%+d min', $offset),
          $host ? $host->getDisplayName() : '?',
          $node->getOwner()->getDisplayName()
        );
      }
    }

    $this->output()->writeln(sprintf('Checked %d appointment(s) from %s.', $checked, $since));
    if (!$rows) {
      $this->output()->writeln('All checkable appointments sit inside their facilitator\'s posted hours.');
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln(sprintf('%d appointment(s) disagree with the posted hours:', count($rows)));
    foreach ($rows as $row) {
      $this->output()->writeln('  ' . $row);
    }
    $this->output()->writeln('');
    $this->output()->writeln('A consistent offset across one facilitator means their posted hours moved');
    $this->output()->writeln('after these were booked. Confirm the real time with the facilitator before');
    $this->output()->writeln('correcting anything — the appointment record may be the accurate one.');

    return self::EXIT_SUCCESS;
  }

  /**
   * Re-times appointments recorded outside their facilitator's posted hours.
   *
   * The stored time is rebuilt the way it should have been computed in the
   * first place: the posted shift start for that day plus the appointment's own
   * saved slot offsets. Nothing is inferred — an appointment is only touched
   * when its slots are known, the host posted exactly one shift that day, and
   * the recomputed range lands inside that shift. Everything else is reported
   * and left alone.
   *
   * Dry run by default. Saving re-syncs the linked tool reservation, so the
   * hold on the machine moves with the appointment.
   *
   * @command appointment-facilitator:retime-appointments
   * @option since Only appointments on or after this date (YYYY-MM-DD).
   * @option until Only appointments on or before this date (YYYY-MM-DD).
   * @option host Only appointments hosted by this user id.
   * @option apply Write the corrected times. Without it, nothing is saved.
   * @usage drush appointment-facilitator:retime-appointments --since=2026-01-01
   *   Show what would change.
   * @usage drush appointment-facilitator:retime-appointments --since=2026-01-01 --apply
   *   Correct them.
   */
  public function retimeAppointments(
    array $options = [
      'since' => NULL,
      'until' => NULL,
      'host' => NULL,
      'apply' => FALSE,
    ],
  ): int {
    $apply = !empty($options['apply']);
    $since = $options['since'] ?: date('Y-m-d');
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('field_appointment_date', $since, '>=')
      ->sort('field_appointment_date');
    if (!empty($options['until'])) {
      $query->condition('field_appointment_date', $options['until'], '<=');
    }
    if (!empty($options['host'])) {
      $query->condition('field_appointment_host', (int) $options['host']);
    }
    $ids = $query->execute();
    if (!$ids) {
      $this->output()->writeln('No appointments in range.');
      return self::EXIT_SUCCESS;
    }

    $offsets_map = _appointment_facilitator_slot_offset_map();
    $changed = 0;
    $skipped = [];

    foreach (array_chunk($ids, 100) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if (!$node instanceof NodeInterface) {
          continue;
        }
        $result = $this->checker->checkAppointment($node);
        if ($result === NULL) {
          continue;
        }

        if (count($result['shifts']) !== 1) {
          $skipped[] = $node->id() . ' — host posted ' . count($result['shifts']) . ' shifts that day; correct by hand';
          continue;
        }

        $offsets = [];
        foreach ($node->get('field_appointment_slot') as $slot) {
          if (isset($offsets_map[$slot->value])) {
            $offsets[] = $offsets_map[$slot->value];
          }
        }
        if (!$offsets) {
          $skipped[] = $node->id() . ' — no slot values stored; the original time cannot be rebuilt';
          continue;
        }

        sort($offsets);
        $duration = (max($offsets) - min($offsets)) + 30;
        $shift = $result['shifts'][0];
        $new_start = $shift['start'] + (min($offsets) * 60);
        $new_end = $new_start + ($duration * 60);

        if ($new_start < $shift['start'] || $new_end > $shift['end']) {
          $skipped[] = $node->id() . ' — rebuilt range ' . date('g:ia', $new_start) . '-' . date('g:ia', $new_end)
            . ' does not fit the posted shift; correct by hand';
          continue;
        }

        $this->output()->writeln(sprintf(
          '  %-7s %s  %s–%s  ->  %s–%s',
          $node->id(),
          $node->get('field_appointment_date')->value,
          date('g:ia', $result['start']),
          date('g:ia', $result['end']),
          date('g:ia', $new_start),
          date('g:ia', $new_end)
        ));

        if ($apply) {
          $item = $node->get('field_appointment_timerange')->first();
          $values = $item->getValue();
          $values['value'] = $new_start;
          $values['end_value'] = $new_end;
          if (array_key_exists('duration', $values)) {
            $values['duration'] = $duration;
          }
          $node->set('field_appointment_timerange', [$values]);
          $node->setNewRevision(FALSE);
          $node->save();
        }
        $changed++;
      }
    }

    if ($skipped) {
      $this->output()->writeln('');
      $this->output()->writeln('Skipped:');
      foreach ($skipped as $line) {
        $this->output()->writeln('  ' . $line);
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln($apply
      ? sprintf('Re-timed %d appointment(s). Linked tool reservations moved with them.', $changed)
      : sprintf('%d appointment(s) would be re-timed. Re-run with --apply to write.', $changed));

    if ($apply && $changed) {
      $this->output()->writeln('Arrival statuses were classified against the old times — re-run');
      $this->output()->writeln('appointment-facilitator:backfill-arrivals --force over the same range.');
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Reports facilitators whose posted hours disagree with their own rule.
   *
   * The posted rows are generated from the Smart Date rule. When they drift
   * from it, the booking links offer the drifted times and every appointment
   * booked against them is recorded at the wrong hour, with nothing to notice.
   *
   * @command appointment-facilitator:audit-schedule-drift
   * @usage drush appointment-facilitator:audit-schedule-drift
   */
  public function auditScheduleDrift(): int {
    $drift = $this->checker->findScheduleDrift();
    if (!$drift) {
      $this->output()->writeln('No drift: every posted shift matches the rule that generates it.');
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln(sprintf('%d facilitator schedule(s) drifted from their rule:', count($drift)));
    foreach ($drift as $entry) {
      $account = $this->entityTypeManager->getStorage('user')->load($entry['uid']);
      $times = [];
      foreach ($entry['row_times'] as $time => $count) {
        $times[] = $time . ' ×' . $count;
      }
      $this->output()->writeln(sprintf(
        '  rule %-5s %-24s rule says %s, posted rows say %s',
        $entry['rule_id'],
        $account ? $account->getDisplayName() : ('uid ' . $entry['uid']),
        $entry['rule_time'],
        implode(', ', $times)
      ));
    }
    $this->output()->writeln('');
    $this->output()->writeln('Re-run appointment_facilitator_update_9025 (or re-save the rule) to re-sync,');
    $this->output()->writeln('then run appointment-facilitator:audit-times — existing appointments keep');
    $this->output()->writeln('the old times and have to be corrected deliberately.');

    return self::EXIT_SUCCESS;
  }

}
