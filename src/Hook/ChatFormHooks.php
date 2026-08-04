<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ai_agent_modes\EventSubscriber\AssistantScopeSubscriber;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\SelectionStoreInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Integrates the mode selector into the AI Assistant chat interface.
 *
 * The dropdown is added to the same chat form the AI Assistant already
 * renders (form ID ai_foundation_chat), and the parent agent is read from the
 * assistant's own configuration, so no separate UI or manual agent ID is
 * needed. The integration is soft: it does nothing unless ai_assistant_api is
 * installed.
 *
 * An assistant with no agent behind it is still offered the generic modes,
 * whose instruction is applied to the assistant's own system prompt by
 * AssistantScopeSubscriber. Its selection is stored against the assistant
 * rather than an agent, because there is no agent run to key it to.
 */
class ChatFormHooks implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ChatFormHooks object.
   *
   * @param object|null $assistantRunner
   *   The AI Assistant API runner, or NULL when ai_assistant_api is absent.
   * @param \Drupal\ai_agent_modes\ModeManagerInterface $modeManager
   *   The mode manager.
   * @param \Drupal\ai_agent_modes\SelectionStoreInterface $selectionStore
   *   The selection store.
   */
  public function __construct(
    protected ?object $assistantRunner,
    protected ModeManagerInterface $modeManager,
    protected SelectionStoreInterface $selectionStore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // Soft dependency: ai_assistant_api may not be installed.
    $runner = $container->has('ai_assistant_api.runner')
      ? $container->get('ai_assistant_api.runner')
      : NULL;
    return new static(
      $runner,
      $container->get('ai_agent_modes.manager'),
      $container->get('ai_agent_modes.selection_store'),
    );
  }

  /**
   * Implements hook_form_FORM_ID_alter() for the AI Assistant chat form.
   */
  #[Hook('form_ai_foundation_chat_alter')]
  public function chatFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($this->assistantRunner === NULL || !method_exists($this->assistantRunner, 'getAssistant')) {
      return;
    }
    $assistant = $this->assistantRunner->getAssistant();
    if ($assistant === NULL) {
      return;
    }
    $agent = (string) ($assistant->get('ai_agent') ?? '');
    // The assistant is reached through a soft-typed runner, so ask before
    // reading its ID.
    $assistant_id = method_exists($assistant, 'id') ? (string) ($assistant->id() ?? '') : '';

    if ($agent !== '') {
      // Backed by an agent: the selection belongs to that agent, and the agent
      // subscriber applies it.
      $scope = $agent;
      $has_modes = $this->modeManager->listModes($agent, ModeManagerInterface::SURFACE_ASSISTANT, $assistant_id) !== [];
      $has_sub_agents = $this->modeManager->listSubAgents($agent) !== [];
      if (!$has_modes && !$has_sub_agents) {
        return;
      }
    }
    else {
      // No agent, so there are no sub-agents to pick and no agent run to scope.
      // Such an assistant can still be steered by a generic mode's own
      // instruction, applied to the assistant's system prompt, with the
      // selection stored against the assistant rather than an agent.
      if ($assistant_id === '') {
        return;
      }
      $scope = AssistantScopeSubscriber::ASSISTANT_SCOPE_PREFIX . $assistant_id;
      if ($this->modeManager->listModes('', ModeManagerInterface::SURFACE_ASSISTANT, $assistant_id) === []) {
        return;
      }
    }

    $form['ai_agent_mode'] = [
      '#type' => 'ai_agent_mode_select',
      '#parent_agent' => $agent,
      '#surface' => ModeManagerInterface::SURFACE_ASSISTANT,
      '#assistant' => $assistant_id,
      '#default_value' => $this->currentValue($scope),
      '#weight' => -50,
      '#attributes' => ['class' => ['ai-agent-modes-mode']],
      '#wrapper_attributes' => ['class' => ['ai-agent-modes-selector']],
    ];

    $form['#attached']['library'][] = 'ai_agent_modes/chat';
    $form['#attached']['drupalSettings']['aiAgentModes'] = [
      // Either an agent ID or "assistant:<id>". The selection endpoint treats
      // this as an opaque key, so no route or JavaScript change is needed.
      'agent' => $scope,
      // Session-wide selection: the request subscriber falls back to it.
      'conversation' => '',
    ];
  }

  /**
   * Maps the stored selection to the select element's value.
   *
   * @param string $scope
   *   The selection scope key: either a parent agent plugin ID, or
   *   "assistant:<id>" for an assistant that has no agent.
   *
   * @return string
   *   The current select value ('', 'mode:<id>' or 'agent:<id>').
   */
  protected function currentValue(string $scope): string {
    $current = $this->selectionStore->get($scope);
    if ($current === NULL) {
      return '';
    }
    if (!empty($current['mode_id'])) {
      return 'mode:' . $current['mode_id'];
    }
    if (!empty($current['sub_agents'])) {
      return 'agent:' . reset($current['sub_agents']);
    }
    return '';
  }

}
