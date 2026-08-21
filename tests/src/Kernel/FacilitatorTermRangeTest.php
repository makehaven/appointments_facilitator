<?php

namespace Drupal\Tests\appointment_facilitator\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\user\Entity\User;

/**
 * Covers how a facilitator's term is derived from their scheduled hours.
 *
 * The term lives in the Smart Date recurrence on field_coordinator_hours, but
 * SmartDateRule::start/::end describe the FIRST INSTANCE — a single shift —
 * not the rule's span. Reading ::end as the term end made every facilitator's
 * "current term" a one-day window on /facilitator/dashboard/stats. These tests
 * pin the corrected derivation: start from the rule, end from its limit, and
 * an honest "no end" for the majority of rules that recur indefinitely.
 *
 * @group appointment_facilitator
 */
class FacilitatorTermRangeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'smart_date',
    'smart_date_recur',
    'profile',
    'appointment_facilitator',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('smart_date_rule');
    $this->installEntitySchema('smart_date_override');
    $this->installSchema('system', ['sequences']);

    ProfileType::create([
      'id' => 'coordinator',
      'label' => 'Coordinator',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_coordinator_hours',
      'entity_type' => 'profile',
      'type' => 'smartdate',
      'cardinality' => -1,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_coordinator_hours',
      'entity_type' => 'profile',
      'bundle' => 'coordinator',
      'label' => 'Coordinator Hours',
      'third_party_settings' => [
        'smart_date_recur' => [
          'allow_recurring' => TRUE,
          'month_limit' => 12,
        ],
      ],
    ])->save();
  }

  /**
   * A rule bounded with UNTIL reports that date as the term end.
   */
  public function testUntilRuleGivesDatedTerm(): void {
    $start = strtotime('-4 months', $this->now());
    $until = date('Y-m-d', strtotime('+2 months', $this->now()));

    $term = $this->termFor('until_facilitator', $start, 'UNTIL=' . $until, FALSE);

    $this->assertNotNull($term);
    $this->assertFalse($term['open_ended'], 'A rule with UNTIL is not open-ended.');
    $this->assertSame($start, $term['start_ts'], 'Term start is the rule start.');
    $this->assertSame(
      date('Y-m-d', strtotime($until)),
      date('Y-m-d', $term['end_ts']),
      'Term end comes from the UNTIL date, not the first instance.'
    );
    $this->assertGreaterThan(
      86400 * 30,
      $term['end_ts'] - $term['start_ts'],
      'The term spans months, not a single shift.'
    );
  }

  /**
   * An unlimited rule has no term end and says so.
   */
  public function testUnlimitedRuleIsOpenEnded(): void {
    $start = strtotime('-6 months', $this->now());

    $term = $this->termFor('open_facilitator', $start, NULL, TRUE);

    $this->assertNotNull($term);
    $this->assertTrue($term['open_ended'], 'A rule with no limit is reported as open-ended.');
    $this->assertSame($start, $term['start_ts']);
    $this->assertGreaterThanOrEqual(
      $term['start_ts'],
      $term['effective_end_ts'],
      'An open-ended term is measured from its start through today.'
    );
    $this->assertGreaterThan(
      86400 * 30,
      $term['effective_end_ts'] - $term['start_ts'],
      'The counting window covers the whole run, not one shift.'
    );
  }

  /**
   * An open-ended rule starting in the future must not report a negative span.
   */
  public function testFutureOpenEndedTermNeverEndsBeforeItStarts(): void {
    $start = strtotime('+9 days', $this->now());

    $term = $this->termFor('future_facilitator', $start, NULL, TRUE);

    $this->assertNotNull($term);
    $this->assertTrue($term['open_ended']);
    $this->assertGreaterThanOrEqual(
      $term['start_ts'],
      $term['end_ts'],
      'A term that has not started yet cannot end before it begins.'
    );
    $this->assertGreaterThanOrEqual($term['start_ts'], $term['effective_end_ts']);
  }

  /**
   * Hours with no recurrence at all are flagged rather than shown as a term.
   */
  public function testSingleShiftIsNotPresentedAsTerm(): void {
    $user = $this->createFacilitator('oneoff_facilitator');
    $start = strtotime('-3 days', $this->now());

    $profile = Profile::create([
      'type' => 'coordinator',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $profile->set('field_coordinator_hours', [
      [
        'value' => $start,
        'end_value' => $start + 10800,
        'duration' => 180,
      ],
    ]);
    $profile->save();

    $term = \Drupal::service('appointment_facilitator.stats')
      ->getFacilitatorTermRange((int) $user->id());

    $this->assertNotNull($term);
    $this->assertTrue($term['single_shift'], 'Hours with no rule are flagged as a single shift.');
    $this->assertFalse($term['open_ended']);
  }

  /**
   * Builds a facilitator with one recurring rule and returns their term range.
   */
  protected function termFor(string $name, int $start, ?string $limit, bool $unlimited): ?array {
    $user = $this->createFacilitator($name);

    $rule = SmartDateRule::create([
      'freq' => 'WEEKLY',
      'limit' => $limit,
      'unlimited' => $unlimited ? 1 : 0,
      'entity_type' => 'profile',
      'bundle' => 'coordinator',
      'field_name' => 'field_coordinator_hours',
      // Deliberately a single 3-hour shift: this is exactly the pair the buggy
      // reader mistook for the whole term.
      'start' => $start,
      'end' => $start + 10800,
      'instances' => ['data' => []],
    ]);
    $rule->save();

    $profile = Profile::create([
      'type' => 'coordinator',
      'uid' => $user->id(),
      'status' => 1,
    ]);
    $profile->set('field_coordinator_hours', [
      [
        'value' => $start,
        'end_value' => $start + 10800,
        'duration' => 180,
        'rrule' => (int) $rule->id(),
        'rrule_index' => 0,
      ],
    ]);
    $profile->save();

    return \Drupal::service('appointment_facilitator.stats')
      ->getFacilitatorTermRange((int) $user->id());
  }

  /**
   * Creates a user to hang a coordinator profile from.
   */
  protected function createFacilitator(string $name): User {
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'status' => 1,
    ]);
    $user->save();

    return $user;
  }

  /**
   * The request time the service reads, so tests and code agree on "now".
   */
  protected function now(): int {
    return (int) \Drupal::time()->getRequestTime();
  }

}
