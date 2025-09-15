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
      '#description' => $this->t('When unchecked, Join displays only when effective capacity > 1.'),
      '#default_value' => (bool) $conf->get('show_always_join_cta'),
    ];
    return parent::buildForm($form, $form_state);
  }
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('appointment_facilitator.settings')
      ->set('show_always_join_cta', (bool) $form_state->getValue('show_always_join_cta'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}
