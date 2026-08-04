<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for the AI agent mode config entity.
 *
 * A mode is a curated subset of a parent agent's sub-agents, shipped in config
 * so it can travel in recipes and other modules.
 */
interface AiAgentModeInterface extends ConfigEntityInterface {

  /**
   * Scope strength: steer with the directive only, leaving every tool in place.
   *
   * The default, and what every mode saved before this field existed does.
   */
  const SCOPE_GUIDE = 'guide';

  /**
   * Scope strength: steer, and also withhold the sub-agent tools not named.
   *
   * Honoured only for a mode that names a parent agent and at least one
   * sub-agent, and only on the ai_agents pipeline.
   */
  const SCOPE_RESTRICT = 'restrict';

  /**
   * Gets the mode description.
   *
   * @return string
   *   The human-readable description.
   */
  public function getDescription(): string;

  /**
   * Gets the parent agent this mode applies to.
   *
   * @return string
   *   The parent agent plugin ID, or an empty string when the mode is generic.
   */
  public function getAgent(): string;

  /**
   * Gets the sub-agent plugin IDs this mode exposes.
   *
   * Under the guide scope strength this is a hint carried in the prompt. Under
   * restrict it is also the allow-list: every other sub-agent tool is withheld
   * from the request.
   *
   * @return string[]
   *   A list of AiAgent plugin IDs.
   */
  public function getSubAgents(): array;

  /**
   * Gets the scope strength of this mode.
   *
   * @return string
   *   Either self::SCOPE_GUIDE (the default) or self::SCOPE_RESTRICT. An
   *   unknown stored value reads as self::SCOPE_GUIDE.
   */
  public function getScopeStrength(): string;

  /**
   * Whether this mode withholds the sub-agent tools it does not name.
   *
   * @return bool
   *   TRUE when the scope strength is self::SCOPE_RESTRICT.
   */
  public function withholdsTools(): bool;

  /**
   * Gets the scoped system prompt directive for this mode.
   *
   * @return string
   *   The system prompt addition.
   */
  public function getSystemPromptAddition(): string;

  /**
   * Gets the AI Assistants this mode is limited to.
   *
   * @return string[]
   *   A list of ai_assistant entity IDs, or an empty list when the mode is
   *   offered for every assistant.
   */
  public function getAssistants(): array;

  /**
   * Checks whether this mode is available for the given AI Assistant.
   *
   * @param string|null $assistant_id
   *   The ai_assistant entity ID to check, or NULL when the surface is not
   *   backed by an assistant.
   *
   * @return bool
   *   TRUE when the mode names no assistant, or names this one. A mode that
   *   names assistants is never available where there is no assistant.
   */
  public function appliesToAssistant(?string $assistant_id): bool;

  /**
   * Gets the surfaces this mode applies to.
   *
   * @return string[]
   *   A list of surface IDs, or an empty list when the mode applies everywhere.
   */
  public function getSurfaces(): array;

  /**
   * Checks whether this mode is available on the given surface.
   *
   * @param string $surface
   *   The surface ID to check.
   *
   * @return bool
   *   TRUE when the mode has no surface restriction or lists the surface.
   */
  public function appliesToSurface(string $surface): bool;

}
