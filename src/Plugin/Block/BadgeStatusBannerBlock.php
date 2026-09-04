<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Plugin\Block;

use Drupal\appointment_facilitator\Form\BadgeVideoWatchedForm;
use Drupal\appointment_facilitator\Service\BadgeUserStatusResolver;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member-facing "next step" banner for the badge term page.
 *
 * Resolves the current user's status with respect to the badge term loaded
 * by the active route, then renders a sticky banner with an appropriate CTA
 * (take quiz, schedule checkout, submit documentation, earn prerequisites,
 * etc.). Anonymous visitors see a log-in prompt.
 *
 * @Block(
 *   id = "appointment_facilitator_badge_status_banner",
 *   admin_label = @Translation("Badge status banner (member next-step CTA)"),
 *   category = @Translation("MakeHaven")
 * )
 */
class BadgeStatusBannerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly AccountInterface $currentUser,
    protected readonly BadgeUserStatusResolver $statusResolver,
    protected readonly UserDataInterface $userData,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('current_user'),
      $container->get('appointment_facilitator.badge_user_status'),
      $container->get('user.data'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $term = $this->resolveBadgeTerm();
    if (!$term) {
      return [];
    }

    $uid = (int) $this->currentUser->id();
    $resolved = $this->statusResolver->resolve($uid, $term);
    $state = $resolved['state'];

    [$tone, $heading, $body, $cta] = $this->renderForState($state, $term, $resolved);
    $steps = $this->buildSteps($state, $term);
    $docs_row = $this->renderDocumentationStatusRow($resolved);

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mh-badge-status-banner',
          'mh-badge-status-banner--' . str_replace('_', '-', $state),
          'mh-badge-status-banner--tone-' . $tone,
        ],
        'data-state' => $state,
      ],
      '#attached' => [
        'library' => ['appointment_facilitator/badge_status_banner'],
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mh-badge-status-banner__inner']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['mh-badge-status-banner__heading']],
          '#value' => $heading,
        ],
        'body' => $this->renderBody($body),
        'cta' => $cta ?? [],
      ],
      'steps' => $steps,
    ];

    if ($docs_row) {
      $build['docs_row'] = $docs_row;
    }

    return $build;
  }

  /**
   * Renders the secondary "Documentation: not submitted / under review /
   * approved" pill row, or [] when the badge doesn't require documentation.
   *
   * Independent of the primary state — a member with an active badge still
   * sees the green "Approved" pill so they can confirm that gate cleared.
   */
  protected function renderDocumentationStatusRow(array $resolved): array {
    $docs_status = $resolved['documentation_status'] ?? BadgeUserStatusResolver::DOCS_NOT_REQUIRED;
    if ($docs_status === BadgeUserStatusResolver::DOCS_NOT_REQUIRED) {
      return [];
    }

    $gate = $resolved['gate'] ?? [];
    $form_url = $gate['documentation_form_url'] ?? NULL;
    $submitted_at = isset($gate['documentation_submitted_at']) ? (int) $gate['documentation_submitted_at'] : 0;

    [$tone, $label, $sub, $action_label] = match ($docs_status) {
      BadgeUserStatusResolver::DOCS_APPROVED => [
        'success',
        (string) $this->t('Documentation: approved'),
        (string) $this->t('You can now schedule a facilitator checkout.'),
        NULL,
      ],
      BadgeUserStatusResolver::DOCS_PENDING_REVIEW => [
        'warning',
        (string) $this->t('Documentation: under review'),
        $this->formatSubmittedAgo($submitted_at),
        NULL,
      ],
      BadgeUserStatusResolver::DOCS_SATISFIED_BY_CLASS => [
        'success',
        (string) $this->t('Documentation: not needed'),
        (string) $this->t("You're registered for a class that includes this badge. Your instructor issues it at the end of class — no form to submit."),
        NULL,
      ],
      default => [
        'danger',
        (string) $this->t('Documentation: not submitted'),
        (string) $this->t('Submit the Training Documentation form for staff review.'),
        $form_url ? (string) $this->t('Open form') : NULL,
      ],
    };

    return [
      '#type' => 'inline_template',
      '#template' => <<<TWIG
<div class="mh-badge-docs-row mh-badge-docs-row--{{ tone }}" data-docs-status="{{ status }}">
  <span class="mh-badge-docs-row__label">{{ label }}</span>
  {% if sub %}<span class="mh-badge-docs-row__sub">{{ sub }}</span>{% endif %}
  {% if action_url and action_label %}<a class="mh-badge-docs-row__action" href="{{ action_url }}">{{ action_label }}</a>{% endif %}
</div>
TWIG,
      '#context' => [
        'tone' => $tone,
        'status' => $docs_status,
        'label' => $label,
        'sub' => $sub,
        'action_url' => $form_url,
        'action_label' => $action_label,
      ],
    ];
  }

  /**
   * Builds "Submitted N days ago" copy from a UNIX timestamp.
   */
  protected function formatSubmittedAgo(int $ts): string {
    if ($ts <= 0) {
      return (string) $this->t('Submitted — awaiting staff approval.');
    }
    $days = max(0, (int) floor((time() - $ts) / 86400));
    if ($days <= 0) {
      return (string) $this->t('Submitted today — awaiting staff approval.');
    }
    return (string) $this->formatPlural(
      $days,
      'Submitted 1 day ago — awaiting staff approval.',
      'Submitted @count days ago — awaiting staff approval.'
    );
  }

  /**
   * Renders the four-step progress strip beneath the status banner.
   *
   * Steps: Prerequisites → Learn → Quiz → Earn. Each step is marked
   * done / current / locked / skipped based on the resolved state and
   * whether the badge requires prereqs and a facilitator checkout.
   */
  protected function buildSteps(string $state, TermInterface $term): array {
    $has_prereqs = $term->hasField('field_badge_prerequisite')
      && !$term->get('field_badge_prerequisite')->isEmpty();

    $has_video = $term->hasField('field_badge_video')
      && !$term->get('field_badge_video')->isEmpty();

    $checkout_requirement = '';
    if ($term->hasField('field_badge_checkout_requirement') && !$term->get('field_badge_checkout_requirement')->isEmpty()) {
      $checkout_requirement = strtolower(trim((string) $term->get('field_badge_checkout_requirement')->value));
    }
    $requires_checkout = ($checkout_requirement !== '' && $checkout_requirement !== 'no');

    $uid = (int) $this->currentUser->id();
    $video_watched = $has_video && BadgeVideoWatchedForm::isWatched($this->userData, $uid, (int) $term->id());

    // Compute each step's status from the resolved state. Phases:
    //  - done:    earned/passed in the past
    //  - current: where the member is right now
    //  - locked:  blocked by an earlier step
    //  - skipped: not applicable for this badge (e.g. no prereqs, auto-issue)
    $prereq_status = $has_prereqs ? 'locked' : 'skipped';
    $learn_status = 'locked';
    $quiz_status = 'locked';
    $earn_status = 'locked';

    switch ($state) {
      case BadgeUserStatusResolver::STATE_ANONYMOUS:
        // Show the path but don't pick a "current" — login is the gate.
        break;

      case BadgeUserStatusResolver::STATE_PREREQS_MISSING:
        $prereq_status = 'current';
        break;

      case BadgeUserStatusResolver::STATE_QUIZ_NEEDED:
        $prereq_status = $has_prereqs ? 'done' : 'skipped';
        // If the badge has a video AND the member hasn't self-attested
        // to watching it, step 2 is where they are — not step 3. The
        // post-state override below promotes learn to 'done' when the
        // watch flag is set, which then frees step 3 to become current.
        if ($has_video && !$video_watched) {
          $learn_status = 'current';
          $quiz_status = 'locked';
        }
        else {
          $learn_status = 'available';
          $quiz_status = 'current';
        }
        break;

      case BadgeUserStatusResolver::STATE_PENDING:
        $prereq_status = $has_prereqs ? 'done' : 'skipped';
        $learn_status = 'done';
        $quiz_status = 'done';
        // Only `yes` badges need an actionable checkout step; for class /
        // auto-issued badges, reaching PENDING already implies the badge
        // will be applied (no separate user action to take).
        $earn_status = ($checkout_requirement === 'yes') ? 'current' : 'done';
        break;

      case BadgeUserStatusResolver::STATE_ACTIVE:
        $prereq_status = $has_prereqs ? 'done' : 'skipped';
        $learn_status = 'done';
        $quiz_status = 'done';
        $earn_status = 'done';
        break;

      case BadgeUserStatusResolver::STATE_SUSPENDED:
      case BadgeUserStatusResolver::STATE_EXPIRED:
        $prereq_status = $has_prereqs ? 'done' : 'skipped';
        $learn_status = 'done';
        $quiz_status = 'done';
        $earn_status = 'current';
        break;
    }

    // Step 4 is collapsed to the lightweight "skipped" style whenever the
    // badge issues itself (no = quiz-only; class = at the class) — only
    // 'yes' badges need an active 1-on-1 facilitator checkout.
    $auto_issued = ($checkout_requirement !== 'yes');
    if ($auto_issued && $earn_status === 'locked') {
      $earn_status = 'skipped';
    }

    // Badges with no video can't have a "watched" step — collapse it.
    // Otherwise honor the self-attested watch flag: if they've marked it,
    // show step 2 as done regardless of which gate they're currently on.
    if (!$has_video) {
      $learn_status = 'skipped';
    }
    elseif ($video_watched && in_array($learn_status, ['available', 'current', 'locked'], TRUE)) {
      $learn_status = 'done';
    }

    // Step 4 is always "Checkout" — getting the badge happens *after*
    // the checkout (whether that's a 1-on-1 facilitator session or an
    // automatic application). Hint adapts:
    //   yes   → no hint (the schedule grid below speaks for itself)
    //   no/class → "Auto-issued" so members know there's no action here
    $earn_label = $this->t('Checkout');
    $earn_hint = ($checkout_requirement === 'yes') ? NULL : (string) $this->t('Auto-issued');

    // Step-1 hint:
    //   - No badge prereqs → "None required"
    //   - Otherwise → no hint (the banner body lists the missing prereqs)
    if (!$has_prereqs) {
      $prereq_hint = $this->t('None required');
    }
    else {
      $prereq_hint = NULL;
    }

    $steps = [
      ['key' => 'prereqs', 'label' => $this->t('Prerequisites'),    'status' => $prereq_status, 'hint' => $prereq_hint],
      ['key' => 'learn',   'label' => $this->t('Review materials'), 'status' => $learn_status,  'hint' => NULL],
      ['key' => 'quiz',    'label' => $this->t('Pass quiz'),        'status' => $quiz_status,   'hint' => NULL],
      ['key' => 'earn',    'label' => $earn_label,                  'status' => $earn_status,   'hint' => $earn_hint],
    ];

    // Glyph per status:
    //   done    → ✓ (filled green)
    //   skipped → ⚡ (muted; communicates "happens automatically", not
    //             "do not pass" which was the em-dash's accidental read)
    //   else    → step number
    foreach ($steps as $i => &$step) {
      $step['position'] = $i + 1;
      $step['num'] = match ($step['status']) {
        'done' => '✓',
        'skipped' => '⚡',
        default => (string) ($i + 1),
      };
    }
    unset($step);

    return [
      '#type' => 'inline_template',
      '#template' => <<<TWIG
<ol class="mh-badge-steps" aria-label="{{ aria }}">
  {% for step in steps %}
    <li class="mh-badge-step mh-badge-step--{{ step.status }}" data-step="{{ step.key }}"{% if step.status == 'current' %} aria-current="step"{% endif %}>
      <span class="mh-badge-step__num" aria-hidden="true">{{ step.num }}</span>
      <span class="mh-badge-step__label">{{ step.label }}</span>
      {% if step.hint %}<span class="mh-badge-step__hint">{{ step.hint }}</span>{% endif %}
    </li>
  {% endfor %}
</ol>
TWIG,
      '#context' => [
        'aria' => $this->t('Progress earning this badge'),
        'steps' => $steps,
      ],
    ];
  }

  /**
   * Wraps the body in an <p> tag when it's a string, or returns the render
   * array as-is when the state contributes a complex element (e.g. the
   * prereqs-missing state injects an item_list of prerequisite links).
   */
  protected function renderBody(mixed $body): array {
    if ($body === NULL || $body === '') {
      return [];
    }
    if (is_array($body)) {
      return $body;
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['mh-badge-status-banner__body']],
      '#value' => $body,
    ];
  }

  /**
   * Computes [tone, heading, body, cta-render-array] for a state.
   */
  protected function renderForState(string $state, TermInterface $term, array $resolved): array {
    switch ($state) {
      case BadgeUserStatusResolver::STATE_ANONYMOUS:
        $login = Url::fromRoute('user.login', [], [
          'query' => ['destination' => $term->toUrl()->toString()],
        ])->toString();
        $cta = $this->ctaButton($this->t('Log in'), $login);
        return [
          'neutral',
          $this->t('Log in to see your progress on this badge.'),
          NULL,
          $cta,
        ];

      case BadgeUserStatusResolver::STATE_ACTIVE:
        return [
          'success',
          $this->t('✓ You have this badge.'),
          $this->t("You're authorized to use the associated tools listed below."),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_PENDING:
        $docs_status = $resolved['documentation_status'] ?? BadgeUserStatusResolver::DOCS_NOT_REQUIRED;
        if ($docs_status === BadgeUserStatusResolver::DOCS_SATISFIED_BY_CLASS) {
          // The class includes the badging session: the instructor
          // activates the badge from the class checkout page, so there is
          // no facilitator slot to book and no form to send.
          return [
            'progress',
            $this->t('Quiz passed — your instructor will issue this badge at the end of your class.'),
            $this->t('Nothing else to submit. If your class has already happened and the badge is still pending, ask your instructor to complete the class checkout.'),
            NULL,
          ];
        }
        $needs_docs = ($docs_status === BadgeUserStatusResolver::DOCS_NOT_SUBMITTED
          || $docs_status === BadgeUserStatusResolver::DOCS_PENDING_REVIEW);
        if ($needs_docs) {
          // Member passed the quiz but the documentation gate is still
          // open — the docs row below shows whether the form is still
          // unsubmitted or awaiting staff review. The class-based path
          // remains available; only facilitator checkouts are gated.
          return [
            'progress',
            $this->t('Quiz passed — documentation needs approval before scheduling.'),
            $this->t("Once staff approves your training documentation form, the facilitator schedule below will unlock. You can still earn this badge by attending a scheduled class."),
            NULL,
          ];
        }
        return [
          'progress',
          $this->t('Next step: schedule a facilitator checkout.'),
          $this->t("You've passed the quiz. Pick an upcoming session below or contact a facilitator."),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_QUIZ_NEEDED:
        // No CTA: we deliberately do not link directly to the quiz from the
        // top of the page. Members should review the materials (video,
        // manual, checklist) first; the quiz link lives further down with
        // the rest of the content.
        return [
          'progress',
          $this->t('Start by reviewing the materials below.'),
          $this->t('Once you have watched the video or read through the materials, scroll down to take the quiz.'),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_PREREQS_MISSING:
        // Show every prerequisite badge with its own status pill (Earned,
        // Quiz pending, Not started, etc.). Members can see at a glance
        // which prereq they're stuck on — the old in-body card had this
        // and was a real loss when it was removed for de-duplication.
        $body = $this->renderPrereqsWithState($term, (int) $this->currentUser->id());
        return [
          'blocked',
          $this->t('You need to earn prerequisites first.'),
          $body,
          NULL,
        ];

      // STATE_DOCS_REQUIRED is no longer emitted as a primary state —
      // the docs row beneath the steps strip surfaces the submission +
      // approval status independently. Members on a docs-required badge
      // see the normal Review / Quiz path instead of a dead-end banner.
      case BadgeUserStatusResolver::STATE_SUSPENDED:
        return [
          'warning',
          $this->t('Your access to this badge is suspended.'),
          $this->t('Contact staff for next steps.'),
          NULL,
        ];

      case BadgeUserStatusResolver::STATE_EXPIRED:
        return [
          'warning',
          $this->t('This badge has expired.'),
          $this->t('Re-take the quiz or schedule a refresher to renew it.'),
          NULL,
        ];
    }

    return ['neutral', '', NULL, NULL];
  }

  /**
   * Builds a CTA button render element.
   */
  protected function ctaButton($label, string $href, array $extra_classes = []): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'a',
      '#value' => $label,
      '#attributes' => [
        'href' => $href,
        'class' => array_merge(['mh-badge-status-banner__cta', 'btn', 'btn-primary'], $extra_classes),
      ],
    ];
  }

  /**
   * Resolves the URL of the quiz referenced by the badge term.
   */
  protected function resolveQuizUrl(TermInterface $term): ?string {
    if (!$term->hasField('field_badge_quiz_reference') || $term->get('field_badge_quiz_reference')->isEmpty()) {
      return NULL;
    }
    $quiz = $term->get('field_badge_quiz_reference')->entity;
    if (!$quiz) {
      return NULL;
    }
    try {
      return $quiz->toUrl()->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Renders every prerequisite as a pill row showing the viewer's state on
   * that prereq (Earned ✓ / Quiz pending / Not started / etc.).
   *
   * Used in the PREREQS_MISSING banner so members can tell which specific
   * prereq is blocking and how close they are on each. Anonymous viewers
   * (uid 0) get a simpler row without per-user state — just the badge
   * names linked.
   */
  protected function renderPrereqsWithState(TermInterface $term, int $uid): array {
    if (!$term->hasField('field_badge_prerequisite') || $term->get('field_badge_prerequisite')->isEmpty()) {
      return [];
    }
    $prereqs = $term->get('field_badge_prerequisite')->referencedEntities();
    if (!$prereqs) {
      return [];
    }

    $items = [];
    foreach ($prereqs as $prereq) {
      if (!$prereq instanceof TermInterface) {
        continue;
      }
      [$pill_label, $pill_tone] = $this->prereqStatePill($prereq, $uid);
      try {
        $url = $prereq->toUrl()->toString();
      }
      catch (\Throwable) {
        $url = '/taxonomy/term/' . (int) $prereq->id();
      }
      $items[] = [
        'name' => $prereq->label(),
        'url' => $url,
        'pill' => $pill_label,
        'tone' => $pill_tone,
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => <<<TWIG
<ul class="mh-badge-status-banner__prereqs mh-badge-status-banner__prereqs--with-state">
  {% for item in items %}
    <li>
      <a class="mh-badge-status-banner__prereq-link mh-badge-status-banner__prereq-link--tone-{{ item.tone }}" href="{{ item.url }}">
        <span class="mh-badge-status-banner__prereq-name">{{ item.name }}</span>
        <span class="mh-badge-status-banner__prereq-pill mh-badge-status-banner__prereq-pill--{{ item.tone }}">{{ item.pill }}</span>
      </a>
    </li>
  {% endfor %}
</ul>
TWIG,
      '#context' => ['items' => $items],
    ];
  }

  /**
   * Returns [pill label, tone] for a member's status on a single prereq badge.
   *
   * Tones map to existing CSS variants on the prereq pill (success, info,
   * warning, danger, muted). For prereqs in QUIZ_NEEDED state, we further
   * differentiate based on whether the member has marked materials as
   * reviewed: if not, the real next step is "Review materials" rather
   * than "Quiz pending" — pointing them at the right action.
   */
  protected function prereqStatePill(TermInterface $prereq, int $uid): array {
    if ($uid <= 0) {
      return [(string) $this->t('Required'), 'muted'];
    }
    $resolved = $this->statusResolver->resolve($uid, $prereq);
    $state = $resolved['state'] ?? '';

    if ($state === BadgeUserStatusResolver::STATE_QUIZ_NEEDED) {
      $has_video = $prereq->hasField('field_badge_video') && !$prereq->get('field_badge_video')->isEmpty();
      $watched = $has_video
        ? BadgeVideoWatchedForm::isWatched($this->userData, $uid, (int) $prereq->id())
        : TRUE;
      return $watched
        ? [(string) $this->t('Quiz pending'), 'info']
        : [(string) $this->t('Review pending'), 'info'];
    }

    return match ($state) {
      BadgeUserStatusResolver::STATE_ACTIVE => [(string) $this->t('Earned ✓'), 'success'],
      BadgeUserStatusResolver::STATE_PENDING => [(string) $this->t('In progress'), 'info'],
      BadgeUserStatusResolver::STATE_PREREQS_MISSING => [(string) $this->t('Blocked'), 'warning'],
      BadgeUserStatusResolver::STATE_DOCS_REQUIRED => [(string) $this->t('Class / docs'), 'warning'],
      BadgeUserStatusResolver::STATE_EXPIRED => [(string) $this->t('Expired'), 'warning'],
      BadgeUserStatusResolver::STATE_SUSPENDED => [(string) $this->t('Suspended'), 'warning'],
      default => [(string) $this->t('Not started'), 'danger'],
    };
  }

  /**
   * Renders a plain UL of links to the user's missing prerequisite badges.
   *
   * Bypasses `#theme => item_list` so the Bootstrap Barrio override doesn't
   * wrap the UL in `<div class="item-list">` (which would land in the grid
   * without `grid-area: body` and overlap the heading) and doesn't add
   * `list-group`/`list-group-item` classes that fight with the pill styling.
   */
  protected function renderPrereqLinks(array $gate): array {
    $tids = $gate['prerequisites_missing'] ?? [];
    $labels = $gate['prerequisites_missing_labels'] ?? [];
    if (!$tids) {
      return [];
    }

    $build = [
      '#prefix' => '<ul class="mh-badge-status-banner__prereqs">',
      '#suffix' => '</ul>',
    ];
    foreach ($tids as $i => $tid) {
      $label = $labels[$i] ?? ('Badge ' . $tid);
      $url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid])->toString();
      $build['item_' . $i] = [
        '#prefix' => '<li>',
        '#suffix' => '</li>',
        'link' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => $label,
          '#attributes' => [
            'href' => $url,
            'class' => ['mh-badge-status-banner__prereq-link'],
          ],
        ],
      ];
    }

    return $build;
  }

  /**
   * Returns TRUE when the `event_upcoming_badging:badge_events` view has at
   * least one upcoming row for this badge term.
   *
   * Used by the docs-required body copy to decide whether to link the
   * phrase "upcoming classes listed below" or replace it with a "none
   * currently scheduled" note. Result is statically cached per term per
   * request so the view doesn't execute twice.
   */
  protected function hasUpcomingClassesForBadge(int $tid): bool {
    static $cache = [];
    if (array_key_exists($tid, $cache)) {
      return $cache[$tid];
    }
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('view');
      $view = $storage->load('event_upcoming_badging');
      if (!$view) {
        return $cache[$tid] = FALSE;
      }
      $exec = $view->getExecutable();
      $exec->setDisplay('badge_events');
      $exec->setArguments([(string) $tid]);
      $exec->setItemsPerPage(1);
      $exec->execute();
      $count = is_array($exec->result) ? count($exec->result) : 0;
      $exec->destroy();
    }
    catch (\Throwable) {
      $count = 0;
    }
    return $cache[$tid] = $count > 0;
  }

  /**
   * Returns the badges term from the current route or NULL.
   */
  protected function resolveBadgeTerm(): ?TermInterface {
    $term = $this->routeMatch->getParameter('taxonomy_term');
    if (!$term instanceof TermInterface) {
      return NULL;
    }
    return $term->bundle() === 'badges' ? $term : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $tags = parent::getCacheTags();
    if ($term = $this->resolveBadgeTerm()) {
      $tags = Cache::mergeTags($tags, $term->getCacheTags());
      $tags[] = 'node_list:badge_request';
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
