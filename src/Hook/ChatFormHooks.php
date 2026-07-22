<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
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
 * installed and the assistant is backed by an ai_agent.
 */
class ChatFormHooks implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The surface ID used to filter modes shown in the assistant.
   */
  protected const SURFACE = 'ai_assistant';

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
    if ($agent === '') {
      // Legacy assistants without an agent cannot be scoped.
      return;
    }

    // Only add the selector when there is actually something to scope.
    $has_modes = $this->modeManager->listModes($agent, self::SURFACE) !== [];
    $has_sub_agents = $this->modeManager->listSubAgents($agent) !== [];
    if (!$has_modes && !$has_sub_agents) {
      return;
    }

    $form['ai_agent_mode'] = [
      '#type' => 'ai_agent_mode_select',
      '#parent_agent' => $agent,
      '#surface' => self::SURFACE,
      '#default_value' => $this->currentValue($agent),
      '#weight' => -50,
      '#attributes' => ['class' => ['ai-agent-modes-mode']],
      '#wrapper_attributes' => ['class' => ['ai-agent-modes-selector']],
    ];

    $form['#attached']['library'][] = 'ai_agent_modes/chat';
    $form['#attached']['drupalSettings']['aiAgentModes'] = [
      'agent' => $agent,
      // Session-wide selection: the request subscriber falls back to it.
      'conversation' => '',
    ];
  }

  /**
   * Maps the stored selection to the select element's value.
   *
   * @param string $agent
   *   The parent agent plugin ID.
   *
   * @return string
   *   The current select value ('', 'mode:<id>' or 'agent:<id>').
   */
  protected function currentValue(string $agent): string {
    $current = $this->selectionStore->get($agent);
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
