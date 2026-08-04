<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Settings for how the mode selector is presented.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_agent_modes_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_agent_modes.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['canvas_position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Where should the dropdown appear in the Drupal Canvas AI panel?'),
      '#default_value' => $this->config('ai_agent_modes.settings')->get('canvas_position') ?: 'toolbar',
      '#options' => [
        'top' => $this->optionLabel('top', $this->t('Top of the panel')),
        'above_input' => $this->optionLabel('above_input', $this->t('Above the message box')),
        'below_input' => $this->optionLabel('below_input', $this->t('Under the message box')),
        'toolbar' => $this->optionLabel('toolbar', $this->t('In the toolbar (compact)')),
      ],
      '#description' => $this->t('Applies to everyone using the assistant.'),
    ];

    $form['chatbot_position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Where should the dropdown appear in the AI Chatbot panel?'),
      '#default_value' => $this->config('ai_agent_modes.settings')->get('chatbot_position') ?: 'above_chat',
      '#options' => [
        'above_chat' => $this->t('Above the chat, under the panel header'),
        'below_input' => $this->t('Under the message box'),
        'header' => $this->t('In the panel header, beside the assistant name'),
      ],
      '#description' => $this->t('The AI Chatbot panel is a separate surface from the Drupal Canvas AI panel, so it has its own placement. Applies to everyone using the chatbot.'),
    ];

    $enforcement = $this->config('ai_agent_modes.settings')->get('tool_scope_enforcement');
    $form['tool_scope_enforcement'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enforce tool scope'),
      // An absent value counts as enabled, matching how the mode manager reads
      // it, so a site upgraded before this setting existed keeps the behaviour.
      '#default_value' => $enforcement === NULL ? TRUE : (bool) $enforcement,
      '#description' => $this->t('When this is off, every mode set to <em>Steer and withhold</em> behaves as <em>Steer only</em>: the assistant is still pointed at the right sub-agents, but nothing is withheld from it. Switch it off to rule the withholding out as the cause of a problem, without editing any mode.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds a radio label with a small diagram of the position.
   *
   * Each diagram is the assistant panel in miniature: the outer box is the
   * panel, the small box at its foot is the message box, and the blue bar is
   * where the dropdown will sit.
   *
   * @param string $variant
   *   The position machine name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   The option text.
   *
   * @return \Drupal\Core\Render\MarkupInterface
   *   The label markup.
   */
  protected function optionLabel(string $variant, TranslatableMarkup $text): MarkupInterface {
    // The message box outline sits higher in the below_input diagram so the
    // bar under it still fits inside the panel outline.
    $box_y = $variant === 'below_input' ? 15.5 : 19.5;
    $bar = match ($variant) {
      'top' => '<rect x="5" y="4" width="34" height="3.5" rx="1.5" fill="#0d6efd"/>',
      'above_input' => '<rect x="5" y="15" width="34" height="3.5" rx="1.5" fill="#0d6efd"/>',
      'below_input' => '<rect x="5" y="28" width="34" height="3.5" rx="1.5" fill="#0d6efd"/>',
      default => '<circle cx="9" cy="27" r="1.6" fill="#999"/><circle cx="35" cy="27" r="1.6" fill="#999"/>'
        . '<rect x="13" y="25.5" width="18" height="3" rx="1.5" fill="#0d6efd"/>',
    };
    $svg = '<svg width="80" height="62" viewBox="0 0 44 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"'
      . ' style="vertical-align:middle;margin-right:10px;">'
      . '<rect x="1" y="1" width="42" height="32" rx="3" fill="#fff" stroke="#c2c8d0"/>'
      . '<rect x="5" y="' . $box_y . '" width="34" height="11" rx="2" fill="none" stroke="#c2c8d0"/>'
      . $bar
      . '</svg>';
    return Markup::create($svg . '<span style="vertical-align:middle;">' . $text . '</span>');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_agent_modes.settings')
      ->set('canvas_position', $form_state->getValue('canvas_position'))
      ->set('chatbot_position', $form_state->getValue('chatbot_position'))
      ->set('tool_scope_enforcement', (bool) $form_state->getValue('tool_scope_enforcement'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
