<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\appointment_facilitator\Service\ScheduleConsistencyChecker;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputOption;

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
      'since' => InputOption::VALUE_OPTIONAL,
      'until' => InputOption::VALUE_OPTIONAL,
      'include-canceled' => InputOption::VALUE_NONE,
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
