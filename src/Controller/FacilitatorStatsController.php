<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides self-serve stats for facilitators.
 */
class FacilitatorStatsController extends ControllerBase {

  protected const FEEDBACK_DELAY_DAYS = 30;

  protected const FEEDBACK_MINIMUM_COUNT = 3;

  protected const FEEDBACK_DISPLAY_LIMIT = 12;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManagerService,
    protected readonly EntityFieldManagerInterface $entityFieldManagerService,
    protected readonly DateFormatterInterface $dateFormatterService,
    protected readonly AppointmentStats $statsHelper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter'),
      $container->get('appointment_facilitator.stats'),
    );
  }

  /**
   * Displays facilitator self stats.
   */
  public function overview(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();

    $overall = $this->statsHelper->summarize(NULL, NULL, [
      'include_cancelled' => TRUE,
      'use_facilitator_terms' => TRUE,
    ]);
    $lifetime_summary = $this->statsHelper->summarize(NULL, NULL, [
      'host_id' => $uid,
      'include_cancelled' => TRUE,
      'use_facilitator_terms' => FALSE,
    ]);

    $facilitator = $overall['facilitators'][$uid] ?? $this->buildEmptyFacilitatorRow($uid);
    $lifetime = $lifetime_summary['facilitators'][$uid] ?? $this->buildEmptyFacilitatorRow($uid);
    $arrival_tracking_start = $overall['arrival_tracking_start'] ?? '2026-01-01 00:00:00';
    $arrival_tracking_start_label = $this->dateFormatterService->format(strtotime($arrival_tracking_start), 'custom', 'M j, Y');
    $term = NULL;
    if (
      $facilitator['term_start'] instanceof \DateTimeInterface
      && $facilitator['term_end'] instanceof \DateTimeInterface
    ) {
      $term = [
        'start' => $facilitator['term_start'],
        'end' => $facilitator['term_end'],
        'open_ended' => !empty($facilitator['term_open_ended']),
        'single_shift' => !empty($facilitator['term_single_shift']),
      ];
    }
    else {
      $term = $this->statsHelper->getFacilitatorTermRange($uid);
    }

    $summary_rows = [
      [$this->t('Appointments hosted'), $facilitator['appointments']],
      [$this->t('Attendees served'), $facilitator['attendees']],
      [$this->t('Badge sessions'), $facilitator['badge_sessions']],
      [$this->t('Badges selected'), $facilitator['badges']],
      [$this->t('Cancelled appointments'), $facilitator['cancelled']],
      [
        $this->t('Arrival days'),
        $facilitator['arrival_days'] === NULL
          ? $this->t('—')
          : $this->t('@count of @total', [
            '@count' => $facilitator['arrival_days'],
            '@total' => $facilitator['tracked_appointment_day_count'] ?? 0,
          ]),
      ],
      [
        $this->t('Arrival coverage'),
        $this->formatPercent($facilitator['arrival_rate']),
      ],
      [
        $this->t('Arrival status mix'),
        $this->formatArrivalStatusCounts(
          $facilitator['arrival_status_counts'] ?? [],
          $this->getAllowedValues('field_facilitator_arrival_status'),
        ),
      ],
      [
        $this->t('Evaluation completion'),
        $this->t('@rate (@count)', [
          '@rate' => $this->formatPercent($facilitator['feedback_rate']),
          '@count' => $facilitator['feedback'],
        ]),
      ],
      [$this->t('Appointments per week'), $this->formatRate($facilitator['appointments_per_week'])],
      [$this->t('Appointments per month'), $this->formatRate($facilitator['appointments_per_month'])],
    ];

    $term_value = $this->t('Not set');
    if ($term) {
      // Most facilitators' scheduled hours recur with no end date, so there is
      // no term end to print. Say so, and show the window the counts below
      // actually cover, rather than printing today as if it were a term end.
      if (!empty($term['single_shift'])) {
        // No recurring rule at all — one scheduled shift is not a term, and
        // printing it as "X to X" is what the original report looked like.
        $term_value = $this->t('No recurring schedule set — one shift on @start', [
          '@start' => $this->dateFormatterService->format($term['start']->getTimestamp(), 'custom', 'M j, Y'),
        ]);
      }
      elseif (empty($term['open_ended'])) {
        $term_value = $this->t('@start to @end', [
          '@start' => $this->dateFormatterService->format($term['start']->getTimestamp(), 'custom', 'M j, Y'),
          '@end' => $this->dateFormatterService->format($term['end']->getTimestamp(), 'custom', 'M j, Y'),
        ]);
      }
      else {
        // The recurrence has no end date, which is true of most facilitators'
        // hours — say so rather than printing today as if it were a term end.
        $term_value = $this->t('Ongoing since @start (no end date set) — counts below cover @start to today', [
          '@start' => $this->dateFormatterService->format($term['start']->getTimestamp(), 'custom', 'M j, Y'),
        ]);
      }
    }

    $benchmark_rows = $this->buildBenchmarkRows($uid, $overall['facilitators']);
    $lifetime_latest = $this->t('—');
    if ($lifetime['latest'] instanceof \DateTimeInterface) {
      $lifetime_latest = $this->dateFormatterService->format($lifetime['latest']->getTimestamp(), 'custom', 'M j, Y g:i a');
    }
    $comparison_chart = $this->buildComparisonChart($facilitator, $overall['facilitator_rate_averages'] ?? []);
    $purpose_source = $this->resolveChartCounts($facilitator['purpose_counts'] ?? [], $lifetime['purpose_counts'] ?? []);
    $result_source = $this->resolveChartCounts($facilitator['result_counts'] ?? [], $lifetime['result_counts'] ?? []);
    $purpose_chart = $this->buildDistributionChart(
      $purpose_source['counts'],
      $this->getAllowedValues('field_appointment_purpose'),
      (string) ($purpose_source['scope'] === 'current'
        ? $this->t('Your appointment purpose mix (current term)')
        : $this->t('Your appointment purpose mix (lifetime)')),
      '#0f766e',
      'donut'
    );
    $result_chart = $this->buildDistributionChart(
      $result_source['counts'],
      $this->getAllowedValues('field_appointment_result'),
      (string) ($result_source['scope'] === 'current'
        ? $this->t('Your outcome mix (current term)')
        : $this->t('Your outcome mix (lifetime)')),
      '#2563eb',
      'column'
    );
    $summary_tiles = [
      ['label' => $this->t('Appointments hosted'), 'value' => (string) $facilitator['appointments']],
      ['label' => $this->t('Attendees served'), 'value' => (string) $facilitator['attendees']],
      ['label' => $this->t('Badge sessions'), 'value' => (string) $facilitator['badge_sessions']],
      ['label' => $this->t('Badges selected'), 'value' => (string) $facilitator['badges']],
      ['label' => $this->t('Evaluation completion'), 'value' => (string) $this->t('@rate (@count)', ['@rate' => $this->formatPercent($facilitator['feedback_rate']), '@count' => $facilitator['feedback']])],
      ['label' => $this->t('Arrival coverage'), 'value' => $this->formatPercent($facilitator['arrival_rate'])],
      ['label' => $this->t('Appointments per week'), 'value' => $this->formatRate($facilitator['appointments_per_week'])],
      ['label' => $this->t('Appointments per month'), 'value' => $this->formatRate($facilitator['appointments_per_month'])],
    ];
    $lifetime_tiles = [
      ['label' => $this->t('Appointments hosted'), 'value' => (string) $lifetime['appointments']],
      ['label' => $this->t('Attendees served'), 'value' => (string) $lifetime['attendees']],
      ['label' => $this->t('Badge sessions'), 'value' => (string) $lifetime['badge_sessions']],
      ['label' => $this->t('Badges selected'), 'value' => (string) $lifetime['badges']],
      ['label' => $this->t('Cancelled appointments'), 'value' => (string) $lifetime['cancelled']],
      ['label' => $this->t('Arrival coverage'), 'value' => $this->formatPercent($lifetime['arrival_rate'])],
      ['label' => $this->t('Evaluation completion'), 'value' => (string) $this->t('@rate (@count)', ['@rate' => $this->formatPercent($lifetime['feedback_rate']), '@count' => $lifetime['feedback']])],
      ['label' => $this->t('Latest appointment'), 'value' => (string) $lifetime_latest],
    ];
    $benchmark_list = $this->buildBenchmarkList($benchmark_rows);

    $title = $this->t('Facilitator stats for @name', ['@name' => $account->getDisplayName()]);

    return [
      '#type' => 'container',
      '#title' => $title,
      '#attributes' => ['class' => ['appointment-facilitator-self-stats']],
      '#attached' => [
        'library' => [
          'appointment_facilitator/self_stats',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => [
          'node_list',
          'node_list:appointment',
          'config:appointment_facilitator.settings',
          'config:system.date',
          'access_control_log_list',
        ],
        'max-age' => 300,
      ],
      'grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['afs-grid']],
        'summary_card' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afs-card', 'afs-card-summary']],
          'summary' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Current term counts'),
          ],
          'summary_intro' => [
            '#markup' => '<p class="afs-card-intro">' . $this->t('A quick view of your active facilitator term performance. Arrival coverage reflects access-log based arrival tracking for appointments on or after @date.', [
              '@date' => $arrival_tracking_start_label,
            ]) . '</p>',
          ],
          'summary_tiles' => $this->buildMetricTileGrid($summary_tiles),
        ],
        'lifetime_card' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afs-card', 'afs-card-lifetime']],
          'lifetime' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Lifetime totals'),
          ],
          'lifetime_tiles' => $this->buildMetricTileGrid($lifetime_tiles),
        ],
        'term_card' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afs-card', 'afs-card-term']],
          'term' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Current term range'),
          ],
          'term_value' => [
            '#markup' => '<div class="afs-emphasis">' . $term_value . '</div>',
          ],
        ],
        'comparisons_card' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afs-card', 'afs-card-comparison']],
          'comparisons' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('You vs. org averages'),
          ],
          'comparisons_intro' => [
            '#markup' => '<p class="afs-card-intro">' . $this->t('Arrival coverage means the percentage of tracked appointment days where an arrival scan was detected. Tracking starts on @date.', [
              '@date' => $arrival_tracking_start_label,
            ]) . '</p>',
          ],
          'comparisons_chart' => $comparison_chart,
        ],
        'benchmark_card' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afs-card', 'afs-card-comparison']],
          'benchmarks' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('How you compare to other facilitators'),
          ],
          'benchmark_list' => $benchmark_list,
        ],
        'purpose_chart_card' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afs-card', 'afs-card-chart-half']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Purpose mix'),
          ],
          'chart' => $purpose_chart,
        ],
        'result_chart_card' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['afs-card', 'afs-card-chart-half']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $this->t('Outcome mix'),
          ],
          'chart' => $result_chart,
        ],
      ],
    ];
  }

  protected function buildEmptyFacilitatorRow(int $uid): array {
    return [
      'uid' => $uid,
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
      'appointment_day_count' => 0,
      'tracked_appointment_day_count' => 0,
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

  protected function formatRate($value): string {
    if ($value === NULL) {
      return (string) $this->t('—');
    }
    if ($value === 0 || $value === '0' || $value === 0.0) {
      return '0';
    }
    return (string) $value;
  }

  protected function formatPercent($value): string {
    if ($value === NULL) {
      return (string) $this->t('—');
    }
    $value = (float) $value;
    return $value . '%';
  }

  protected function formatArrivalStatusCounts(array $counts, array $labels): string {
    if (!$counts) {
      return (string) $this->t('—');
    }
    arsort($counts);
    $parts = [];
    foreach ($counts as $key => $value) {
      $label = $labels[$key] ?? NULL;
      if ($key === '_none' || $key === NULL || $key === '') {
        $label = $this->t('Not set');
      }
      elseif ($label === NULL) {
        $label = $this->humanizeMachineName($key);
      }
      $parts[] = $this->t('@count @label', ['@label' => $label, '@count' => $value]);
    }
    return implode(', ', $parts);
  }

  protected function buildBenchmarkRows(int $uid, array $facilitators): array {
    $tracked = array_filter($facilitators, static function (array $facilitator): bool {
      return !empty($facilitator['uid']);
    });
    $population = count($tracked);

    return [
      [
        $this->t('Appointments hosted rank'),
        $this->formatRank($this->calculateRank($uid, $tracked, static fn(array $row): ?float => (float) ($row['appointments'] ?? 0)), $population),
      ],
      [
        $this->t('Appointments per week rank'),
        $this->formatRank($this->calculateRank($uid, $tracked, static fn(array $row): ?float => isset($row['appointments_per_week']) ? (float) $row['appointments_per_week'] : NULL), $population),
      ],
      [
        $this->t('Evaluation completion rank'),
        $this->formatRank($this->calculateRank($uid, $tracked, static fn(array $row): ?float => !empty($row['appointments']) ? (float) ($row['feedback_rate'] ?? 0) : NULL), $population),
      ],
      [
        $this->t('Arrival coverage rank'),
        $this->formatRank($this->calculateRank($uid, $tracked, static fn(array $row): ?float => $row['arrival_rate'] !== NULL ? (float) $row['arrival_rate'] : NULL), $population),
      ],
    ];
  }

  protected function buildBenchmarkList(array $rows): array {
    $items = [];
    foreach ($rows as $row) {
      $items[] = Markup::create('<strong>' . Html::escape((string) $row[0]) . ':</strong> ' . Html::escape((string) $row[1]));
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['afs-benchmark-list']],
    ];
  }

  protected function calculateRank(int $uid, array $facilitators, callable $value_callback): ?array {
    $scores = [];
    foreach ($facilitators as $facilitator) {
      $score = $value_callback($facilitator);
      if ($score === NULL) {
        continue;
      }
      $scores[(int) $facilitator['uid']] = $score;
    }

    if (!isset($scores[$uid])) {
      return NULL;
    }

    arsort($scores, SORT_NUMERIC);
    $position = 1;
    foreach (array_keys($scores) as $score_uid) {
      if ((int) $score_uid === $uid) {
        return [
          'position' => $position,
          'total' => count($scores),
        ];
      }
      $position++;
    }

    return NULL;
  }

  protected function formatRank(?array $rank, int $population): string {
    if ($rank === NULL) {
      return (string) $this->t('—');
    }

    $percentile = $rank['total'] > 1
      ? (int) round((($rank['total'] - $rank['position']) / ($rank['total'] - 1)) * 100)
      : 100;

    return (string) $this->t('@position of @total (about @percentileth percentile)', [
      '@position' => $rank['position'],
      '@total' => $rank['total'] ?: $population,
      '@percentile' => $percentile,
    ]);
  }

  protected function loadAnonymizedFeedbackItems(int $uid): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $cutoff = \Drupal::time()->getRequestTime() - (self::FEEDBACK_DELAY_DAYS * 86400);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_host.target_id', $uid)
      ->exists('field_appointment_feedback.value')
      ->condition('field_appointment_feedback.value', '', '<>')
      ->sort('created', 'DESC');

    $nids = $query->execute();
    if (!$nids) {
      return [(string) $this->t('No delayed feedback is available yet.')];
    }

    $nodes = $storage->loadMultiple($nids);
    $items = [];
    foreach ($nodes as $node) {
      $feedback = trim((string) $node->get('field_appointment_feedback')->value);
      if ($feedback === '') {
        continue;
      }
      $appointment_ts = $this->extractAppointmentTimestamp($node);
      if ($appointment_ts !== NULL && $appointment_ts > $cutoff) {
        continue;
      }
      if ($appointment_ts === NULL && (int) $node->getCreatedTime() > $cutoff) {
        continue;
      }
      $items[] = [
        '#markup' => '<blockquote class="afs-feedback-quote">' . nl2br(Html::escape($feedback)) . '</blockquote>',
      ];
    }

    if (count($items) < self::FEEDBACK_MINIMUM_COUNT) {
      return [(string) $this->t('More delayed feedback is needed before anonymous comments can be shown.')];
    }

    $seed = ((int) floor(\Drupal::time()->getRequestTime() / 300)) + $uid;
    mt_srand($seed);
    shuffle($items);
    mt_srand();

    return array_slice($items, 0, self::FEEDBACK_DISPLAY_LIMIT);
  }

  protected function buildMetricTileGrid(array $tiles): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['afs-tile-grid']],
    ];

    foreach ($tiles as $delta => $tile) {
      $build['tile_' . $delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['afs-tile']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => (string) $tile['label'],
          '#attributes' => ['class' => ['afs-tile__label']],
        ],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => (string) $tile['value'],
          '#attributes' => ['class' => ['afs-tile__value']],
        ],
      ];
    }

    return $build;
  }

  protected function resolveChartCounts(array $current_counts, array $lifetime_counts): array {
    $current_filtered = $this->filterDistributionCounts($current_counts);
    if ($current_filtered) {
      return [
        'counts' => $current_counts,
        'scope' => 'current',
      ];
    }

    return [
      'counts' => $lifetime_counts,
      'scope' => 'lifetime',
    ];
  }

  protected function filterDistributionCounts(array $counts): array {
    return array_filter($counts, static function ($count, $key): bool {
      return $count > 0 && $key !== '_none';
    }, ARRAY_FILTER_USE_BOTH);
  }

  protected function buildComparisonChart(array $facilitator, array $averages): array {
    $labels = [
      (string) $this->t('Per week'),
      (string) $this->t('Per month'),
      (string) $this->t('Feedback %'),
      (string) $this->t('Arrival %'),
    ];

    return [
      '#type' => 'chart',
      '#chart_type' => 'column',
      '#chart_library' => 'chartjs',
      '#height' => 320,
      '#height_units' => 'px',
      'xaxis' => [
        '#type' => 'chart_xaxis',
        '#labels' => $labels,
      ],
      'yaxis' => [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Value'),
      ],
      'you' => [
        '#type' => 'chart_data',
        '#title' => $this->t('You'),
        '#data' => [
          (float) ($facilitator['appointments_per_week'] ?? 0),
          (float) ($facilitator['appointments_per_month'] ?? 0),
          (float) ($facilitator['feedback_rate'] ?? 0),
          (float) ($facilitator['arrival_rate'] ?? 0),
        ],
        '#color' => '#0f766e',
      ],
      'org' => [
        '#type' => 'chart_data',
        '#title' => $this->t('Org average'),
        '#data' => [
          (float) ($averages['appointments_per_week'] ?? 0),
          (float) ($averages['appointments_per_month'] ?? 0),
          (float) ($averages['feedback_rate'] ?? 0),
          (float) ($averages['arrival_rate'] ?? 0),
        ],
        '#color' => '#94a3b8',
      ],
    ];
  }

  protected function buildDistributionChart(array $counts, array $labels, string $title, string $color, string $chart_type = 'column'): array {
    $filtered = $this->filterDistributionCounts($counts);

    if (!$filtered) {
      return [
        '#markup' => '<p class="afs-empty-state">' . $this->t('No data yet for this chart.') . '</p>',
      ];
    }

    arsort($filtered);
    $filtered = array_slice($filtered, 0, 6, TRUE);
    $chart_labels = [];
    $chart_values = [];
    foreach ($filtered as $key => $count) {
      $chart_labels[] = $labels[$key] ?? $this->humanizeMachineName($key);
      $chart_values[] = (int) $count;
    }

    return [
      '#type' => 'chart',
      '#chart_type' => $chart_type,
      '#chart_library' => 'chartjs',
      '#height' => 320,
      '#height_units' => 'px',
      '#title' => $title,
      'xaxis' => [
        '#type' => 'chart_xaxis',
        '#labels' => $chart_labels,
      ],
      'series' => [
        '#type' => 'chart_data',
        '#title' => $this->t('Appointments'),
        '#data' => $chart_values,
        '#color' => $color,
      ],
    ];
  }

  protected function extractAppointmentTimestamp($node): ?int {
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      return (int) $node->get('field_appointment_timerange')->value;
    }

    if ($node->hasField('field_appointment_date') && !$node->get('field_appointment_date')->isEmpty()) {
      $value = (string) $node->get('field_appointment_date')->value;
      $date = \Drupal\Core\Datetime\DrupalDateTime::createFromFormat('Y-m-d', $value);
      return $date?->getTimestamp();
    }

    return NULL;
  }

  protected function getAllowedValues(string $field_name): array {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    if (!isset($definitions[$field_name])) {
      return [];
    }
    $settings = $definitions[$field_name]->getSetting('allowed_values') ?? [];
    $labels = [];
    foreach ($settings as $item) {
      if (is_array($item) && isset($item['value'])) {
        $labels[$item['value']] = $item['label'] ?? $item['value'];
      }
      elseif (is_string($item)) {
        $labels[$item] = $item;
      }
    }
    return $labels;
  }

  protected function fieldExists(string $field_name): bool {
    $definitions = $this->entityFieldManagerService->getFieldDefinitions('node', 'appointment');
    return isset($definitions[$field_name]);
  }

  protected function humanizeMachineName(?string $value): string {
    if ($value === NULL || $value === '' || $value === '_none') {
      return (string) $this->t('Not set');
    }
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', trim($value));
    return ucwords($value);
  }

}
