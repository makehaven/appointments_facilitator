<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a CSRF-protected submit form for joining appointments.
 */
class JoinAppointmentForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DateFormatterInterface $dateFormatter,
    ConfigFactoryInterface $configFactory,
  ) {
    // FormBase already provides $this->configFactory; assign it here instead of
    // redeclaring the property with readonly promotion (which PHP forbids).
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_join_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return [];
    }

    // When rendered as a standalone page via the _form: route, Drupal does not
    // pass route parameters as buildForm() arguments. Retrieve the node from
    // the route match in that case.
    if (!$node instanceof NodeInterface) {
      $route_node = \Drupal::routeMatch()->getParameter('node');
      if ($route_node instanceof NodeInterface) {
        $node = $route_node;
      }
      elseif (is_numeric($route_node)) {
        $node = $this->entityTypeManager->getStorage('node')->load($route_node);
      }
    }

    if (!$node || $node->bundle() !== 'appointment') {
      $form['message'] = ['#markup' => $this->t('This appointment is unavailable.')];
      return $this->addCacheContexts($form);
    }

    if (!$node->hasField('field_appointment_attendees')) {
      return [];
    }

    $config = $this->configFactory->get('appointment_facilitator.settings');
    $show_always = (bool) $config->get('show_always_join_cta');
    $effective_capacity = appointment_facilitator_effective_capacity($node);
    $max_joiners = max(0, $effective_capacity - 1);

    // Show join button only when extra spots exist (or show_always is on).
    if (!$show_always && $max_joiners <= 0) {
      return [];
    }

    // Respect the appointment creator's preference.
    $open_to_join = !$node->hasField('field_appointment_open_to_join')
      || $node->get('field_appointment_open_to_join')->isEmpty()
      || (bool) $node->get('field_appointment_open_to_join')->value;
    if (!$open_to_join) {
      return [];
    }

    // Count only extra joiners — people in field_appointment_attendees who are
    // NOT the node author (the primary member who booked the appointment).
    $author_uid  = (int) $node->getOwnerId();
    $host_uid    = $node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()
      ? (int) $node->get('field_appointment_host')->target_id : 0;

    // The primary member who booked this appointment should never see the join
    // form — showing it to them (with a pace warning) is confusing since they
    // already own the session.
    $uid = (int) $account->id();
    if ($uid === $author_uid) {
      return [];
    }

    $attendee_values = $node->get('field_appointment_attendees')->getValue();
    $current_ids = [];
    foreach ($attendee_values as $value) {
      $id = (int) ($value['target_id'] ?? 0);
      if ($id > 0) {
        $current_ids[] = $id;
      }
    }

    // Extra joiners = attendees who are not the primary member and not the host.
    $joiner_ids   = array_values(array_filter($current_ids, fn($id) => $id !== $author_uid && $id !== $host_uid));
    $joiner_count = count($joiner_ids);
    $remaining    = max(0, $max_joiners - $joiner_count);

    $form['#attributes']['class'][] = 'appointment-join-form';

    // --- Session details panel ---
    // Date/time.
    $ts = 0;
    if ($node->hasField('field_appointment_timerange') && !$node->get('field_appointment_timerange')->isEmpty()) {
      $ts = (int) $node->get('field_appointment_timerange')->value;
    }

    // Host name.
    $host_name = '';
    if ($node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()) {
      $host = $node->get('field_appointment_host')->entity;
      if ($host) {
        $host_name = $host->getDisplayName();
      }
    }

    $meta_parts = [];
    if ($ts) {
      $meta_parts[] = $this->dateFormatter->format($ts, 'custom', 'l, F j \a\t g:ia');
    }
    if ($host_name) {
      $meta_parts[] = $this->t('with @name', ['@name' => $host_name]);
    }
    $meta_parts[] = $this->formatPlural($remaining, '1 extra spot available', '@count extra spots available');

    $form['session_meta'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => implode(' &mdash; ', array_map('strval', $meta_parts)),
      '#attributes' => ['class' => ['join-session-meta']],
    ];

    // --- Badges covered in this session ---
    if ($node->hasField('field_appointment_badges') && !$node->get('field_appointment_badges')->isEmpty()) {
      $badge_terms = $node->get('field_appointment_badges')->referencedEntities();
      $pending_tids = array_flip(_appointment_facilitator_load_pending_badge_term_ids($uid));

      $badge_items = [];
      foreach ($badge_terms as $term) {
        $tid = (int) $term->id();
        $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);
        $is_pending = isset($pending_tids[$tid]);

        if ($is_pending) {
          $badge_items[] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['join-badge-item', 'join-badge-item--pending']],
            'label' => [
              '#type' => 'link',
              '#title' => $term->label(),
              '#url' => $badge_url,
            ],
            'status' => [
              '#markup' => ' <span class="join-badge-status join-badge-status--pending">' . $this->t('pending — ready to check out') . '</span>',
            ],
          ];
        }
        else {
          $badge_items[] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['join-badge-item', 'join-badge-item--not-pending']],
            'label' => [
              '#type' => 'link',
              '#title' => $term->label(),
              '#url' => $badge_url,
            ],
            'status' => [
              '#markup' => ' <span class="join-badge-status join-badge-status--not-pending">' . $this->t('not yet pending') . '</span>',
            ],
          ];
        }
      }

      if ($badge_items) {
        $form['badges_section'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['join-section', 'join-badges']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Badges covered in this session'),
            '#attributes' => ['class' => ['join-section-heading']],
          ],
          'items' => $badge_items,
          'hint' => [
            '#markup' => '<p class="join-badge-hint">' . $this->t('Visit each badge page and follow the instructions to get it marked as pending before your session.') . '</p>',
          ],
        ];

        // Checkout-specific notice: only pending badges will be issued.
        if ($this->requiresPendingBadge($node)) {
          $form['badge_issuance_notice'] = [
            '#type'       => 'container',
            '#attributes' => ['class' => ['join-experience-gate', 'join-experience-gate--notice']],
            'icon'    => ['#markup' => '<div class="join-experience-gate__icon">ℹ️</div>'],
            'content' => ['#type' => 'container', '#attributes' => ['class' => ['join-experience-gate__content']],
              'heading' => ['#markup' => '<h3 class="join-experience-gate__heading">' . $this->t('Badge issuance at checkout') . '</h3>'],
              'body'    => ['#markup' => '<p class="join-experience-gate__body">' . $this->t('<strong>Only badges already marked as pending on your profile will be issued</strong> during this session. You must complete each badge\'s request process and have it marked pending <em>before</em> your appointment — the facilitator cannot issue badges that aren\'t already pending.') . '</p>'],
            ],
          ];
        }
      }
    }

    // --- Current attendees ---
    $other_ids = array_filter($current_ids, static fn($id) => $id !== $uid && $id > 0);
    if ($other_ids) {
      $attendee_users = $this->entityTypeManager->getStorage('user')->loadMultiple($other_ids);
      $names = array_map(static fn($u) => htmlspecialchars($u->getDisplayName(), ENT_QUOTES, 'UTF-8'), $attendee_users);
      $form['attendees_section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['join-section', 'join-attendees']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Also attending'),
          '#attributes' => ['class' => ['join-section-heading']],
        ],
        'list' => [
          '#markup' => '<p class="join-attendee-names">' . implode(', ', $names) . '</p>',
        ],
      ];
    }

    // --- Experience level gate / warning ---
    $form['#attached']['library'][] = 'appointment_facilitator/appointments';
    $primary_exp = '';
    if ($node->hasField('field_appointment_experience') && !$node->get('field_appointment_experience')->isEmpty()) {
      $primary_exp = (string) $node->get('field_appointment_experience')->value;
    }
    if ($primary_exp) {
      if ($primary_exp === 'advanced') {
        $form['experience_gate'] = [
          '#type'       => 'container',
          '#attributes' => ['class' => ['join-experience-gate', 'join-experience-gate--blocked']],
          'icon'    => ['#markup' => '<div class="join-experience-gate__icon">⚡</div>'],
          'heading' => ['#markup' => '<h3 class="join-experience-gate__heading">' . $this->t('Advanced-pace session') . '</h3>'],
          'body'    => ['#markup' => '<p class="join-experience-gate__body">' . $this->t('The primary attendee is <strong>advanced</strong>. This session will move at an advanced pace — beginners are not a good fit and will not be able to join.') . '</p>'],
        ];
      }
      elseif ($primary_exp === 'beginner') {
        $form['experience_notice'] = [
          '#type'       => 'container',
          '#attributes' => ['class' => ['join-experience-gate', 'join-experience-gate--notice']],
          'icon'    => ['#markup' => '<div class="join-experience-gate__icon">🐢</div>'],
          'heading' => ['#markup' => '<h3 class="join-experience-gate__heading">' . $this->t('Beginner-pace session') . '</h3>'],
          'body'    => ['#markup' => '<p class="join-experience-gate__body">' . $this->t('The primary attendee is a <strong>beginner</strong>. The facilitator will prioritise their needs. If you are more experienced, you are welcome to join but must follow along at their pace.') . '</p>'],
        ];
      }
      elseif ($primary_exp === 'intermediate') {
        $form['experience_notice'] = [
          '#type'       => 'container',
          '#attributes' => ['class' => ['join-experience-gate', 'join-experience-gate--notice']],
          'icon'    => ['#markup' => '<div class="join-experience-gate__icon">📋</div>'],
          'heading' => ['#markup' => '<h3 class="join-experience-gate__heading">' . $this->t('Intermediate-pace session') . '</h3>'],
          'body'    => ['#markup' => '<p class="join-experience-gate__body">' . $this->t('The primary attendee has intermediate experience. Beginners should be comfortable with the basics before joining.') . '</p>'],
        ];
      }
    }

    if ($this->requiresPendingBadge($node) && !$this->userHasPendingBadgeForAppointment($node, $uid)) {
      $form['#attached']['library'][] = 'appointment_facilitator/appointments';
      $form['pending_required'] = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['join-pending-gate']],
        'icon'        => ['#markup' => '<div class="join-pending-gate__icon">🔒</div>'],
        'heading'     => ['#markup' => '<h3 class="join-pending-gate__heading">' . $this->t('Get a badge pending to join') . '</h3>'],
        'body'        => ['#markup' => '<p class="join-pending-gate__body">' . $this->t('To join this session you need at least one of its badges marked as pending on your profile. Visit the badge page, follow the steps to qualify, and come back once it\'s pending.') . '</p>'],
      ];

      if ($node->hasField('field_appointment_badges') && !$node->get('field_appointment_badges')->isEmpty()) {
        $buttons = ['#type' => 'container', '#attributes' => ['class' => ['join-pending-gate__buttons']]];
        foreach ($node->get('field_appointment_badges')->referencedEntities() as $idx => $term) {
          $buttons['badge_' . $idx] = [
            '#type'       => 'link',
            '#title'      => $this->t('Go to @badge →', ['@badge' => $term->label()]),
            '#url'        => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $term->id()]),
            '#attributes' => ['class' => ['btn', 'btn-outline-primary', 'join-pending-gate__btn']],
          ];
        }
        $form['pending_required']['buttons'] = $buttons;
      }

      return $this->addCacheContexts($form);
    }

    // Documentation gate: if any of this appointment's badges requires
    // training documentation and the member has not gotten staff approval,
    // they can't book a facilitator checkout (the docs path exists so the
    // class-based earning route still works without scheduling). This
    // mirrors _appointment_facilitator_checkout_group_gate_blocked() on
    // badge pages.
    $docs_blocked_badges = $this->docsBlockedBadgesForAppointment($node, $uid);
    if ($docs_blocked_badges) {
      $form['#attached']['library'][] = 'appointment_facilitator/appointments';
      $names = array_map(fn ($t) => $t->label(), $docs_blocked_badges);
      $form['docs_required'] = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['join-pending-gate']],
        'icon'        => ['#markup' => '<div class="join-pending-gate__icon">📋</div>'],
        'heading'     => ['#markup' => '<h3 class="join-pending-gate__heading">' . $this->t('Training documentation required') . '</h3>'],
        'body'        => ['#markup' => '<p class="join-pending-gate__body">' . $this->t(
          'Before you can book a facilitator checkout for @badges, your training documentation form needs to be submitted and approved by staff.',
          ['@badges' => implode(', ', $names)]
        ) . '</p>'],
      ];
      $buttons = ['#type' => 'container', '#attributes' => ['class' => ['join-pending-gate__buttons']]];
      foreach ($docs_blocked_badges as $idx => $term) {
        $buttons['badge_' . $idx] = [
          '#type'       => 'link',
          '#title'      => $this->t('Go to @badge →', ['@badge' => $term->label()]),
          '#url'        => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $term->id()]),
          '#attributes' => ['class' => ['btn', 'btn-outline-primary', 'join-pending-gate__btn']],
        ];
      }
      $form['docs_required']['buttons'] = $buttons;
      return $this->addCacheContexts($form);
    }

    // --- Already joined / Full / Join button ---
    if (in_array($uid, $current_ids, TRUE)) {
      $form['message'] = ['#markup' => '<p>' . $this->t('You are already on this appointment.') . '</p>'];
      $form['node_id'] = [
        '#type' => 'hidden',
        '#value' => $node->id(),
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['leave'] = [
        '#type' => 'submit',
        '#value' => $this->t('Leave this appointment'),
        '#button_type' => 'danger',
        '#submit' => ['::leaveSubmit'],
      ];
      return $this->addCacheContexts($form);
    }

    if ($remaining <= 0) {
      $form['message'] = ['#markup' => '<p>' . $this->t('This appointment is full.') . '</p>'];
      return $this->addCacheContexts($form);
    }

    $form['node_id'] = [
      '#type' => 'hidden',
      '#value' => $node->id(),
    ];

    // --- Pace & Experience Warning ---
    $form['pace_warning'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['messages', 'messages--warning'],
        'style' => 'margin-top: 20px; border-left: 5px solid #ffc107;',
      ],
      'title' => [
        '#markup' => '<strong>' . $this->t('Important: Pace Warning') . '</strong>',
      ],
      'body' => [
        '#markup' => '<p>' . $this->t('By joining this appointment, you agree to follow along at the primary attendee\'s pace. The facilitator will prioritize the needs of the person who originally scheduled the session.') . '</p>',
      ],
    ];

    $form['experience_level'] = [
      '#type' => 'radios',
      '#title' => $this->t('Your Experience Level with these tools/badges'),
      '#options' => [
        'beginner'     => $this->t('Beginner'),
        'intermediate' => $this->t('Intermediate'),
        'advanced'     => $this->t('Advanced'),
      ],
      '#default_value' => 'beginner',
      '#required'      => TRUE,
      '#description'   => $this->t('Choose the pace that best matches your experience. Default is Beginner.'),
      '#attributes' => [
        'class' => ['join-experience-level'],
      ],
      '#prefix' => '<div class="join-experience-level__intro">Beginner to more experienced</div>',
    ];

    $form['join_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes for the Facilitator (Optional)'),
      '#description' => $this->t('Any specific questions or context you want to share.'),
      '#rows' => 3,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Join this appointment'),
      '#button_type' => 'primary',
    ];

    return $this->addCacheContexts($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      $this->messenger()->addError($this->t('You must be logged in to join this appointment.'));
      return;
    }

    $nid = (int) $form_state->getValue('node_id');
    if ($nid <= 0) {
      $this->messenger()->addError($this->t('Unable to determine which appointment to join.'));
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('This appointment is unavailable.'));
      return;
    }

    if (!$node->hasField('field_appointment_attendees')) {
      $this->messenger()->addError($this->t('This appointment does not accept attendees.'));
      return;
    }

    if (!$node->access('view', $this->currentUser(), TRUE)->isAllowed()) {
      $this->messenger()->addError($this->t('You do not have access to this appointment.'));
      return;
    }

    $open_to_join = !$node->hasField('field_appointment_open_to_join')
      || $node->get('field_appointment_open_to_join')->isEmpty()
      || (bool) $node->get('field_appointment_open_to_join')->value;
    if (!$open_to_join) {
      $this->messenger()->addError($this->t('This appointment is not open to additional attendees.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    $attendee_field = $node->get('field_appointment_attendees');
    $attendee_values = $attendee_field->getValue();
    foreach ($attendee_values as $value) {
      if ((int) ($value['target_id'] ?? 0) === $uid) {
        $this->messenger()->addStatus($this->t('You are already on this appointment.'));
        $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
        return;
      }
    }

    $effective_capacity = appointment_facilitator_effective_capacity($node);
    $max_joiners = max(0, $effective_capacity - 1);
    $author_uid  = (int) $node->getOwnerId();
    $host_uid    = $node->hasField('field_appointment_host') && !$node->get('field_appointment_host')->isEmpty()
      ? (int) $node->get('field_appointment_host')->target_id : 0;
    $joiner_ids  = array_filter(
      array_column($attendee_values, 'target_id'),
      fn($id) => (int) $id !== $author_uid && (int) $id !== $host_uid && (int) $id > 0,
    );
    if (count($joiner_ids) >= $max_joiners) {
      $this->messenger()->addWarning($this->t('This appointment is full.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    // Block beginners from joining advanced-pace sessions.
    if ($node->hasField('field_appointment_experience') && !$node->get('field_appointment_experience')->isEmpty()) {
      $primary_exp  = (string) $node->get('field_appointment_experience')->value;
      $joiner_exp   = (string) $form_state->getValue('experience_level');
      if ($primary_exp === 'advanced' && $joiner_exp === 'beginner') {
        $this->messenger()->addError($this->t('This session is paced for experienced attendees. Please find a beginner-friendly session instead.'));
        $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
        return;
      }
    }

    if ($this->requiresPendingBadge($node) && !$this->userHasPendingBadgeForAppointment($node, $uid)) {
      $this->messenger()->addError($this->t('You can only join checkout sessions for badges currently pending on your profile.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    // Block joining when the member is already booked into another appointment
    // that overlaps this one's time. Members reported being tagged onto two
    // appointments at the same time (cycle review 2026-06-15); the client-side
    // slot disabling only covers the same view, not a different tool/session.
    if ($this->memberHasOverlappingAppointment($node, $uid)) {
      $this->messenger()->addError($this->t('You are already booked for another appointment at this time. Cancel that one first, or pick a different slot.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    $attendee_field->appendItem($uid);

    // Append joiner info to notes if possible.
    if ($node->hasField('field_appointment_note')) {
      $exp = $form_state->getValue('experience_level');
      $note = $form_state->getValue('join_note');
      $user_name = $this->currentUser()->getDisplayName();
      
      $extra_note = "\n\n--- Joiner: {$user_name} ---\nExperience: {$exp}";
      if (!empty($note)) {
        $extra_note .= "\nNote: {$note}";
      }
      
      $current_note = (string) $node->get('field_appointment_note')->value;
      $node->set('field_appointment_note', $current_note . $extra_note);
    }

    try {
      $node->save();
      $this->messenger()->addStatus($this->t('You have joined this appointment.'));
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->error('Failed to add attendee to appointment @id: @error', [
        '@id' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to join the appointment. Please try again.'));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Removes the current user from appointment attendees.
   */
  public function leaveSubmit(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      $this->messenger()->addError($this->t('You must be logged in to leave this appointment.'));
      return;
    }

    $nid = (int) $form_state->getValue('node_id');
    if ($nid <= 0) {
      $this->messenger()->addError($this->t('Unable to determine which appointment to leave.'));
      return;
    }

    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'appointment') {
      $this->messenger()->addError($this->t('This appointment is unavailable.'));
      return;
    }

    if (!$node->hasField('field_appointment_attendees')) {
      $this->messenger()->addError($this->t('This appointment does not track attendees.'));
      return;
    }

    if (!$node->access('view', $this->currentUser(), TRUE)->isAllowed()) {
      $this->messenger()->addError($this->t('You do not have access to this appointment.'));
      return;
    }

    $attendee_values = $node->get('field_appointment_attendees')->getValue();
    $remaining = [];
    $removed = FALSE;
    foreach ($attendee_values as $value) {
      $target_id = (int) ($value['target_id'] ?? 0);
      if ($target_id === $uid) {
        $removed = TRUE;
        continue;
      }
      if ($target_id > 0) {
        $remaining[] = ['target_id' => $target_id];
      }
    }

    if (!$removed) {
      $this->messenger()->addStatus($this->t('You are not currently on this appointment.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    $node->set('field_appointment_attendees', $remaining);
    try {
      $node->save();
      $this->messenger()->addStatus($this->t('You have left this appointment.'));
    }
    catch (\Exception $e) {
      $this->getLogger('appointment_facilitator')->error('Failed to remove attendee from appointment @id: @error', [
        '@id' => $node->id(),
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to leave the appointment. Please try again.'));
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Adds cache contexts so per-user content stays distinct.
   */
  protected function addCacheContexts(array $form): array {
    $contexts = $form['#cache']['contexts'] ?? [];
    $contexts[] = 'user';
    $contexts[] = 'url.path';
    $form['#cache']['contexts'] = array_values(array_unique($contexts));
    return $form;
  }

  /**
   * Returns TRUE when this appointment is a badge checkout session.
   */
  protected function requiresPendingBadge(NodeInterface $node): bool {
    if (!$node->hasField('field_appointment_purpose') || $node->get('field_appointment_purpose')->isEmpty()) {
      return FALSE;
    }
    return (string) $node->get('field_appointment_purpose')->value === 'checkout';
  }

  /**
   * Returns TRUE if the member already has an overlapping booked appointment.
   *
   * "Booked into" means the member is the owner (booker) or an attendee
   * (tagalong) of another non-cancelled appointment whose time overlaps this
   * one. Two ranges overlap when each starts before the other ends.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The appointment the member is trying to join.
   * @param int $uid
   *   The member's user id.
   *
   * @return bool
   *   TRUE if a conflicting appointment exists; FALSE if none (or this
   *   appointment has no resolvable time, in which case we cannot judge).
   */
  protected function memberHasOverlappingAppointment(NodeInterface $node, int $uid): bool {
    if ($uid <= 0
      || !$node->hasField('field_appointment_timerange')
      || $node->get('field_appointment_timerange')->isEmpty()) {
      return FALSE;
    }

    $range = $node->get('field_appointment_timerange')->first();
    $start = (int) ($range->value ?? 0);
    $end = (int) ($range->end_value ?? 0);
    if ($start <= 0 || $end <= 0) {
      return FALSE;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'appointment')
      ->condition('nid', $node->id(), '<>')
      // Overlap: existing.start < this.end AND existing.end > this.start.
      ->condition('field_appointment_timerange.value', $end, '<')
      ->condition('field_appointment_timerange.end_value', $start, '>')
      ->range(0, 1);

    // The member is the booker (owner) or a tagalong attendee.
    $who = $query->orConditionGroup()
      ->condition('uid', $uid)
      ->condition('field_appointment_attendees.target_id', $uid);
    $query->condition($who);

    // Ignore cancelled appointments (status unset counts as active).
    $not_cancelled = $query->orConditionGroup()
      ->condition('field_appointment_status', 'canceled', '<>')
      ->notExists('field_appointment_status');
    $query->condition($not_cancelled);

    return !empty($query->execute());
  }

  /**
   * Returns TRUE if user has at least one pending badge on this appointment.
   */
  protected function userHasPendingBadgeForAppointment(NodeInterface $node, int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    if (!$node->hasField('field_appointment_badges') || $node->get('field_appointment_badges')->isEmpty()) {
      // Checkout session without badge terms configured should not hard-block.
      return TRUE;
    }

    $pending = array_flip(_appointment_facilitator_load_pending_badge_term_ids($uid));
    if (!$pending) {
      return FALSE;
    }

    foreach ($node->get('field_appointment_badges')->getValue() as $item) {
      $tid = (int) ($item['target_id'] ?? 0);
      if ($tid > 0 && isset($pending[$tid])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns the appointment's badges where this user is blocked by an unmet
   * training-documentation requirement.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Badge terms requiring docs that the member has not had approved. Empty
   *   array means scheduling is allowed (no docs needed, or already approved,
   *   or the resolver / status service is unavailable).
   */
  protected function docsBlockedBadgesForAppointment(NodeInterface $node, int $uid): array {
    if ($uid <= 0) {
      return [];
    }
    if (!$node->hasField('field_appointment_badges') || $node->get('field_appointment_badges')->isEmpty()) {
      return [];
    }
    if (!\Drupal::hasService('appointment_facilitator.badge_user_status')) {
      return [];
    }
    /** @var \Drupal\appointment_facilitator\Service\BadgeUserStatusResolver $resolver */
    $resolver = \Drupal::service('appointment_facilitator.badge_user_status');

    $blocked = [];
    foreach ($node->get('field_appointment_badges')->referencedEntities() as $term) {
      if (!$term instanceof \Drupal\taxonomy\TermInterface) {
        continue;
      }
      $resolved = $resolver->resolve($uid, $term);
      $ds = $resolved['documentation_status'] ?? 'not_required';
      // Same rule as the scheduling gate in the .module file: anything short
      // of "not required" / "approved" blocks — including a class
      // registration, because that class is where the badge is issued.
      if ($ds !== 'not_required' && $ds !== 'approved') {
        $blocked[] = $term;
      }
    }
    return $blocked;
  }

}
