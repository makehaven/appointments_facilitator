<?php

namespace Drupal\appointment_facilitator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase {
  protected function getEditableConfigNames() {
    return ['appointment_facilitator.settings'];
  }

  public function getFormId() {
    return 'appointment_facilitator_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $conf = $this->config('appointment_facilitator.settings');

    $form['show_always_join_cta'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Always show Join button'),
      '#description' => $this->t('When unchecked, the Join button only appears if effective capacity > 1.'),
      '#default_value' => (bool) $conf->get('show_always_join_cta'),
    ];

    $form['badges_vocab_machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Badges vocabulary machine name'),
      '#default_value' => $conf->get('badges_vocab_machine_name') ?: 'badges',
      '#description' => $this->t('Machine name of the vocabulary where badges live. Default: <code>badges</code>.'),
      '#required' => TRUE,
    ];

    $form['facilitator_profile_bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facilitator profile bundle machine name'),
      '#default_value' => $conf->get('facilitator_profile_bundle') ?: 'coordinator',
      '#description' => $this->t('If you use the Profile module, enter the bundle used for facilitators. Default: <code>coordinator</code>.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('appointment_facilitator.settings')
      ->set('show_always_join_cta', (bool) $form_state->getValue('show_always_join_cta'))
      ->set('badges_vocab_machine_name', (string) $form_state->getValue('badges_vocab_machine_name'))
      ->set('facilitator_profile_bundle', (string) $form_state->getValue('facilitator_profile_bundle'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
