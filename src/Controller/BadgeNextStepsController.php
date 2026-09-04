<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\appointment_facilitator\Service\BadgePrerequisiteGate;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays badge completion options for the current member.
 *
 * For each badge that is pending on the member's profile, the page shows —
 * in priority order — ways to complete it:
 *
 *  1. Join an existing upcoming appointment that includes this badge and still
 *     has open capacity (most efficient: the facilitator is already scheduled).
 *  2. Attend an upcoming CiviCRM event tagged with this badge.
 *  3. Book a new one-on-one session with a listed facilitator.
 *  4. Request a paid private session (fallback link to /private-class-inquiry).
 */
class BadgeNextStepsController extends ControllerBase {

  public function __construct(
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly BadgePrerequisiteGate $badgeGate,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('appointment_facilitator.badge_gate'),
    );
  }

  /**
   * Builds the badge next-steps page.
   */
  public function build(): array {
    $uid = (int) $this->currentUser()->id();
    $pending_tids = _appointment_facilitator_load_pending_badge_term_ids($uid);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-next-steps']],
      '#cache' => ['max-age' => 0],
    ];

    // Always show an intro so the page reads as guidance, not a dump of
    // pending records. Wording adapts to whether there are pending badges.
    $build['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--status', 'badge-next-steps__intro']],
      '#weight' => -100,
      'heading' => [
        '#markup' => '<p><strong>' . $this->t('Pick a badge and finish it.') . '</strong></p>',
      ],
      'body' => [
        '#markup' => '<p>' . $this->t('Below are any badges you have <em>started</em> (waiting on a checkout) plus a short list of badges we recommend next based on your areas of interest. Visit the badge page to watch the video, read the requirements, and take the quiz.') . '</p>',
      ],
    ];

    // Personal badge stats — total earned, rank among members, leaderboard
    // peek. Anonymous users skip this whole section.
    if ($uid > 0) {
      $stats = $this->buildPersonalStatsSection($uid);
      if ($stats) {
        $build['personal_stats'] = $stats;
      }
    }

    // Badges that unlock the tools the member starred. Higher signal than
    // area-of-interest recs because the member explicitly picked the tool.
    $exclude_tids = array_map('intval', $pending_tids);
    $exclude_tids = array_merge($exclude_tids, $this->loadActiveBadgeTids($uid));
    if ($uid > 0) {
      $starred = $this->buildStarredToolsRecommendations($uid, $exclude_tids);
      if ($starred) {
        $build['starred_recommendations'] = $starred;
      }
    }

    // Recommended next-up badges based on member areas of interest.
    $recommendations = $this->buildRecommendationsSection($uid, $exclude_tids);
    if ($recommendations) {
      $build['recommendations'] = $recommendations;
    }

    // Pending badges section.
    if (empty($pending_tids)) {
      $build['no_pending'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-next-steps__no-pending']],
        '#weight' => 0,
        'message' => [
          '#markup' => '<p>' . $this->t('You have no badges in progress right now. Pick one above (or browse <a href="@all">all badges</a>) to get started.', ['@all' => '/badges']) . '</p>',
        ],
      ];
      return $build;
    }

    $terms = $this->entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($pending_tids);

    // Load badge_request nodes to find when each badge became pending.
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $request_nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_status', 'pending')
      ->condition('field_badge_requested.target_id', array_values($pending_tids), 'IN')
      ->sort('created', 'ASC')
      ->execute();

    // Build tid → created timestamp map (earliest request per badge).
    $pending_since = [];
    if ($request_nids) {
      foreach ($node_storage->loadMultiple($request_nids) as $request) {
        $tid = (int) $request->get('field_badge_requested')->target_id;
        if (!isset($pending_since[$tid])) {
          $pending_since[$tid] = (int) $request->getCreatedTime();
        }
      }
    }

    $build['pending_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('In progress — finish your checkout'),
      '#attributes' => ['class' => ['badge-next-steps__heading']],
      '#weight' => -10,
    ];

    foreach ($terms as $tid => $term) {
      // Skip the paid-private-session fallback here — most members never use
      // it, and the individual badge term page still exposes it as needed.
      $build['badge_' . $tid] = $this->buildBadgeSection($term, $uid, $pending_since[$tid] ?? 0, FALSE, TRUE);
    }

    return $build;
  }

  /**
   * Builds the "Your badges" stats panel — total active badges, rank against
   * other members, and a small peek at the top earners. Excludes the
   * "Door" access badge (tid 1519) from counts since every member has it.
   */
  protected function buildPersonalStatsSection(int $uid): ?array {
    $db = \Drupal::database();
    $door_tid = 1519;

    // Count of distinct active equipment badges this user holds.
    $my_count = (int) $db->select('node__field_member_to_badge', 'm')
      ->fields('m')
      ->condition('m.field_member_to_badge_target_id', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
    // Actually scope to active equipment badges by joining:
    $q = $db->select('node__field_member_to_badge', 'm');
    $q->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $q->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $q->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $q->condition('n.type', 'badge_request');
    $q->condition('n.status', 1);
    $q->condition('s.field_badge_status_value', 'active');
    $q->condition('b.field_badge_requested_target_id', $door_tid, '<>');
    $q->condition('m.field_member_to_badge_target_id', $uid);
    $q->addExpression('COUNT(DISTINCT b.field_badge_requested_target_id)', 'c');
    $my_count = (int) $q->execute()->fetchField();

    // Per-user badge counts across all active members → derive rank + total.
    $sub = $db->select('node__field_member_to_badge', 'm');
    $sub->innerJoin('node__field_badge_status', 's', 's.entity_id = m.entity_id');
    $sub->innerJoin('node__field_badge_requested', 'b', 'b.entity_id = m.entity_id');
    $sub->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $sub->innerJoin('user__roles', 'r', 'r.entity_id = m.field_member_to_badge_target_id');
    $sub->innerJoin('users_field_data', 'u', 'u.uid = m.field_member_to_badge_target_id');
    $sub->condition('n.type', 'badge_request');
    $sub->condition('n.status', 1);
    $sub->condition('s.field_badge_status_value', 'active');
    $sub->condition('b.field_badge_requested_target_id', $door_tid, '<>');
    $sub->condition('r.roles_target_id', 'member');
    $sub->condition('u.status', 1);
    $sub->addField('m', 'field_member_to_badge_target_id', 'uid');
    $sub->addExpression('COUNT(DISTINCT b.field_badge_requested_target_id)', 'badge_count');
    $sub->groupBy('m.field_member_to_badge_target_id');
    $rows = $sub->execute()->fetchAll();

    $counts_by_uid = [];
    foreach ($rows as $row) {
      $counts_by_uid[(int) $row->uid] = (int) $row->badge_count;
    }

    // How many members have STRICTLY more badges than me → rank = that + 1.
    $rank = 1;
    foreach ($counts_by_uid as $count) {
      if ($count > $my_count) {
        $rank++;
      }
    }

    // Total members ranked is "members who hold ≥1 equipment badge" — the
    // honest denominator for the rank statement.
    $total_ranked = count($counts_by_uid);

    // Total active members (for context).
    $q3 = $db->select('user__roles', 'r');
    $q3->innerJoin('users_field_data', 'u', 'u.uid = r.entity_id');
    $q3->condition('r.roles_target_id', 'member');
    $q3->condition('u.status', 1);
    $q3->addExpression('COUNT(DISTINCT u.uid)', 'c');
    $total_members = (int) $q3->execute()->fetchField();

    // Bail if the user has no badges and nobody else does either.
    if ($my_count === 0 && $total_ranked === 0) {
      return NULL;
    }

    $facts = [];
    if ($my_count === 0) {
      $facts[] = $this->t("You haven't earned an equipment badge yet — pick one below to start.");
    }
    elseif ($my_count === 1) {
      $facts[] = $this->t('You hold <strong>1 equipment badge</strong>.');
    }
    else {
      $facts[] = $this->t('You hold <strong>@n equipment badges</strong>.', ['@n' => $my_count]);
    }

    if ($my_count > 0 && $total_ranked > 0) {
      $facts[] = $this->t(
        'Rank <strong>#@rank</strong> of @total members with badges (out of @members active members).',
        [
          '@rank' => $rank,
          '@total' => $total_ranked,
          '@members' => $total_members,
        ]
      );
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-personal-stats']],
      '#weight' => -90,
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#attributes' => ['class' => ['badge-personal-stats__heading']],
        '#value' => $this->t('Your badge progress'),
      ],
      'facts' => [
        '#theme' => 'item_list',
        '#items' => array_map(fn ($f) => ['#markup' => (string) $f], $facts),
        '#attributes' => ['class' => ['badge-personal-stats__list']],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:badge_request', 'user_list'],
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * Returns term IDs of badges the member currently holds (active status).
   */
  protected function loadActiveBadgeTids(int $uid): array {
    if ($uid <= 0) {
      return [];
    }
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_status', 'active')
      ->execute();
    if (!$nids) {
      return [];
    }
    $tids = [];
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($nids) as $request) {
      if ($request->hasField('field_badge_requested') && !$request->get('field_badge_requested')->isEmpty()) {
        $tids[] = (int) $request->get('field_badge_requested')->target_id;
      }
    }
    return array_values(array_unique($tids));
  }

  /**
   * Builds a "recommended next" section based on member areas of interest.
   *
   * Picks badges in the user's interest areas, excludes ones the user
   * already has or has in progress, and orders by prerequisite count
   * (fewer prereqs = more foundational) then alphabetically. Capped at
   * a small handful so the list reads as a suggestion, not a directory.
   */
  /**
   * Recommends badges that unlock the tools the member has starred via the
   * `tool_favorite` flag. Returns NULL when the member has no stars or
   * when all the derived badges are already held / in progress.
   *
   * Ordering: most-recently-starred tools first → their badges in turn.
   * Capped at 6 cards to match the area-of-interest section visually.
   */
  protected function buildStarredToolsRecommendations(int $uid, array $exclude_tids): ?array {
    $db = \Drupal::database();

    // Pull starred tool nids in reverse-chronological order so the freshest
    // signal drives the top of the list.
    $q = $db->select('flagging', 'f');
    $q->condition('f.flag_id', 'tool_favorite');
    $q->condition('f.uid', $uid);
    $q->fields('f', ['entity_id']);
    $q->orderBy('f.created', 'DESC');
    $tool_nids = $q->execute()->fetchCol();
    if (!$tool_nids) {
      return NULL;
    }

    $node_storage = $this->entityTypeManager()->getStorage('node');
    $tools = $node_storage->loadMultiple($tool_nids);
    // Preserve query order; loadMultiple may rearrange.
    $tools_ordered = [];
    foreach ($tool_nids as $nid) {
      if (isset($tools[$nid])) {
        $tools_ordered[$nid] = $tools[$nid];
      }
    }

    $exclude_lookup = array_flip(array_map('intval', $exclude_tids));
    $candidate_tids = [];
    foreach ($tools_ordered as $tool) {
      if (!$tool instanceof NodeInterface || $tool->bundle() !== 'item') {
        continue;
      }
      foreach (['field_member_badges', 'field_additional_badges'] as $badge_field) {
        if (!$tool->hasField($badge_field) || $tool->get($badge_field)->isEmpty()) {
          continue;
        }
        foreach ($tool->get($badge_field)->getValue() as $val) {
          $tid = (int) ($val['target_id'] ?? 0);
          if ($tid <= 0 || isset($exclude_lookup[$tid]) || isset($candidate_tids[$tid])) {
            continue;
          }
          $candidate_tids[$tid] = $tid;
        }
      }
    }
    if (!$candidate_tids) {
      return NULL;
    }

    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')
      ->loadMultiple($candidate_tids);

    // Drop inactive/unlisted badges.
    $terms = array_filter($terms, function (TermInterface $t): bool {
      if ($t->hasField('field_badge_inactive') && !$t->get('field_badge_inactive')->isEmpty() && (bool) $t->get('field_badge_inactive')->value) {
        return FALSE;
      }
      if ($t->hasField('field_badge_unlisted') && !$t->get('field_badge_unlisted')->isEmpty() && (bool) $t->get('field_badge_unlisted')->value) {
        return FALSE;
      }
      return TRUE;
    });

    if (!$terms) {
      return NULL;
    }

    // Preserve candidate order (most-recently-starred-tool first). Cap at 6.
    $ordered = [];
    foreach ($candidate_tids as $tid) {
      if (isset($terms[$tid])) {
        $ordered[$tid] = $terms[$tid];
      }
    }
    $ordered = array_slice($ordered, 0, 6, TRUE);

    $items = [];
    foreach ($ordered as $term) {
      $items[] = [
        '#type' => 'link',
        '#title' => $term->label(),
        '#url' => $term->toUrl(),
        '#attributes' => ['class' => ['badge-next-steps__rec-link']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-next-steps__recommendations', 'badge-next-steps__recommendations--starred']],
      '#weight' => -60,
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Earn these to unlock the tools you starred'),
        '#attributes' => ['class' => ['badge-next-steps__heading']],
      ],
      'subhead' => [
        '#markup' => '<p>' . $this->t('You starred these tools — these badges unlock them. Click any badge to see how to earn it.') . '</p>',
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['badge-next-steps__rec-list']],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:badge_request', 'flagging_list', 'taxonomy_term_list'],
        'max-age' => 3600,
      ],
    ];
  }

  protected function buildRecommendationsSection(int $uid, array $exclude_tids): ?array {
    if ($uid <= 0) {
      return NULL;
    }

    $interest_tids = $this->loadMemberInterestAreaTids($uid);
    if (!$interest_tids) {
      return NULL;
    }

    // Badge → area is derived via items, not directly on the taxonomy term.
    // (item node → field_item_area_interest → area_of_interest term; the item
    // also references its badges via field_member_badges / field_additional_badges.)
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $item_nids = $node_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'item')
      ->condition('status', 1)
      ->condition('field_item_area_interest', $interest_tids, 'IN')
      ->execute();
    if (!$item_nids) {
      return NULL;
    }

    $candidate_tids = [];
    foreach ($node_storage->loadMultiple($item_nids) as $item) {
      foreach (['field_member_badges', 'field_additional_badges'] as $badge_field) {
        if (!$item->hasField($badge_field)) {
          continue;
        }
        foreach ($item->get($badge_field)->getValue() as $val) {
          $tid = (int) ($val['target_id'] ?? 0);
          if ($tid > 0) {
            $candidate_tids[$tid] = $tid;
          }
        }
      }
    }
    if (!$candidate_tids) {
      return NULL;
    }

    $exclude_lookup = array_flip(array_map('intval', $exclude_tids));
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')
      ->loadMultiple(array_diff_key($candidate_tids, $exclude_lookup));

    if (!$terms) {
      return NULL;
    }

    // Filter out inactive/unlisted badges.
    $terms = array_filter($terms, function (TermInterface $t): bool {
      if ($t->hasField('field_badge_inactive') && !$t->get('field_badge_inactive')->isEmpty()) {
        if ((bool) $t->get('field_badge_inactive')->value) {
          return FALSE;
        }
      }
      if ($t->hasField('field_badge_unlisted') && !$t->get('field_badge_unlisted')->isEmpty()) {
        if ((bool) $t->get('field_badge_unlisted')->value) {
          return FALSE;
        }
      }
      return TRUE;
    });

    if (!$terms) {
      return NULL;
    }

    // Sort: prereq count ASC (foundational first), then alphabetically.
    uasort($terms, function (TermInterface $a, TermInterface $b): int {
      $ac = $a->hasField('field_badge_prerequisite') ? $a->get('field_badge_prerequisite')->count() : 0;
      $bc = $b->hasField('field_badge_prerequisite') ? $b->get('field_badge_prerequisite')->count() : 0;
      if ($ac !== $bc) {
        return $ac <=> $bc;
      }
      return strcasecmp((string) $a->label(), (string) $b->label());
    });

    $terms = array_slice($terms, 0, 6, TRUE);

    $items = [];
    foreach ($terms as $term) {
      $items[] = [
        '#type' => 'link',
        '#title' => $term->label(),
        '#url' => $term->toUrl(),
        '#attributes' => ['class' => ['badge-next-steps__rec-link']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-next-steps__recommendations']],
      '#weight' => -50,
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Recommended next — based on your interests'),
        '#attributes' => ['class' => ['badge-next-steps__heading']],
      ],
      'subhead' => [
        '#markup' => '<p>' . $this->t('Foundational badges in your interest areas come first. Click any badge to see how to earn it.') . '</p>',
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['badge-next-steps__rec-list']],
      ],
    ];
  }

  /**
   * Reads the member's area-of-interest taxonomy term IDs from their main
   * profile (field_member_areas_interest).
   */
  protected function loadMemberInterestAreaTids(int $uid): array {
    if ($uid <= 0 || !$this->entityTypeManager()->hasDefinition('profile')) {
      return [];
    }
    $profile_storage = $this->entityTypeManager()->getStorage('profile');
    $profile_ids = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('type', 'main')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$profile_ids) {
      return [];
    }
    $profile = $profile_storage->load(reset($profile_ids));
    if (!$profile || !$profile->hasField('field_member_areas_interest') || $profile->get('field_member_areas_interest')->isEmpty()) {
      return [];
    }
    $tids = [];
    foreach ($profile->get('field_member_areas_interest')->getValue() as $item) {
      $tids[] = (int) ($item['target_id'] ?? 0);
    }
    return array_values(array_unique(array_filter($tids)));
  }

  /**
   * Checks if the tool(s) required for this badge are currently offline.
   *
   * A badge is considered "offline" if it has associated items and ALL of those
   * items have an "offline" status (i.e., not Operational or Degraded). If no
   * items are linked to the badge, it is assumed to be "online".
   */
  protected function isBadgeOffline(TermInterface $term): bool {
    return $this->badgeGate->isBadgeOffline($term);
  }

  /**
   * Builds a schedule-first section for a single badge term page.
   *
   * Only returns content when the member has this specific badge in pending
   * state, matching the gating used by /badges/complete.
   */
  public function buildScheduleSectionForBadgeTerm(TermInterface $term, int $uid): array {
    if ($uid <= 0) {
      return [];
    }

    $pending_tids = _appointment_facilitator_load_pending_badge_term_ids($uid);
    if (!in_array((int) $term->id(), $pending_tids, TRUE)) {
      return [];
    }

    $pending_since = 0;
    $request_nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_status', 'pending')
      ->condition('field_badge_requested.target_id', (int) $term->id())
      ->sort('created', 'ASC')
      ->range(0, 1)
      ->execute();
    if ($request_nids) {
      $request = $this->entityTypeManager()->getStorage('node')->load(reset($request_nids));
      if ($request) {
        $pending_since = (int) $request->getCreatedTime();
      }
    }

    // The badge term page shows the schedule EVA + the facilitator cards
    // below; the paid private-session option is rendered separately at the
    // bottom by appointment_facilitator_entity_view() so it doesn't compete
    // with the primary booking options at the top.
    $section = $this->buildBadgeSection($term, $uid, $pending_since, FALSE);
    unset($section['heading']);
    $section['#attributes']['class'][] = 'badge-next-step-card--term-page';
    return $section;
  }

  /**
   * Builds the standalone paid-private-session fallback link.
   *
   * Used by the badge term page to position the link far down the page,
   * away from the primary booking actions.
   */
  public function buildPaidPrivateSessionFallback(TermInterface $term): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-option', 'badge-option--private', 'badge-option--secondary']],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['badge-private-desc']],
        '#value' => $this->t("Can't make any of the options above? A paid private session is also available."),
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Request a paid private session'),
        '#url' => Url::fromUri('internal:/private-class-inquiry'),
        '#attributes' => ['class' => ['badge-private-link']],
      ],
    ];
  }

  /**
   * Builds only the schedule-table portion for a badge term.
   *
   * This is used by other pages (e.g. quiz result pages) that want the
   * schedule matrix above legacy facilitator listings.
   *
   * @param bool $is_gated
   *   When TRUE, the badge page is showing this grid as a *preview* (member
   *   hasn't cleared prereqs yet). The Schedule buttons are rendered as
   *   non-actionable disabled spans so members can see availability without
   *   accidentally arriving at a booking form that would 403/400 on them.
   */
  public function buildScheduleTableForBadgeTerm(TermInterface $term, bool $is_gated = FALSE): array {
    if ($this->isBadgeOffline($term)) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error', 'badge-offline-notice']],
        'message' => [
          '#markup' => '<p>' . $this->t('<strong>Scheduling currently unavailable:</strong> The tool(s) required for this badge checkout are currently marked as offline for maintenance. Please check back later.') . '</p>',
        ],
      ];
    }

    $facilitators = $this->getFacilitatorsForBadge($term);
    if (!$facilitators) {
      return [];
    }
    return $this->buildFacilitatorScheduleTable($facilitators, (int) $term->id(), $is_gated);
  }

  /**
   * Builds the card for a single pending badge.
   *
   * @param bool $include_private_session
   *   When TRUE, append the paid-private-session fallback option. Used on
   *   individual badge term pages where it sits as a last-resort link.
   *   Set FALSE on /badges/complete because most members never need it and
   *   it pulls attention away from the actual next step.
   */
  protected function buildBadgeSection(TermInterface $term, int $uid, int $pending_since = 0, bool $include_private_session = TRUE, bool $show_schedule_cta = FALSE): array {
    $tid = (int) $term->id();
    $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);

    // Build the "pending for X days" notice.
    $now = \Drupal::time()->getRequestTime();
    $days = $pending_since > 0 ? (int) floor(($now - $pending_since) / 86400) : 0;
    if ($days === 0) {
      $age_text = $this->t('marked pending today');
    }
    elseif ($days === 1) {
      $age_text = $this->t('pending for 1 day');
    }
    else {
      $age_text = $this->t('pending for @days days', ['@days' => $days]);
    }
    $notice_text = $this->t(
      'This badge has been @age — choose a time below to complete checkout and activate it.',
      ['@age' => $age_text]
    );

    $section = [
      '#type' => 'container',
      '#attributes' => ['class' => ['badge-next-step-card']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#attributes' => ['class' => ['badge-card-title']],
        'link' => [
          '#type' => 'link',
          '#title' => $term->label(),
          '#url' => $badge_url,
        ],
      ],
      'notice' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $notice_text,
        '#attributes' => ['class' => ['badge-pending-notice']],
      ],
      'options' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-options']],
      ],
    ];

    // --- Tool offline check ---
    if ($this->isBadgeOffline($term)) {
      $section['options']['offline'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error', 'badge-offline-notice']],
        'message' => [
          '#markup' => '<p>' . $this->t('<strong>Scheduling currently unavailable:</strong> The tool(s) required for this badge checkout are currently marked as offline for maintenance. Please check back later when the tool status is restored to Operational.') . '</p>',
        ],
      ];
      return $section;
    }

    // Primary CTA to the badge page — only shown when the caller asked for it
    // (i.e. /badges/complete cards). Skipped on the badge term page itself
    // where the heading already links to the badge.
    if ($show_schedule_cta) {
      $section['primary_cta'] = [
        '#type' => 'link',
        '#title' => $this->t('Schedule'),
        '#url' => $badge_url,
        '#attributes' => [
          'class' => ['button', 'button--primary', 'badge-next-step-cta'],
          'aria-label' => $this->t('Open @badge badge page to schedule a checkout', ['@badge' => $term->label()]),
        ],
        // Place between the pending notice (default weight) and the options
        // container so it reads as the primary action.
        '#weight' => 5,
      ];
      // The options container needs a higher weight to render below the CTA.
      $section['options']['#weight'] = 10;
    }

    // --- Already scheduled? Show when and skip all booking options ---
    $existing = $this->findExistingAppointmentForBadge($tid, $uid);
    if ($existing) {
      // Member already has a session booked; no need to push them back to the
      // badge page to schedule again.
      unset($section['primary_cta']);
      $ts = (int) $existing->get('field_appointment_timerange')->value;
      $date_str = $this->dateFormatter->format($ts, 'custom', 'l, F j \a\t g:ia');
      $node_url = $existing->toUrl();
      $section['options']['scheduled'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--scheduled']],
        'message' => [
          '#markup' => '<p class="badge-scheduled-notice">'
            . $this->t('You are already scheduled for a session on @date.', ['@date' => $date_str])
            . ' </p>',
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('View appointment'),
          '#url' => $node_url,
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
      ];
      return $section;
    }

    $has_options = FALSE;

    // --- Priority 1: join an existing open session ---
    $open_appointments = $this->findOpenAppointments($tid, $uid);
    if ($open_appointments) {
      $has_options = TRUE;
      $rows = [];
      foreach ($open_appointments as $node) {
        $rows[] = $this->buildAppointmentRow($node, $uid);
      }
      $section['options']['join'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--join']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Next available sessions'),
        ],
        'note' => ['#markup' => '<p class="badge-option-note">' . $this->t('Fastest path: join an existing open session.') . '</p>'],
        'slots' => $rows,
      ];
    }

    // --- Priority 2: upcoming events ---
    $events = $this->findUpcomingEvents($tid);
    if ($events) {
      $has_options = TRUE;
      $items = [];
      foreach ($events as $event) {
        $item = $this->buildEventItem($event);
        if ($item) {
          $items[] = $item;
        }
      }
      if ($items) {
        $section['options']['events'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['badge-option', 'badge-option--event']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Upcoming classes'),
          ],
          'items' => $items,
        ];
      }
    }

    // --- Priority 3: book a new facilitator session ---
    $facilitators = $this->getFacilitatorsForBadge($term);
    if ($facilitators) {
      $has_options = TRUE;
      $schedule = $this->buildFacilitatorScheduleTable($facilitators, $tid);
      $section['options']['book'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--book']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Book a new session time'),
        ],
        'table' => $schedule,
      ];
    }

    // --- No current options ---
    if (!$has_options) {
      $section['options']['none'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--none']],
        '#markup' => '<p>' . $this->t('No sessions or events are currently scheduled for this badge.') . '</p>',
      ];
    }

    // --- Paid private-session fallback (badge term page only) ---
    // Most members never use this; we keep it visible on the badge term
    // page as a true last-resort, sitting under the other options with a
    // small label that doesn't compete with the primary CTAs.
    if ($include_private_session) {
      $section['options']['private'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['badge-option', 'badge-option--private', 'badge-option--secondary']],
        '#weight' => 100,
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['badge-private-desc']],
          '#value' => $this->t("Can't make any of these times? A paid private session is also available."),
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Request a paid private session'),
          '#url' => Url::fromUri('internal:/private-class-inquiry'),
          '#attributes' => ['class' => ['badge-private-link']],
        ],
      ];
    }

    return $section;
  }

  /**
   * Finds upcoming appointment nodes for a badge that have open capacity.
   *
   * Excludes sessions the current user has already joined — there is no point
   * offering to join something they are already part of. Only looks within the
   * current scheduling window (14 days).
   */
  protected function findOpenAppointments(int $badge_tid, int $uid): array {
    $now = \Drupal::time()->getRequestTime();
    // 14-day window: badges whose facilitators run sparse (biweekly/monthly)
    // availability were showing "no slots" when the next slot was 8+ days out
    // (cycle review 2026-06-15). Match the facilitator_schedules view window.
    $week_ahead = $now + (14 * 24 * 60 * 60);

    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('status', 1)
      ->condition('field_appointment_badges.target_id', $badge_tid)
      ->condition('field_appointment_timerange.value', $now, '>=')
      ->condition('field_appointment_timerange.value', $week_ahead, '<=')
      ->condition('field_appointment_status', 'canceled', '!=')
      ->sort('field_appointment_timerange.value', 'ASC')
      ->range(0, 10);

    $nids = $query->execute();
    if (!$nids) {
      return [];
    }

    $open = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $capacity = appointment_facilitator_effective_capacity($node);
      // Only surface group sessions (capacity > 1). Capacity-1 appointments
      // are 1-on-1s that must be freshly booked; the JoinAppointmentForm also
      // returns empty for them, so linking there would show a blank page.
      if ($capacity <= 1) {
        continue;
      }
      $attendee_values = $node->get('field_appointment_attendees')->getValue();
      // Skip sessions the user already joined — no point listing them.
      $already_joined = FALSE;
      foreach ($attendee_values as $item) {
        if ((int) ($item['target_id'] ?? 0) === $uid) {
          $already_joined = TRUE;
          break;
        }
      }
      if ($already_joined) {
        continue;
      }
      if (count($attendee_values) < $capacity) {
        $open[] = $node;
      }
    }
    return $open;
  }

  /**
   * Returns the earliest upcoming appointment for a badge that this user is
   * already attending (as host or attendee), if one exists.
   */
  protected function findExistingAppointmentForBadge(int $badge_tid, int $uid): ?NodeInterface {
    $now = \Drupal::time()->getRequestTime();
    $storage = $this->entityTypeManager()->getStorage('node');

    // Check appointments where the user is the host.
    foreach (['field_appointment_host', 'field_appointment_attendees'] as $field) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'appointment')
        ->condition('status', 1)
        ->condition('field_appointment_badges.target_id', $badge_tid)
        ->condition('field_appointment_timerange.value', $now, '>=')
        ->condition('field_appointment_status', 'canceled', '!=')
        ->condition($field . '.target_id', $uid)
        ->sort('field_appointment_timerange.value', 'ASC')
        ->range(0, 1);

      $nids = $query->execute();
      if ($nids) {
        $nodes = $storage->loadMultiple($nids);
        return reset($nodes) ?: NULL;
      }
    }

    return NULL;
  }

  /**
   * Finds upcoming CiviCRM events tagged with a badge.
   */
  protected function findUpcomingEvents(int $badge_tid): array {
    if (!$this->moduleHandler()->moduleExists('civicrm_entity')) {
      return [];
    }

    // The badge→event link is a Drupal field on the civicrm_event entity, and
    // an entity query conditioned on it returns nothing (civicrm_entity only
    // translates base-field conditions), so this list was always empty. Read
    // the field table, then filter the candidates on the CiviCRM side.
    try {
      $tagged = \Drupal::database()->select('civicrm_event__field_civi_event_badges', 'eb')
        ->fields('eb', ['entity_id'])
        ->condition('eb.field_civi_event_badges_target_id', $badge_tid)
        ->condition('eb.deleted', 0)
        ->execute()
        ->fetchCol();
      if (!$tagged) {
        return [];
      }
      $ids = $this->entityTypeManager()->getStorage('civicrm_event')->getQuery()
        ->accessCheck(FALSE)
        ->condition('id', array_map('intval', $tagged), 'IN')
        ->condition('start_date', date('Y-m-d H:i:s'), '>=')
        ->condition('is_active', 1)
        ->sort('start_date', 'ASC')
        ->range(0, 5)
        ->execute();
      if (!$ids) {
        return [];
      }
      return $this->entityTypeManager()->getStorage('civicrm_event')->loadMultiple($ids);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Returns facilitators with upcoming availability for a badge.
   *
   * Resolves badge issuers (on-request first, then all issuers), loads each
   * facilitator's coordinator profile, and keeps only those who have at least
   * one field_coordinator_hours slot within the next week (matching the
   * /facilitator/schedules view: now-2h to now+7d). Excludes facilitators
   * whose field_coordinator_hours_display is set to 'hide'.
   *
   * Returns an array of ['user', 'availability'] entries.
   */
  protected function getFacilitatorsForBadge(TermInterface $term): array {
    // Union BOTH issuer fields, deduped by uid. The previous "prefer
    // on-request, else fall back to all issuers" logic silently dropped
    // every trained facilitator on tools where only a couple of people
    // had toggled the on-request flag — e.g. table-saw has 14 issuers
    // and 1 on-request entry (currently on break), and that lone entry
    // was hiding the other 13's published hours from the schedule grid.
    $users_by_uid = [];
    foreach (['field_badge_issuer', 'field_badge_issuer_on_request'] as $field) {
      if (!$term->hasField($field) || $term->get($field)->isEmpty()) {
        continue;
      }
      foreach ($term->get($field)->referencedEntities() as $user) {
        $uid = (int) $user->id();
        if (!isset($users_by_uid[$uid])) {
          $users_by_uid[$uid] = $user;
        }
      }
    }
    $users = array_values($users_by_uid);
    if (!$users) {
      return [];
    }

    $bundle = \Drupal::config('appointment_facilitator.settings')
      ->get('facilitator_profile_bundle') ?: 'coordinator';
    $profile_storage = $this->entityTypeManager()->getStorage('profile');

    $now = \Drupal::time()->getRequestTime();
    // Match /facilitator/schedules view window: 2 hours ago → 2 weeks ahead.
    // Widened from 1 week so sparse-availability badges (next slot 8+ days out)
    // don't show "no slots available" (cycle review 2026-06-15).
    $window_start = $now - (2 * 60 * 60);
    $window_end   = $now + (14 * 24 * 60 * 60);

    $available = [];
    foreach ($users as $user) {
      // Match the legacy facilitator card list behavior: only users with the
      // facilitator role should appear in scheduling options.
      if (!$user->hasRole('facilitator')) {
        continue;
      }

      $profiles = $profile_storage->loadByUser($user, $bundle);
      $profile = is_array($profiles) ? reset($profiles) : $profiles;
      if (!$profile) {
        continue;
      }

      // Skip if explicitly hidden from schedules.
      if ($profile->hasField('field_coordinator_hours_display')
        && !$profile->get('field_coordinator_hours_display')->isEmpty()
        && $profile->get('field_coordinator_hours_display')->value === 'hide') {
        continue;
      }

      if (!$profile->hasField('field_coordinator_hours') || $profile->get('field_coordinator_hours')->isEmpty()) {
        continue;
      }

      // Find the soonest slot within the availability window.
      $soonest_ts = NULL;
      $slot_rows = [];
      foreach ($profile->get('field_coordinator_hours') as $item) {
        $slot_ts = (int) ($item->value ?? 0);
        if ($slot_ts >= $window_start && $slot_ts <= $window_end) {
          if ($soonest_ts === NULL || $slot_ts < $soonest_ts) {
            $soonest_ts = $slot_ts;
          }
          $slot_rows[] = [
            'start' => $slot_ts,
            'end' => (int) ($item->end_value ?? 0),
          ];
        }
      }
      if ($soonest_ts === NULL) {
        continue;
      }

      usort($slot_rows, fn($a, $b) => $a['start'] <=> $b['start']);

      $available[] = [
        'user' => $user,
        'availability' => $this->formatCoordinatorHours($profile),
        'soonest_ts' => $soonest_ts,
        'slots' => $slot_rows,
      ];
    }

    // Sort by soonest upcoming slot so the earliest available appears first.
    usort($available, fn($a, $b) => $a['soonest_ts'] <=> $b['soonest_ts']);

    return $available;
  }

  /**
   * Builds a week-style schedule table from facilitator availability slots.
   *
   * When $is_gated is TRUE the per-slot "Schedule @time" element is rendered
   * as an inert button (no href, aria-disabled, btn-secondary disabled) so
   * preview-only viewers cannot click through to a booking form they don't
   * have permission to submit.
   */
  protected function buildFacilitatorScheduleTable(array $facilitators, int $badge_tid, bool $is_gated = FALSE): array {
    $timezone_name = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
    $timezone = new \DateTimeZone($timezone_name);

    // Contiguous, full-day coverage so no eligible facilitator slot is ever
    // dropped. The previous sparse blocks (9-11, 11-2, 5-8) silently hid any
    // availability set for early morning, 2-5 PM, or after 8 PM — e.g. a
    // facilitator with a standing Tue 9 PM or Mon 2 PM slot never rendered
    // even though they passed every eligibility check. Each block spans a
    // half-open hour range [start, end); together they cover hours 0-23 with
    // no gaps. The exact slot time still appears on each "Schedule" button,
    // so per-hour granularity is preserved within the column.
    $time_blocks = [
      'morning' => ['label' => (string) $this->t('Morning (before 12 PM)'), 'start' => 0, 'end' => 12],
      'afternoon' => ['label' => (string) $this->t('Afternoon (12 - 5 PM)'), 'start' => 12, 'end' => 17],
      'evening' => ['label' => (string) $this->t('Evening (after 5 PM)'), 'start' => 17, 'end' => 24],
    ];

    $days_to_show = 15;
    $start_day = new \DateTimeImmutable('today', $timezone);
    $days = [];
    for ($i = 0; $i < $days_to_show; $i++) {
      $day_dt = $start_day->modify('+' . $i . ' days');
      $key = $day_dt->format('Y-m-d');
      $days[$key] = [
        'label' => $this->dateFormatter->format($day_dt->getTimestamp(), 'custom', 'l'),
        'date' => $this->dateFormatter->format($day_dt->getTimestamp(), 'custom', 'F j, Y'),
        'blocks' => array_fill_keys(array_keys($time_blocks), []),
      ];
    }

    foreach ($facilitators as $facilitator) {
      $user = $facilitator['user'] ?? NULL;
      if (!$user || empty($facilitator['slots']) || !is_iterable($facilitator['slots'])) {
        continue;
      }

      foreach ($facilitator['slots'] as $slot) {
        $slot_ts = (int) ($slot['start'] ?? 0);
        if ($slot_ts <= 0) {
          continue;
        }

        $slot_dt = (new \DateTimeImmutable('@' . $slot_ts))->setTimezone($timezone);
        $day_key = $slot_dt->format('Y-m-d');
        if (!isset($days[$day_key])) {
          continue;
        }

        $hour = (int) $slot_dt->format('G');
        $block_key = NULL;
        foreach ($time_blocks as $key => $def) {
          if ($hour >= $def['start'] && $hour < $def['end']) {
            $block_key = $key;
            break;
          }
        }
        if ($block_key === NULL) {
          continue;
        }

        $days[$day_key]['blocks'][$block_key][] = [
          'ts' => $slot_ts,
          'label' => $this->dateFormatter->format($slot_ts, 'custom', 'g:ia'),
          'host' => $user->getDisplayName(),
          'url' => $this->buildScheduleUrl($user->id(), $badge_tid, $slot_ts),
        ];
      }
    }

    // Hide empty days/columns to match the Calendly chooser behavior.
    foreach ($days as $day_key => $day) {
      $has_any = FALSE;
      foreach (array_keys($time_blocks) as $block_key) {
        if (!empty($day['blocks'][$block_key])) {
          $has_any = TRUE;
          usort($days[$day_key]['blocks'][$block_key], fn($a, $b) => $a['ts'] <=> $b['ts']);
        }
      }
      if (!$has_any) {
        unset($days[$day_key]);
      }
    }

    if (!$days) {
      return [
        '#markup' => '<p>' . $this->t('No schedule slots currently available.') . '</p>',
      ];
    }

    $active_blocks = [];
    foreach (array_keys($time_blocks) as $block_key) {
      foreach ($days as $day) {
        if (!empty($day['blocks'][$block_key])) {
          $active_blocks[$block_key] = TRUE;
          break;
        }
      }
    }
    $time_blocks = array_intersect_key($time_blocks, $active_blocks);

    $table = [
      '#type' => 'table',
      '#header' => array_merge(
        [(string) $this->t('Day')],
        array_map(fn($block) => $block['label'], $time_blocks)
      ),
      '#attributes' => ['class' => ['table', 'table-bordered', 'calendly-week-table']],
    ];

    foreach ($days as $day) {
      $row = [];
      $row[] = [
        'data' => [
          '#markup' => '<strong>' . Html::escape($day['label']) . '</strong><br><small>' . Html::escape($day['date']) . '</small>',
        ],
      ];

      foreach (array_keys($time_blocks) as $block_key) {
        $slots = $day['blocks'][$block_key];
        if (!$slots) {
          $row[] = ['data' => ['#markup' => '&nbsp;']];
          continue;
        }

        $cell = [
          '#type' => 'container',
          '#attributes' => ['class' => ['calendly-slot-list-item']],
        ];

        foreach ($slots as $i => $slot) {
          if ($is_gated) {
            $action = [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $this->t('Schedule @time', ['@time' => $slot['label']]),
              '#attributes' => [
                'class' => ['btn', 'btn-secondary', 'btn-sm', 'disabled', 'appointment-facilitator-slot-disabled'],
                'aria-disabled' => 'true',
                'tabindex' => '-1',
                'role' => 'button',
                'title' => (string) $this->t('Booking unlocks after you finish the prerequisites and pass the quiz above.'),
              ],
            ];
          }
          else {
            $action = [
              '#type' => 'link',
              '#title' => $this->t('Schedule @time', ['@time' => $slot['label']]),
              '#url' => $slot['url'],
              '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-sm']],
            ];
          }
          $cell['slot_' . $i] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['calendly-slot-entry']],
            'event' => [
              '#markup' => '<div class="calendly-slot-event-name"><small>' . $this->t('Facilitator badge checkout') . '</small></div>',
            ],
            'link' => $action,
            'details' => [
              '#markup' => '<div class="calendly-slot-details">' . $this->t('With @name', ['@name' => $slot['host']]) . '</div>',
            ],
          ];
        }

        $row[] = ['data' => $cell];
      }

      $table['#rows'][] = $row;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['calendly-availability-week-schedule', 'responsive-table']],
      'table' => $table,
    ];
  }

  /**
   * Builds the appointment add form URL for a host/badge slot.
   */
  protected function buildScheduleUrl(int $host_uid, int $badge_tid, int $slot_ts): Url {
    $query = [
      'host-uid' => $host_uid,
      'host' => $host_uid,
      'badge' => $badge_tid,
      'purpose' => 'checkout',
      'from-badges-complete' => 1,
    ];

    if ($slot_ts > 0) {
      $timezone_name = \Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get();
      try {
        $slot = (new \DateTimeImmutable('@' . $slot_ts))->setTimezone(new \DateTimeZone($timezone_name));
        $query['date'] = $slot->format('Y-m-d');
        $query['start_time'] = (string) $slot_ts;
      }
      catch (\Exception $e) {
        // Fall back to host + badge preselection only.
      }
    }

    return Url::fromRoute('node.add', ['node_type' => 'appointment'], ['query' => $query]);
  }

  /**
   * Formats coordinator hours into a human-readable availability string.
   *
   * Reads the stored field_coordinator_hours items, sorts by start timestamp
   * (soonest first), deduplicates by day-of-week (keeping the earliest slot
   * per day), and returns a compact string such as "Mon 6–8pm · Wed 6–8pm".
   */
  protected function formatCoordinatorHours($profile): string {
    $raw = [];
    foreach ($profile->get('field_coordinator_hours') as $item) {
      $start_ts = (int) ($item->value ?? 0);
      if ($start_ts <= 0) {
        continue;
      }
      $raw[] = [
        'ts'  => $start_ts,
        'end' => (int) ($item->end_value ?? 0),
      ];
    }

    // Sort soonest first so the first entry per day is always the earliest.
    usort($raw, fn($a, $b) => $a['ts'] <=> $b['ts']);

    $slots = [];
    foreach ($raw as $entry) {
      $start_ts = $entry['ts'];
      $end_ts   = $entry['end'];
      $day      = $this->dateFormatter->format($start_ts, 'custom', 'D');
      if (isset($slots[$day])) {
        continue; // keep only the earliest slot per day
      }
      $start = $this->dateFormatter->format($start_ts, 'custom', 'g:ia');
      $end   = $end_ts > $start_ts
        ? $this->dateFormatter->format($end_ts, 'custom', 'g:ia')
        : '';
      $slots[$day] = $end ? $day . ' ' . $start . '–' . $end : $day . ' ' . $start;
    }

    return implode(' · ', array_values($slots));
  }

  /**
   * Renders a row for an upcoming appointment with open capacity.
   */
  protected function buildAppointmentRow(NodeInterface $node, int $current_uid): array {
    $ts = (int) $node->get('field_appointment_timerange')->value;
    $date = $this->dateFormatter->format($ts, 'custom', 'D, M j');
    $time = $this->dateFormatter->format($ts, 'custom', 'g:ia');

    $capacity = appointment_facilitator_effective_capacity($node);
    $attendee_count = count($node->get('field_appointment_attendees')->getValue());
    $open_spots = $capacity - $attendee_count;

    $host_name = '';
    if (!$node->get('field_appointment_host')->isEmpty()) {
      $host = $node->get('field_appointment_host')->entity;
      if ($host) {
        $host_name = $host->getDisplayName();
      }
    }

    $already_joined = FALSE;
    foreach ($node->get('field_appointment_attendees')->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $current_uid) {
        $already_joined = TRUE;
        break;
      }
    }

    $spots_label = $this->formatPlural($open_spots, '1 spot left', '@count spots left');
    $info = $date . ' at ' . $time . ' &mdash; ' . $spots_label;
    if ($host_name !== '') {
      $info .= ' <span class="slot-host">('
        . $this->t('Facilitator: @name', ['@name' => $host_name])
        . ')</span>';
    }

    $row = [
      '#type' => 'container',
      '#attributes' => ['class' => ['appointment-slot-row']],
      'info' => ['#markup' => '<span class="slot-info">' . $info . '</span> '],
    ];

    if ($already_joined) {
      $row['action'] = ['#markup' => '<em>' . $this->t('Already joined') . '</em>'];
    }
    else {
      $row['action'] = [
        '#type' => 'link',
        '#title' => $this->t('Join this session'),
        '#url' => Url::fromRoute('appointment_facilitator.join', ['node' => $node->id()]),
        '#attributes' => ['class' => ['button', 'button--primary', 'button--small']],
      ];
    }

    return $row;
  }

  /**
   * Renders a row for a CiviCRM event.
   */
  protected function buildEventItem($event): ?array {
    try {
      $start = $event->get('start_date')->value ?? NULL;
      $date_str = $start
        ? $this->dateFormatter->format(strtotime($start), 'custom', 'D, M j g:ia')
        : '';

      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['event-item']],
        'content' => [
          '#markup' => '<span class="event-date">' . htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') . '</span> ',
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $event->label(),
          '#url' => $event->toUrl(),
        ],
      ];
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Renders a card for a facilitator with availability and a booking link.
   */
  protected function buildFacilitatorItem($user, int $badge_tid, string $availability = '', int $soonest_ts = 0): array {
    $schedule_url = $this->buildScheduleUrl((int) $user->id(), $badge_tid, $soonest_ts);

    $name = $user->getDisplayName();
    $soonest_label = '';
    if ($soonest_ts > 0) {
      $soonest_label = $this->dateFormatter->format($soonest_ts, 'custom', 'D, M j g:ia');
    }
    $avail_html = $availability
      ? '<span class="facilitator-hours">' . htmlspecialchars($availability, ENT_QUOTES, 'UTF-8') . '</span>'
      : '';
    $soonest_html = $soonest_label !== ''
      ? '<span class="facilitator-next">' . htmlspecialchars($this->t('Next: @time', ['@time' => $soonest_label]), ENT_QUOTES, 'UTF-8') . '</span>'
      : '';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['facilitator-card']],
      'info' => [
        '#markup' => $soonest_html . $avail_html . '<span class="facilitator-name">' . $this->t('Facilitator: @name', ['@name' => $name]) . '</span>',
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Choose this time window'),
        '#url' => $schedule_url,
        '#attributes' => ['class' => ['facilitator-schedule-btn']],
      ],
    ];
  }

}
