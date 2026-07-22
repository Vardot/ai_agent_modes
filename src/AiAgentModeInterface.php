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
   * @return string[]
   *   A list of AiAgent plugin IDs.
   */
  public function getSubAgents(): array;

  /**
   * Gets the scoped system prompt directive for this mode.
   *
   * @return string
   *   The system prompt addition.
   */
  public function getSystemPromptAddition(): string;

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
