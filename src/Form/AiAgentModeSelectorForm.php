<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_agent_modes\SelectionStoreInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A thin selector form that scopes a conversation to a mode or sub-agent.
 *
 * The form is intentionally UI-agnostic: it can be embedded in any chat
 * surface via the selector block, and it persists the selection per user (and
 * per conversation when a conversation ID is supplied).
 */
class AiAgentModeSelectorForm extends FormBase {

  /**
   * Constructs an AiAgentModeSelectorForm.
   *
   * @param \Drupal\ai_agent_modes\SelectionStoreInterface $selectionStore
   *   The selection store.
   */
  public function __construct(
    protected SelectionStoreInterface $selectionStore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_agent_modes.selection_store'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_agent_mode_selector_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $agent = '', string $surface = '', string $conversation = ''): array {
    if ($agent === '') {
      return [
        '#markup' => $this->t('No parent agent configured for the mode selector.'),
      ];
    }

    $form_state->set('agent', $agent);
    $form_state->set('conversation', $conversation);

    $current = $this->selectionStore->get($agent, $conversation !== '' ? $conversation : NULL);
    $default = '';
    if ($current !== NULL) {
      if (!empty($current['mode_id'])) {
        $default = 'mode:' . $current['mode_id'];
      }
      elseif (!empty($current['sub_agents'])) {
        $default = 'agent:' . reset($current['sub_agents']);
      }
    }

    $form['mode'] = [
      '#type' => 'ai_agent_mode_select',
      '#parent_agent' => $agent,
      '#surface' => $surface,
      '#default_value' => $default,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Apply mode'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $agent = (string) $form_state->get('agent');
    $conversation = (string) $form_state->get('conversation');
    $conversation_id = $conversation !== '' ? $conversation : NULL;
    $value = (string) $form_state->getValue('mode');

    if ($value === '') {
      $this->selectionStore->clear($agent, $conversation_id);
      $this->messenger()->addStatus($this->t('Mode cleared. The assistant will use its full set of sub-agents.'));
      return;
    }

    if (str_starts_with($value, 'mode:')) {
      $this->selectionStore->set($agent, [], substr($value, 5), $conversation_id);
    }
    elseif (str_starts_with($value, 'agent:')) {
      $this->selectionStore->set($agent, [substr($value, 6)], NULL, $conversation_id);
    }
    $this->messenger()->addStatus($this->t('Mode applied. The assistant is now scoped for this conversation.'));
  }

}
