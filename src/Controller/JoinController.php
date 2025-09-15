<?php

namespace Drupal\appointment_facilitator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class JoinController extends ControllerBase {

  public function join(NodeInterface $node) {
    if ($node->bundle() !== 'appointment') {
      $this->messenger()->addError('Not an appointment.');
      return new RedirectResponse('/');
    }

    // Appointment-level capacity.
    $cap = (int) ($node->get('field_appointment_capacity')->value ?? 1);
    if ($cap <= 0) {
      $cap = 1;
    }

    // Current attendees.
    $attendees = $node->hasField('field_appointment_attendees')
      ? $node->get('field_appointment_attendees')->getValue()
      : [];
    $current = count($attendees);

    // Already joined?
    $uid = (int) $this->currentUser()->id();
    foreach ($attendees as $item) {
      if ((int) ($item['target_id'] ?? 0) === $uid) {
        $this->messenger()->addStatus('You are already on this appointment.');
        return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
      }
    }

    if ($current >= $cap) {
      $this->messenger()->addWarning('This appointment is full.');
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $node->get('field_appointment_attendees')->appendItem($uid);
    $node->save();
    $this->messenger()->addStatus('You have joined this appointment.');
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }
}
