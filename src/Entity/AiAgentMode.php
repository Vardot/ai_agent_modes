<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_agent_modes\AiAgentModeInterface;
use Drupal\ai_agent_modes\AiAgentModeListBuilder;
use Drupal\ai_agent_modes\Form\AiAgentModeForm;

/**
 * Defines the AI agent mode config entity.
 */
#[ConfigEntityType(
  id: 'ai_agent_mode',
  label: new TranslatableMarkup('AI agent mode'),
  label_collection: new TranslatableMarkup('AI agent modes'),
  label_singular: new TranslatableMarkup('AI agent mode'),
  label_plural: new TranslatableMarkup('AI agent modes'),
  config_prefix: 'ai_agent_mode',
  admin_permission: 'administer ai agent modes',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
    'status' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => AiAgentModeListBuilder::class,
    'form' => [
      'add' => AiAgentModeForm::class,
      'edit' => AiAgentModeForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/ai/agent-modes',
    'add-form' => '/admin/config/ai/agent-modes/add',
    'edit-form' => '/admin/config/ai/agent-modes/{ai_agent_mode}',
    'delete-form' => '/admin/config/ai/agent-modes/{ai_agent_mode}/delete',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'weight',
    'status',
    'agent',
    'sub_agents',
    'system_prompt_addition',
    'scope_strength',
    'assistants',
    'surfaces',
  ],
)]
class AiAgentMode extends ConfigEntityBase implements AiAgentModeInterface {

  /**
   * The machine name of the mode.
   */
  protected string $id;

  /**
   * The human-readable label of the mode.
   */
  protected string $label;

  /**
   * The description of the mode.
   */
  protected string $description = '';

  /**
   * The weight of the mode in listings and the selector.
   */
  protected int $weight = 0;

  /**
   * The parent agent plugin ID, or an empty string when generic.
   */
  protected string $agent = '';

  /**
   * The sub-agent plugin IDs this mode exposes.
   *
   * @var string[]
   */
  protected array $sub_agents = [];

  /**
   * The scoped system prompt directive.
   */
  protected string $system_prompt_addition = '';

  /**
   * How strongly this mode scopes the agent.
   */
  protected string $scope_strength = AiAgentModeInterface::SCOPE_GUIDE;

  /**
   * The AI Assistant IDs this mode is limited to.
   *
   * @var string[]
   */
  protected array $assistants = [];

  /**
   * The surfaces this mode applies to.
   *
   * @var string[]
   */
  protected array $surfaces = [];

  /**
   * {@inheritdoc}
   *
   * Only the parent agent becomes a dependency. A mode is a subset of one
   * agent, so it is meaningless once that agent is gone, and Drupal removing it
   * with the agent is the right outcome.
   *
   * The sub-agents and the assistants a mode names are deliberately NOT
   * dependencies. The module already tolerates their absence at run time: an
   * unavailable sub-agent name is dropped when the scope is resolved, and an
   * assistant that no longer exists simply never matches. Making them hard
   * dependencies would delete a whole mode because one unrelated sub-agent was
   * removed, which loses an administrator's work for no benefit.
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    if ($this->agent === '') {
      return $this;
    }
    $definition = \Drupal::entityTypeManager()->getDefinition('ai_agent', FALSE);
    if ($definition === NULL) {
      return $this;
    }
    $agent = \Drupal::entityTypeManager()->getStorage('ai_agent')->load($this->agent);
    if ($agent !== NULL) {
      $this->addDependency('config', $agent->getConfigDependencyName());
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getAgent(): string {
    return $this->agent;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubAgents(): array {
    return array_values(array_filter($this->sub_agents));
  }

  /**
   * {@inheritdoc}
   */
  public function getSystemPromptAddition(): string {
    return $this->system_prompt_addition;
  }

  /**
   * {@inheritdoc}
   */
  public function getScopeStrength(): string {
    // An unknown stored value reads as the safe default rather than throwing,
    // so hand-edited config can never make an agent lose its tools.
    return $this->scope_strength === AiAgentModeInterface::SCOPE_RESTRICT
      ? AiAgentModeInterface::SCOPE_RESTRICT
      : AiAgentModeInterface::SCOPE_GUIDE;
  }

  /**
   * {@inheritdoc}
   */
  public function withholdsTools(): bool {
    return $this->getScopeStrength() === AiAgentModeInterface::SCOPE_RESTRICT;
  }

  /**
   * {@inheritdoc}
   */
  public function getAssistants(): array {
    return array_values(array_filter($this->assistants));
  }

  /**
   * {@inheritdoc}
   */
  public function appliesToAssistant(?string $assistant_id): bool {
    $assistants = $this->getAssistants();
    if ($assistants === []) {
      return TRUE;
    }
    // A mode that names assistants belongs to them: it is withheld where there
    // is no assistant at all (e.g. the Drupal Canvas AI panel, which is driven
    // by an agent rather than by an assistant).
    return $assistant_id !== NULL && $assistant_id !== '' && in_array($assistant_id, $assistants, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSurfaces(): array {
    return array_values(array_filter($this->surfaces));
  }

  /**
   * {@inheritdoc}
   */
  public function appliesToSurface(string $surface): bool {
    $surfaces = $this->getSurfaces();
    return $surfaces === [] || in_array($surface, $surfaces, TRUE);
  }

}
