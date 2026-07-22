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
   * The surfaces this mode applies to.
   *
   * @var string[]
   */
  protected array $surfaces = [];

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
