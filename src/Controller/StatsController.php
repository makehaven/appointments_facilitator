<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Form\StatsFilterForm;
use Drupal\appointment_facilitator\Service\AppointmentStats;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides administrative statistics for facilitator appointments.
 */
class StatsController extends ControllerBase {

  protected AppointmentStats $statsHelper;

  protected FormBuilderInterface $statsFormBuilder;

  protected EntityTypeManagerInterface $entityTypeManagerService;

  protected EntityFieldManagerInterface $entityFieldManagerService;

  protected DateFormatterInterface $dateFormatterService;

  protected RequestStack $requestStack;

  public function __construct(
    FormBuilderInterface $formBuilder,
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    DateFormatterInterface $dateFormatter,
    RequestStack $requestStack,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->statsFormBuilder = $formBuilder;
    $this->entityTypeManagerService = $entityTypeManager;
    $this->entityFieldManagerService = $entityFieldManager;
    $this->dateFormatterService = $dateFormatter;
    $this->requestStack = $requestStack;
    $this->statsHelper = new AppointmentStats($entityTypeManager, $entityFieldManager, $loggerFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Displays the stats dashboard.
   */
  public function overview(): array {
    $request = $this->requestStack->getCurrentRequest();
    $filters = $this->buildFilters($request);

    $summary = $this->statsHelper->summarize($filters['start'], $filters['end'], [
      'purpose' => $filters['purpose'] ?? NULL,
      'include_cancelled' => $filters['include_cancelled'] ?? FALSE,
    ]);

    $badge_labels = $this->loadBadgeLabels(array_keys($summary['badge_ids']));
    $user_labels = $this->loadUserLabels(array_keys($summary['facilitators']));
    $purpose_labels = $this->getAllowedValues('field_appointment_purpose');
    $result_labels = $this->getAllowedValues('field_appointment_result');
    $status_labels = $this->getAllowedValues('field_appointment_status');

    [$sort_key, $sort_direction] = $this->resolveSort($request);
    $header = $this->buildTableHeader($request, $sort_key, $sort_direction);
    $facilitators = $this->sortFacilitators($summary['facilitators'], $user_labels, $sort_key, $sort_direction);
    $rows = $this->buildTableRows($facilitators, $user_labels, $badge_labels, $purpose_labels, $result_labels, $status_labels);

    return [
      '#type' => 'container',
      'filter' => $this->statsFormBuilder->getForm(StatsFilterForm::class, $filters, $purpose_labels),
      'summary' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Summary'),
        '#items' => $this->buildSummaryItems($summary, $purpose_labels, $result_labels, $status_labels),
        '#attributes' => ['class' => ['appointment-facilitator-summary']],
      ],
      'definitions' => $this->buildDefinitions(),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No appointments found for the selected filters.'),
        '#attributes' => ['class' => ['appointment-facilitator-table']],
      ],
    ];
  }

  protected function buildDefinitions(): array {
    $items = [
      Markup::create($this->t('<strong>Badge sessions</strong>: Appointments where at least one badge was selected.')),
      Markup::create($this->t('<strong>Badges selected</strong>: Total number of badge selections across those appointments (one appointment can add several).')),
      Markup::create($this->t('<strong>Active days</strong>: Distinct calendar days with at least one appointment inside the current filters.')),
      Markup::create($this->t('<strong>Result mix (set)</strong>: Percentages ignore appointments without a recorded result; counts are shown in parentheses.')),
      Markup::create($this->t('<strong>Cancelled</strong>: Appointments whose status is <em>canceled</em>.')),
    ];

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('How to read this report'),
      '#items' => $items,
      '#attributes' => ['class' => ['appointment-facilitator-definitions']],
    ];
  }

  protected function buildFilters(Request $request): array {
    $filters = [];

    $start_input = $request->query->get('start');
    $end_input = $request->query->get('end');
    $purpose = $request->query->get('purpose');
    $include_cancelled = (bool) $request->query->get('include_cancelled');

    $start_date = $start_input ? $this->createDate($start_input . ' 00:00:00') : NULL;
    $end_date = $end_input ? $this->createDate($end_input . ' 23:59:59') : NULL;

    if ($start_date && $end_date && $start_date > $end_date) {
      $end_date = NULL;
    }

    $filters['start'] = $start_date;
    $filters['end'] = $end_date;

    if (!empty($purpose) && $purpose !== 'all') {
      $filters['purpose'] = $purpose;
    }

    $filters['raw'] = [
      'start' => $start_date ? $start_date->format('Y-m-d') : '',
      'end' => $end_date ? $end_date->format('Y-m-d') : '',
      'purpose' => $purpose ?: 'all',
      'include_cancelled' => $include_cancelled ? 1 : 0,
    ];

    $filters['include_cancelled'] = $include_cancelled;

    return $filters;
  }

  protected function createDate(string $value): ?DrupalDateTime {
    try {
      return new DrupalDateTime($value);
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->warning('Invalid date filter provided: @value', ['@value' => $value]);
      return NULL;
    }
  }

  protected function loadBadgeLabels(array $ids): array {
    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) {
      return [];
    }
    $terms = $this->entityTypeManagerService->getStorage('taxonomy_term')->loadMultiple($ids);
    $labels = [];
    foreach ($terms as $term) {
      $labels[$term->id()] = $term->label();
    }
    return $labels;
  }

  protected function loadUserLabels(array $uids): array {
    $uids = array_filter(array_map('intval', $uids));
    if (!$uids) {
      return [];
    }
    $accounts = $this->entityTypeManagerService->getStorage('user')->loadMultiple($uids);
    $labels = [];
    foreach ($accounts as $account) {
      $labels[$account->id()] = $account->getDisplayName();
    }
    return $labels;
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

  protected function resolveSort(Request $request): array {
    $sortable = ['name', 'appointments', 'badge_sessions', 'badges', 'appointment_day_count', 'cancelled', 'latest'];
    $sort = $request->query->get('sort');
    if (!in_array($sort, $sortable, TRUE)) {
      $sort = 'appointments';
    }

    $order = strtolower((string) $request->query->get('order', 'desc'));
    if (!in_array($order, ['asc', 'desc'], TRUE)) {
      $order = 'desc';
    }

    return [$sort, $order];
  }

  protected function buildTableHeader(Request $request, string $sort_key, string $sort_direction): array {
    $columns = [
      'name' => $this->t('Facilitator'),
      'appointments' => $this->t('Appointments'),
      'badge_sessions' => $this->t('Badge sessions'),
      'badges' => $this->t('Badges selected'),
      'appointment_day_count' => $this->t('Active days'),
      'cancelled' => $this->t('Cancelled'),
      'purpose' => $this->t('Purpose mix'),
      'result' => $this->t('Result mix'),
      'status' => $this->t('Status mix'),
      'top_badges' => $this->t('Top badges'),
      'latest' => $this->t('Latest appointment'),
    ];

    $sortable = ['name', 'appointments', 'badge_sessions', 'badges', 'appointment_day_count', 'cancelled'];

    $header = [];
    foreach ($columns as $key => $label) {
      if (in_array($key, $sortable, TRUE)) {
        $header[$key] = [
          'data' => $this->buildSortLink($label, $key, $sort_key, $sort_direction, $request),
        ];
      }
      else {
        $header[$key] = $label;
      }
    }

    return $header;
  }

  protected function buildSortLink($label, string $column, string $current_sort, string $current_order, Request $request): array {
    $query = $request->query->all();
    $new_order = ($current_sort === $column && $current_order === 'asc') ? 'desc' : 'asc';
    $query['sort'] = $column;
    $query['order'] = $new_order;

    $indicator = '';
    if ($current_sort === $column) {
      $indicator = $current_order === 'asc' ? ' ▲' : ' ▼';
    }

    $url = Url::fromRoute('<current>', [], ['query' => $query]);
    $link = Link::fromTextAndUrl($label . $indicator, $url);
    $render = $link->toRenderable();
    $render['#title'] = Markup::create($label . $indicator);
    return $render;
  }

  protected function sortFacilitators(array $facilitators, array $user_labels, string $sort_key, string $sort_direction): array {
    $records = array_values($facilitators);
    $multiplier = $sort_direction === 'asc' ? 1 : -1;

    usort($records, function (array $a, array $b) use ($sort_key, $multiplier, $user_labels) {
      switch ($sort_key) {
        case 'name':
          $name_a = $this->buildFacilitatorName($a['uid'], $user_labels);
          $name_b = $this->buildFacilitatorName($b['uid'], $user_labels);
          $comparison = strcasecmp($name_a, $name_b);
          break;

        case 'badge_sessions':
        case 'badges':
        case 'appointment_day_count':
        case 'cancelled':
        case 'appointments':
          $comparison = $a[$sort_key] <=> $b[$sort_key];
          break;

        case 'latest':
          $time_a = $a['latest'] instanceof \Drupal\Core\Datetime\DrupalDateTime ? $a['latest']->getTimestamp() : 0;
          $time_b = $b['latest'] instanceof \Drupal\Core\Datetime\DrupalDateTime ? $b['latest']->getTimestamp() : 0;
          $comparison = $time_a <=> $time_b;
          break;

        default:
          $comparison = 0;
      }

      if ($comparison === 0 && $sort_key !== 'appointments') {
        $comparison = $a['appointments'] <=> $b['appointments'];
      }
      if ($comparison === 0) {
        $comparison = strcmp((string) $a['uid'], (string) $b['uid']);
      }

      return $multiplier * $comparison;
    });

    return $records;
  }

  protected function buildFacilitatorName(int $uid, array $user_labels): string {
    if ($uid === 0) {
      return (string) $this->t('Unassigned');
    }
    return $user_labels[$uid] ?? (string) $this->t('User @uid', ['@uid' => $uid]);
  }

  protected function buildTableRows(array $facilitators, array $user_labels, array $badge_labels, array $purpose_labels, array $result_labels, array $status_labels): array {
    $rows = [];

    foreach ($facilitators as $data) {
      $name_render = $this->buildFacilitatorNameRenderable($data['uid'], $user_labels);

      $rows[] = [
        'name' => ['data' => $name_render],
        'appointments' => ['data' => $data['appointments']],
        'badge_sessions' => ['data' => $data['badge_sessions']],
        'badges' => ['data' => $data['badges']],
        'appointment_day_count' => ['data' => $data['appointment_day_count']],
        'cancelled' => ['data' => $data['cancelled']],
        'purpose' => ['data' => $this->formatDistributionList($data['purpose_counts'], $purpose_labels)],
        'result' => ['data' => $this->formatResultPercentages($data['result_counts'], $result_labels, TRUE)],
        'status' => ['data' => $this->formatDistributionList($data['status_counts'], $status_labels, TRUE)],
        'top_badges' => ['data' => $this->formatDistributionList($data['badges_breakdown'], $badge_labels)],
        'latest' => ['data' => $this->formatLatest($data['latest'])],
      ];
    }

    return $rows;
  }

  protected function formatLatest($latest): string {
    if ($latest instanceof \Drupal\Core\Datetime\DrupalDateTime) {
      return $this->dateFormatterService->format($latest->getTimestamp(), 'medium');
    }
    return (string) $this->t('—');
  }

  protected function buildFacilitatorNameRenderable(int $uid, array $user_labels): array {
    $name = $this->buildFacilitatorName($uid, $user_labels);

    if ($uid === 0) {
      return ['#markup' => $name];
    }

    $url = Url::fromRoute('entity.user.canonical', ['user' => $uid]);
    return [
      '#type' => 'link',
      '#title' => $name,
      '#url' => $url,
    ];
  }

  protected function buildSummaryItems(array $summary, array $purpose_labels, array $result_labels, array $status_labels): array {
    $items = [];
    $items[] = $this->t('Appointments: @count', ['@count' => $summary['total_appointments']]);
    $items[] = $this->t('Badge sessions: @count', ['@count' => $summary['total_badge_appointments']]);
    $items[] = $this->t('Badges selected: @count', ['@count' => $summary['total_badges']]);
    $items[] = $this->t('Cancelled appointments: @count', ['@count' => $summary['cancelled_total']]);

    if ($summary['purpose_totals']) {
      $items[] = Markup::create($this->t('Purpose mix: @list', ['@list' => $this->formatDistribution($summary['purpose_totals'], $purpose_labels)]));
    }
    if ($summary['result_totals']) {
      $items[] = Markup::create($this->t('Result mix (set): @list', ['@list' => $this->formatResultPercentages($summary['result_totals'], $result_labels)]));
    }
    if ($summary['status_totals']) {
      $items[] = Markup::create($this->t('Status mix: @list', ['@list' => $this->formatDistribution($summary['status_totals'], $status_labels)]));
    }

    return $items;
  }

  protected function formatResultPercentages(array $counts, array $labels, bool $as_list = FALSE): string|array {
    $filtered = array_filter($counts, static fn($value, $key) => $value > 0 && $key !== '_none', ARRAY_FILTER_USE_BOTH);
    if (!$filtered) {
      return (string) $this->t('—');
    }

    $total = array_sum($filtered);
    if ($total === 0) {
      return (string) $this->t('—');
    }

    arsort($filtered);
    $items = [];
    foreach ($filtered as $key => $value) {
      $label = $labels[$key] ?? $this->humanizeMachineName($key);
      $percentage = round(($value / $total) * 100, 1);
      $percentage = (float) $percentage;
      $items[] = $this->t('@percent% (@count) @label', [
        '@label' => $label,
        '@percent' => $percentage,
        '@count' => $value,
      ]);
    }

    if ($as_list) {
      return $this->renderList($items);
    }

    return implode(', ', array_map(static function ($item) {
      if (is_array($item) && isset($item['#markup'])) {
        return (string) $item['#markup'];
      }
      return (string) $item;
    }, $items));
  }

  protected function formatDistribution(array $counts, array $labels): string {
    if (!$counts) {
      return (string) $this->t('—');
    }
    arsort($counts);
    $pieces = [];
    foreach ($counts as $key => $value) {
      $label = $labels[$key] ?? NULL;

      if ($key === '_none' || $key === NULL || $key === '') {
        $label = $this->t('Not set');
      }
      elseif ($label === NULL) {
        $label = $this->humanizeMachineName($key);
      }

      $pieces[] = $this->t('@count @label', ['@label' => $label, '@count' => $value]);
    }
    return implode(', ', $pieces);
  }

  protected function formatDistributionList(array $counts, array $labels, bool $include_value = FALSE): array {
    if (!$counts) {
      return ['#markup' => $this->t('—')];
    }
    arsort($counts);
    $items = [];
    foreach ($counts as $key => $value) {
      $label = $labels[$key] ?? NULL;

      if ($key === '_none' || $key === NULL || $key === '') {
        $label = $this->t('Not set');
      }
      elseif ($label === NULL) {
        $label = $this->humanizeMachineName($key);
      }

      $text = $include_value
        ? $this->t('@count @label', ['@label' => $label, '@count' => $value])
        : $this->t('@label (@count)', ['@label' => $label, '@count' => $value]);

      $items[] = ['#markup' => $text];
    }

    return $this->renderList($items);
  }

  protected function formatTopBadges(array $badge_counts, array $badge_labels): array {
    if (!$badge_counts) {
      return ['#markup' => $this->t('—')];
    }
    arsort($badge_counts);
    $top = array_slice($badge_counts, 0, 3, TRUE);
    $items = [];
    foreach ($top as $tid => $count) {
      $label = $badge_labels[$tid] ?? $this->t('Badge @id', ['@id' => $tid]);
      $items[] = ['#markup' => $this->t('@count @label', ['@label' => $label, '@count' => $count])];
    }
    return $this->renderList($items);
  }

  protected function humanizeMachineName(?string $value): string {
    if ($value === NULL || $value === '' || $value === '_none') {
      return (string) $this->t('Not set');
    }
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', trim($value));
    return ucwords($value);
  }

  protected function renderList(array $items): array {
    if (!$items) {
      return ['#markup' => $this->t('—')];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => [
        'class' => ['appointment-facilitator-list', 'list-unstyled'],
      ],
      '#item_attributes' => ['class' => ['appointment-facilitator-list__item']],
    ];
  }

}
