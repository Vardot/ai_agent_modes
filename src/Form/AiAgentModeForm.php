<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_agent_modes\AiAgentModeInterface;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add/edit form for the AI agent mode config entity.
 */
class AiAgentModeForm extends EntityForm {

  /**
   * The mode manager.
   */
  protected ModeManagerInterface $modeManager;

  /**
   * Constructs an AiAgentModeForm.
   *
   * @param \Drupal\ai_agent_modes\ModeManagerInterface $mode_manager
   *   The mode manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ModeManagerInterface $mode_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->modeManager = $mode_manager;
    // EntityForm already declares $entityTypeManager (untyped); reuse it.
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_agent_modes.manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ai_agent_modes\AiAgentModeInterface $mode */
    $mode = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $mode->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $mode->id(),
      '#machine_name' => [
        'exists' => [$this, 'modeExists'],
      ],
      '#disabled' => !$mode->isNew(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $mode->getDescription(),
      '#rows' => 2,
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $mode->isNew() ? TRUE : $mode->status(),
    ];

    $selected_agent = (string) ($form_state->getValue('agent') ?? $mode->getAgent());
    $form['agent'] = [
      '#type' => 'select',
      '#title' => $this->t('Parent agent'),
      '#description' => $this->t('The agent this mode scopes. Leave empty for a generic mode that can apply to any agent.'),
      '#options' => ['' => $this->t('- Any (generic) -')] + $this->agentOptions(),
      '#default_value' => $selected_agent,
      '#ajax' => [
        'callback' => '::updateSubAgents',
        'wrapper' => 'ai-agent-mode-sub-agents',
      ],
    ];

    $form['sub_agents'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Sub-agents in this mode'),
      '#description' => $this->t('The curated subset of the parent agent’s sub-agents the orchestrator is restricted to. A smaller set means a smaller context window.'),
      '#options' => $this->subAgentOptions($selected_agent),
      '#default_value' => $mode->getSubAgents(),
      '#prefix' => '<div id="ai-agent-mode-sub-agents">',
      '#suffix' => '</div>',
    ];

    $form['scope_strength'] = [
      '#type' => 'radios',
      '#title' => $this->t('Scope strength'),
      '#default_value' => $mode->getScopeStrength(),
      '#options' => [
        AiAgentModeInterface::SCOPE_GUIDE => $this->t('Steer only (recommended). Name the sub-agents in the prompt and leave every tool available.'),
        AiAgentModeInterface::SCOPE_RESTRICT => $this->t('Steer and withhold. Also hide the sub-agent tools this mode does not name, so they are never sent to the model.'),
      ],
      '#description' => $this->t('Withholding shrinks what the model has to weigh up, at the cost of the assistant genuinely not being able to reach the other sub-agents. Four things to know before choosing it. Only sub-agent tools are ever withheld: the agent keeps its own tools, and a sub-agent that is kept keeps all of its own. It needs a parent agent and at least one sub-agent ticked above. It narrows the context window rather than granting or denying permission, because each tool still authorises itself when it runs. And if the model calls a withheld sub-agent anyway, which can happen when the mode is changed part-way through a conversation, the current AI provider code raises a PHP error instead of answering gracefully, so choose the mode before starting the conversation.'),
    ];

    $form['system_prompt_addition'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt addition'),
      '#description' => $this->t('A short scoped directive prepended to the system prompt when this mode is active. Drupal tokens are replaced, so text such as [site:name] or [user:display-name] arrives resolved.'),
      '#default_value' => $mode->getSystemPromptAddition(),
      '#rows' => 3,
    ];
    $assistant_options = $this->assistantOptions();
    if ($assistant_options !== []) {
      $form['assistants'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('AI Assistants'),
        '#description' => $this->t('Optional. Offer this mode only for the selected AI Assistants. Leave every box unchecked to offer it for every assistant. A mode limited to an assistant is not offered where there is no assistant, such as the Drupal Canvas AI panel.'),
        '#options' => $assistant_options,
        '#default_value' => $mode->getAssistants(),
      ];
    }

    $form['surfaces'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Surfaces'),
      '#description' => $this->t('Optional. One surface ID per line. Leave empty to make the mode available on all surfaces.'),
      '#default_value' => implode("\n", $mode->getSurfaces()),
      '#rows' => 2,
    ];
    $form['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight'),
      '#default_value' => $mode->get('weight') ?? 0,
    ];

    return $form;
  }

  /**
   * AJAX callback: rebuilds the sub-agent checkboxes for the chosen agent.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The sub-agents form element.
   */
  public function updateSubAgents(array $form, FormStateInterface $form_state): array {
    return $form['sub_agents'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ((string) $form_state->getValue('scope_strength') !== AiAgentModeInterface::SCOPE_RESTRICT) {
      return;
    }
    if ((string) $form_state->getValue('agent') === '') {
      $form_state->setErrorByName('agent', $this->t('A mode that withholds tools must name its parent agent, because sub-agent IDs only mean something for one agent.'));
    }
    if (array_filter((array) $form_state->getValue('sub_agents')) === []) {
      $form_state->setErrorByName('sub_agents', $this->t('A mode that withholds tools must name at least one sub-agent to keep, or the agent would be left with none.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Normalises form values before they are copied onto the entity's typed
   * properties. buildEntity() also runs during validation, so this is the
   * earliest safe place (a raw textarea string would TypeError on the
   * array-typed properties).
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $surfaces = $form_state->getValue('surfaces');
    if (!is_array($surfaces)) {
      $form_state->setValue('surfaces', $this->splitLines((string) $surfaces));
    }
    $form_state->setValue('sub_agents', array_values(array_filter((array) $form_state->getValue('sub_agents'))));
    // The assistants element is only built when there are assistants to offer.
    // Touch the value only when it was submitted, so a saved restriction is
    // kept on a site where the AI Assistant API is no longer installed.
    if (isset($form['assistants'])) {
      $form_state->setValue('assistants', array_values(array_filter((array) $form_state->getValue('assistants'))));
    }
    return parent::buildEntity($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('The AI agent mode %label has been saved.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

  /**
   * Machine-name existence checker.
   *
   * @param string $id
   *   The candidate machine name.
   *
   * @return bool
   *   TRUE when a mode with this ID already exists.
   */
  public function modeExists(string $id): bool {
    return (bool) $this->entityTypeManager->getStorage('ai_agent_mode')->load($id);
  }

  /**
   * Builds the parent-agent select options.
   *
   * @return array<string, string>
   *   Options keyed by agent plugin ID.
   */
  protected function agentOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_agent')->loadMultiple() as $id => $agent) {
      $options[$id] = (string) $agent->label();
    }
    asort($options);
    return $options;
  }

  /**
   * Builds the AI Assistant checkbox options.
   *
   * Returns nothing when the AI Assistant API is absent or no assistant has
   * been created, so the field is only shown when it can do something.
   *
   * @return array<string, string>
   *   Options keyed by ai_assistant entity ID.
   */
  protected function assistantOptions(): array {
    if (!$this->entityTypeManager->hasDefinition('ai_assistant')) {
      return [];
    }
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_assistant')->loadMultiple() as $id => $assistant) {
      $options[$id] = (string) $assistant->label();
    }
    asort($options);
    return $options;
  }

  /**
   * Builds the sub-agent checkbox options for a given parent agent.
   *
   * When no agent is selected (generic mode), the union of all agents'
   * sub-agents is offered.
   *
   * @param string $agent_id
   *   The parent agent plugin ID, or an empty string for generic.
   *
   * @return array<string, string>
   *   Options keyed by sub-agent plugin ID.
   */
  protected function subAgentOptions(string $agent_id): array {
    $options = [];
    if ($agent_id !== '') {
      foreach ($this->modeManager->listSubAgents($agent_id) as $id => $info) {
        $options[$id] = $info['label'] . ' (' . $id . ')';
      }
      return $options;
    }
    foreach ($this->entityTypeManager->getStorage('ai_agent')->loadMultiple() as $parent_id => $agent) {
      foreach ($this->modeManager->listSubAgents((string) $parent_id) as $id => $info) {
        $options[$id] = $info['label'] . ' (' . $id . ')';
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Splits a textarea value into a trimmed, non-empty list of lines.
   *
   * @param string $value
   *   The raw textarea value.
   *
   * @return string[]
   *   The cleaned list.
   */
  protected function splitLines(string $value): array {
    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $lines = array_map('trim', $lines);
    return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
  }

}
