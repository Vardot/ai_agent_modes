<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
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

    $form['system_prompt_addition'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt addition'),
      '#description' => $this->t('A short scoped directive prepended to the system prompt when this mode is active.'),
      '#default_value' => $mode->getSystemPromptAddition(),
      '#rows' => 3,
    ];
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
