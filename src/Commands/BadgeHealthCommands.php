<?php

declare(strict_types=1);

namespace Drupal\appointment_facilitator\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\Entity\Role;
use Drush\Commands\DrushCommands;

/**
 * Reports badges a member cannot finish, and badges that outlived their tool.
 *
 * Both checks exist because the 2026-08-25 staff badge session found the same
 * failure twice from opposite directions: a badge that looks bookable and is
 * not (Blind Stitch Hemmer, Vacuum Forming), and a badge still listed for a
 * tool that left the building (Sewing Machine Heavy Duty Singers). Neither is
 * visible anywhere in the admin UI, so both were found by a member.
 */
class BadgeHealthCommands extends DrushCommands {

  /**
   * Item statuses that mean the tool is no longer in service.
   */
  protected const RETIRED_STATUSES = ['Gone', 'Storage'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Lists checkout badges no member can actually book.
   *
   * A badge is bookable only when all three of these line up on the same
   * person: they are named in the badge's `field_badge_issuer`, they hold a
   * role carrying `approve badge requests` (without it they cannot grant the
   * badge even if they meet the member), and they have posted coordinator
   * hours inside the window the badge page shows. The badge page's schedule
   * view additionally filters on the `facilitator` role, so an issuer without
   * it is invisible to booking regardless of their hours.
   *
   * @command appointment-facilitator:unbookable-badges
   * @option days How far ahead to look for posted hours. Defaults to 14, matching the badge page's own window.
   * @usage drush appointment-facilitator:unbookable-badges
   *   Checkout badges with no reachable issuer in the next two weeks.
   */
  public function unbookableBadges(array $options = ['days' => 14]): int {
    $days = max(1, (int) $options['days']);
    $approver_roles = $this->rolesWithPermission('approve badge requests');

    if (!$approver_roles) {
      $this->logger()->error('No role carries "approve badge requests" — every checkout badge is unbookable.');
      return self::EXIT_FAILURE;
    }

    $rows = [];
    foreach ($this->activeBadges() as $tid => $name) {
      if ($this->checkoutRequirement($tid) !== 'yes') {
        continue;
      }
      // A badge already flagged inactive is not offered to members, so it
      // cannot strand anyone — reporting it here would bury the live ones.
      if ($this->badgeIsInactive($tid)) {
        continue;
      }

      $issuers = $this->issuers($tid);
      if (!$issuers) {
        $rows[] = [$name, 'no issuer named', ''];
        continue;
      }

      $reasons = [];
      $bookable = FALSE;
      foreach ($issuers as $uid => $issuer_name) {
        $roles = $this->userRoles($uid);
        $can_approve = (bool) array_intersect($roles, $approver_roles);
        $listed = in_array('facilitator', $roles, TRUE);
        $has_hours = $this->hasHoursWithin($uid, $days);

        if ($can_approve && $listed && $has_hours) {
          $bookable = TRUE;
          break;
        }

        $missing = [];
        if (!$listed) {
          $missing[] = 'not a facilitator';
        }
        if (!$can_approve) {
          $missing[] = 'cannot approve';
        }
        if (!$has_hours) {
          $missing[] = 'no hours in ' . $days . 'd';
        }
        $reasons[] = $issuer_name . ' (' . implode(', ', $missing) . ')';
      }

      if (!$bookable) {
        $rows[] = [$name, count($issuers) . ' issuer(s), none reachable', implode('; ', $reasons)];
      }
    }

    if (!$rows) {
      $this->output()->writeln('Every checkout badge has a reachable issuer in the next ' . $days . ' days.');
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln('Checkout badges no member can book (' . count($rows) . '):');
    $this->output()->writeln('');
    foreach ($rows as [$name, $summary, $detail]) {
      $this->output()->writeln('  ' . $name . ' — ' . $summary);
      if ($detail !== '') {
        $this->output()->writeln('      ' . $detail);
      }
    }
    $this->output()->writeln('');
    $this->output()->writeln('Fix by granting the missing role, moving the issuer list to someone who holds it,');
    $this->output()->writeln('or — if the tool has left the building — retiring the badge:');
    $this->output()->writeln('  drush appointment-facilitator:retired-badges --fix');

    return self::EXIT_SUCCESS;
  }

  /**
   * Lists badges whose every tool is retired but which are still listed.
   *
   * The convention (confirmed by JR, 2026-08-25) is that a retired tool gets a
   * status change on the *tool* — `field_item_status` moves to Gone or
   * Storage. Nothing propagates that to the badge, so the badge keeps
   * appearing in /badges and members keep passing quizzes for machines that no
   * longer exist. Retiring a badge means setting `field_badge_inactive`, which
   * is what the badges view already filters on.
   *
   * A badge is only reported when EVERY item referencing it is retired. Badges
   * like "Prusa 3D Printers" reference machines that have come and gone and
   * are very much alive; those must not be touched.
   *
   * @command appointment-facilitator:retired-badges
   * @option fix Set field_badge_inactive on the badges listed.
   * @usage drush appointment-facilitator:retired-badges
   *   Report badges whose tools have all left the building.
   * @usage drush appointment-facilitator:retired-badges --fix
   *   Report them and mark them inactive.
   */
  public function retiredBadges(array $options = ['fix' => FALSE]): int {
    $fix = (bool) $options['fix'];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $stale = [];

    foreach ($this->activeBadges() as $tid => $name) {
      if ($this->badgeIsInactive($tid)) {
        continue;
      }
      $statuses = $this->toolStatuses($tid);
      if (!$statuses) {
        // No tool references this badge at all — that is a different problem
        // (an orphan badge) and is not safe to auto-retire.
        continue;
      }
      $live = array_diff($statuses, self::RETIRED_STATUSES);
      if ($live) {
        continue;
      }
      $stale[$tid] = [$name, implode(', ', array_unique($statuses)), count($statuses)];
    }

    if (!$stale) {
      $this->output()->writeln('No listed badge has all of its tools retired.');
      return self::EXIT_SUCCESS;
    }

    $this->output()->writeln('Badges still listed whose every tool is retired (' . count($stale) . '):');
    $this->output()->writeln('');
    foreach ($stale as $tid => [$name, $statuses, $count]) {
      $this->output()->writeln('  [' . $tid . '] ' . $name . ' — ' . $count . ' tool(s): ' . $statuses);
    }
    $this->output()->writeln('');

    if (!$fix) {
      $this->output()->writeln('Re-run with --fix to set field_badge_inactive on these badges.');
      return self::EXIT_SUCCESS;
    }

    $marked = 0;
    foreach (array_keys($stale) as $tid) {
      $term = $storage->load($tid);
      if (!$term || !$term->hasField('field_badge_inactive')) {
        $this->logger()->warning('Badge ' . $tid . ' has no field_badge_inactive — skipped.');
        continue;
      }
      $term->set('field_badge_inactive', 1);
      $term->save();
      $marked++;
    }

    $this->logger()->success('Marked ' . $marked . ' badge(s) inactive.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Returns tid => name for every published badge term.
   */
  protected function activeBadges(): array {
    return $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'name'])
      ->condition('t.vid', 'badges')
      ->condition('t.status', 1)
      ->orderBy('t.name')
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Returns the checkout requirement value for a badge ('yes', 'no', 'class').
   */
  protected function checkoutRequirement(int $tid): string {
    $value = $this->database->select('taxonomy_term__field_badge_checkout_requirement', 'r')
      ->fields('r', ['field_badge_checkout_requirement_value'])
      ->condition('r.entity_id', $tid)
      ->execute()
      ->fetchField();

    return (string) ($value ?: '');
  }

  /**
   * TRUE when the badge is already flagged inactive.
   */
  protected function badgeIsInactive(int $tid): bool {
    $value = $this->database->select('taxonomy_term__field_badge_inactive', 'i')
      ->fields('i', ['field_badge_inactive_value'])
      ->condition('i.entity_id', $tid)
      ->execute()
      ->fetchField();

    return (bool) $value;
  }

  /**
   * Returns uid => name for everyone named as an issuer of a badge.
   */
  protected function issuers(int $tid): array {
    $query = $this->database->select('taxonomy_term__field_badge_issuer', 'i');
    $query->join('users_field_data', 'u', 'u.uid = i.field_badge_issuer_target_id');
    $query->fields('i', ['field_badge_issuer_target_id']);
    $query->fields('u', ['name']);
    $query->condition('i.entity_id', $tid);

    return $query->execute()->fetchAllKeyed();
  }

  /**
   * Returns the status names of every published tool referencing a badge.
   *
   * Both `field_member_badges` and `field_additional_badges` count — a tool
   * can reach a badge through either.
   */
  protected function toolStatuses(int $tid): array {
    $statuses = [];

    foreach ([
      'node__field_member_badges' => 'field_member_badges_target_id',
      'node__field_additional_badges' => 'field_additional_badges_target_id',
    ] as $table => $column) {
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }
      $query = $this->database->select($table, 'b');
      $query->join('node_field_data', 'n', 'n.nid = b.entity_id');
      $query->leftJoin('node__field_item_status', 's', 's.entity_id = n.nid');
      $query->leftJoin('taxonomy_term_field_data', 'st', 'st.tid = s.field_item_status_target_id');
      $query->addField('st', 'name', 'status');
      $query->condition('b.' . $column, $tid);
      $query->condition('n.type', 'item');
      $query->condition('n.status', 1);

      foreach ($query->execute()->fetchCol() as $status) {
        // A tool with no status set is not evidence of retirement.
        $statuses[] = (string) ($status ?: 'unset');
      }
    }

    return $statuses;
  }

  /**
   * Returns the role ids held by a user, including 'authenticated'.
   */
  protected function userRoles(int $uid): array {
    $roles = $this->database->select('user__roles', 'r')
      ->fields('r', ['roles_target_id'])
      ->condition('r.entity_id', $uid)
      ->execute()
      ->fetchCol();

    $roles[] = 'authenticated';

    return array_map('strval', $roles);
  }

  /**
   * TRUE when the user has posted coordinator hours inside the window.
   */
  protected function hasHoursWithin(int $uid, int $days): bool {
    $query = $this->database->select('profile', 'p');
    $query->join('profile__field_coordinator_hours', 'ch', 'ch.entity_id = p.profile_id');
    $query->condition('p.uid', $uid);
    $query->condition('p.type', 'coordinator');
    $query->condition('p.status', 1);
    $query->condition('ch.field_coordinator_hours_value', \Drupal::time()->getRequestTime(), '>=');
    $query->condition('ch.field_coordinator_hours_value', \Drupal::time()->getRequestTime() + ($days * 86400), '<=');
    $query->range(0, 1);
    $query->addField('p', 'profile_id');

    return (bool) $query->execute()->fetchField();
  }

  /**
   * Returns the ids of every role carrying a permission.
   */
  protected function rolesWithPermission(string $permission): array {
    $matching = [];
    foreach (Role::loadMultiple() as $role) {
      if ($role->isAdmin() || $role->hasPermission($permission)) {
        $matching[] = $role->id();
      }
    }

    return $matching;
  }

}
