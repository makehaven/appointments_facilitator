<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Filter form for the facilitator stats report.
 */
class StatsFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_facilitator_stats_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $filters = [], array $purpose_labels = []): array {
    $defaults = $filters['raw'] ?? [];

    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'appointment-facilitator-filter';

    $form['start'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#default_value' => $defaults['start'] ?? '',
    ];

    $form['end'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#default_value' => $defaults['end'] ?? '',
    ];

    $purpose_options = ['all' => $this->t('All purposes')];
    foreach ($purpose_labels as $value => $label) {
      $purpose_options[$value] = $label;
    }

    $form['purpose'] = [
      '#type' => 'select',
      '#title' => $this->t('Purpose'),
      '#options' => $purpose_options,
      '#default_value' => $defaults['purpose'] ?? 'all',
    ];

    $form['include_cancelled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include cancelled appointments'),
      '#default_value' => $defaults['include_cancelled'] ?? 0,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $params = [];
    $start = $form_state->getValue('start');
    $end = $form_state->getValue('end');
    $purpose = $form_state->getValue('purpose');
    $include_cancelled = $form_state->getValue('include_cancelled');
    $request = \Drupal::request();
    $current_sort = $request->query->get('sort');
    $current_order = $request->query->get('order');

    if (!empty($start)) {
      $params['start'] = $start;
    }
    if (!empty($end)) {
      $params['end'] = $end;
    }
    if (!empty($purpose) && $purpose !== 'all') {
      $params['purpose'] = $purpose;
    }
    if ($include_cancelled) {
      $params['include_cancelled'] = 1;
    }
    if (!empty($current_sort)) {
      $params['sort'] = $current_sort;
    }
    if (!empty($current_order)) {
      $params['order'] = $current_order;
    }

    $form_state->setRedirect('appointment_facilitator.stats', [], ['query' => $params]);
  }

}
