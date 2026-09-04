<?php

namespace Drupal\appointment_facilitator\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Resolves a member's status with respect to a badge.
 *
 * Wraps BadgePrerequisiteGate plus a badge_request lookup to produce a single
 * state flag the badge page banner, the embedded compliance strip, and the
 * /badges card grid all consume.
 */
class BadgeUserStatusResolver {

  public const STATE_ANONYMOUS = 'anonymous';
  public const STATE_ACTIVE = 'active';
  public const STATE_PENDING = 'pending';
  public const STATE_QUIZ_NEEDED = 'quiz_needed';
  public const STATE_PREREQS_MISSING = 'prereqs_missing';
  public const STATE_DOCS_REQUIRED = 'docs_required';
  public const STATE_SUSPENDED = 'suspended';
  public const STATE_EXPIRED = 'expired';

  /**
   * Documentation status values surfaced on resolve() output as
   * `documentation_status`. Independent of the primary `state` so the badge
   * page can show a secondary docs pill (submitted / under review / approved)
   * regardless of which step the member is on.
   */
  public const DOCS_NOT_REQUIRED = 'not_required';
  public const DOCS_NOT_SUBMITTED = 'not_submitted';
  public const DOCS_PENDING_REVIEW = 'pending_review';
  public const DOCS_APPROVED = 'approved';
  /**
   * The badge requires documentation, but the member is registered for (or
   * attended) a class that issues this badge, so the form is not needed —
   * the instructor issues the badge at the end of class. Treated like an
   * unapproved status by the facilitator-scheduling gates (the class *is*
   * the checkout), but the badge page must not ask for the form.
   */
  public const DOCS_SATISFIED_BY_CLASS = 'satisfied_by_class';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BadgePrerequisiteGate $badgeGate,
  ) {}

  /**
   * Resolves the user's state for the given badge.
   *
   * @return array
   *   Keys: state (string), badge_request (NodeInterface|null), gate (array
   *   from BadgePrerequisiteGate::evaluate), documentation_status (string,
   *   one of the DOCS_* constants), cache_tags (string[]).
   */
  public function resolve(int $uid, TermInterface $badge): array {
    $cache_tags = ['taxonomy_term:' . $badge->id()];

    if ($uid <= 0) {
      return [
        'state' => self::STATE_ANONYMOUS,
        'badge_request' => NULL,
        'gate' => [],
        'documentation_status' => $this->resolveAnonymousDocumentationStatus($badge),
        'cache_tags' => $cache_tags,
      ];
    }

    $request = $this->loadLatestBadgeRequest($uid, (int) $badge->id());
    if ($request) {
      $cache_tags[] = 'node:' . $request->id();
    }
    // Always invalidate per-user when any badge_request changes.
    $cache_tags[] = 'node_list:badge_request';

    $status = '';
    if ($request && $request->hasField('field_badge_status') && !$request->get('field_badge_status')->isEmpty()) {
      $status = strtolower(trim((string) $request->get('field_badge_status')->value));
    }

    // Gate is computed for all logged-in users so documentation_status is
    // available on every return path, even when the primary state would skip
    // it (e.g., already-active members can still see the "approved" pill).
    $gate = $this->badgeGate->evaluate($uid, $badge);
    $documentation_status = $this->documentationStatusFromGate($gate);

    if ($status === 'active') {
      // An active badge_request implies the docs gate was satisfied —
      // either via an approved webform submission, or via an admin override
      // that bypassed the webform's status field. Either way, showing "under
      // review" next to a green ✓ You have this badge would be confusing.
      return [
        'state' => self::STATE_ACTIVE,
        'badge_request' => $request,
        'gate' => $gate,
        'documentation_status' => $documentation_status === self::DOCS_NOT_REQUIRED
          ? self::DOCS_NOT_REQUIRED
          : self::DOCS_APPROVED,
        'cache_tags' => $cache_tags,
      ];
    }
    if ($status === 'suspended') {
      return [
        'state' => self::STATE_SUSPENDED,
        'badge_request' => $request,
        'gate' => $gate,
        'documentation_status' => $documentation_status,
        'cache_tags' => $cache_tags,
      ];
    }
    if ($status === 'expired') {
      return [
        'state' => self::STATE_EXPIRED,
        'badge_request' => $request,
        'gate' => $gate,
        'documentation_status' => $documentation_status,
        'cache_tags' => $cache_tags,
      ];
    }

    if (!empty($gate['prerequisites_missing'])) {
      return [
        'state' => self::STATE_PREREQS_MISSING,
        'badge_request' => $request,
        'gate' => $gate,
        'documentation_status' => $documentation_status,
        'cache_tags' => $cache_tags,
      ];
    }

    // Docs are intentionally NOT a primary state anymore — members on a
    // docs-required badge should still see the normal Review / Quiz path,
    // with documentation surfaced separately as a secondary pill (and
    // enforced at the facilitator-checkout scheduling step). The
    // STATE_DOCS_REQUIRED constant is preserved for back-compat with any
    // call site that still references it, but resolve() no longer emits it.

    if ($request && in_array($status, ['', 'pending'], TRUE)) {
      return [
        'state' => self::STATE_PENDING,
        'badge_request' => $request,
        'gate' => $gate,
        'documentation_status' => $documentation_status,
        'cache_tags' => $cache_tags,
      ];
    }

    return [
      'state' => self::STATE_QUIZ_NEEDED,
      'badge_request' => $request,
      'gate' => $gate,
      'documentation_status' => $documentation_status,
      'cache_tags' => $cache_tags,
    ];
  }

  /**
   * Maps a gate evaluate() array to a DOCS_* status string.
   */
  protected function documentationStatusFromGate(array $gate): string {
    if (empty($gate['requires_documentation'])) {
      return self::DOCS_NOT_REQUIRED;
    }
    if (!empty($gate['documentation_approved'])) {
      return self::DOCS_APPROVED;
    }
    // A class registration outranks "under review": a member who took the
    // class AND filled in the form (because the page used to ask them to)
    // should not be told staff still has to review anything.
    if (!empty($gate['class_registration_satisfies_docs'])) {
      return self::DOCS_SATISFIED_BY_CLASS;
    }
    if (!empty($gate['documentation_submitted'])) {
      return self::DOCS_PENDING_REVIEW;
    }
    return self::DOCS_NOT_SUBMITTED;
  }

  /**
   * Documentation status for anonymous viewers — we can't check submissions
   * without a user, so we collapse to "not required" / "not submitted" based
   * solely on whether the badge term has a documentation webform attached.
   */
  protected function resolveAnonymousDocumentationStatus(TermInterface $badge): string {
    if (!$badge->hasField('field_training_documentation') || $badge->get('field_training_documentation')->isEmpty()) {
      return self::DOCS_NOT_REQUIRED;
    }
    return self::DOCS_NOT_SUBMITTED;
  }

  /**
   * Loads the most recently changed badge_request for this user/badge pair.
   */
  protected function loadLatestBadgeRequest(int $uid, int $tid): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_requested.target_id', $tid)
      ->sort('changed', 'DESC')
      ->execute();

    if (!$nids) {
      return NULL;
    }

    // loadMultiple() returns keyed by nid, not in query order — re-order to the
    // changed-DESC nid order so the fallback below is "most recently changed".
    $nodes = $storage->loadMultiple($nids);
    $ordered = [];
    foreach ($nids as $nid) {
      if (isset($nodes[$nid]) && $nodes[$nid] instanceof NodeInterface) {
        $ordered[] = $nodes[$nid];
      }
    }

    // An active badge_request means the member actually holds the badge; it
    // must win over a more-recently-changed but stale 'pending' duplicate for
    // the same badge. Otherwise the badge page banner and tool-page next-steps
    // report "pending" next to an earned badge (reported on the waterjet badge,
    // cycle review 2026-06-15). Fall back to the most-recently-changed request.
    foreach ($ordered as $node) {
      if ($node->hasField('field_badge_status')
        && strtolower(trim((string) $node->get('field_badge_status')->value)) === 'active') {
        return $node;
      }
    }

    return $ordered ? reset($ordered) : NULL;
  }

}
