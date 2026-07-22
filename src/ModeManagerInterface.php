<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

use Drupal\ai\OperationType\Chat\ChatInput;

/**
 * Resolves agent modes and applies the resulting scope to a request.
 */
interface ModeManagerInterface {

  /**
   * The tool-plugin group used by ai_agents for sub-agent tools.
   */
  const AGENT_TOOL_GROUP = 'agent_tools';

  /**
   * Lists the live sub-agents of a parent agent.
   *
   * The list is read dynamically from the parent agent's enabled tools, so it
   * always reflects the current configuration rather than a hardcoded set.
   *
   * @param string $parent_agent_id
   *   The parent agent plugin ID.
   *
   * @return array<string, array{id: string, label: string, description: string, tool_id: string}>
   *   Sub-agents keyed by sub-agent plugin ID.
   */
  public function listSubAgents(string $parent_agent_id): array;

  /**
   * Lists the saved modes available for a parent agent and surface.
   *
   * @param string|null $parent_agent_id
   *   The parent agent plugin ID, or NULL for all agents. Generic modes (with
   *   no agent set) are always included.
   * @param string|null $surface
   *   The surface ID to filter by, or NULL to skip surface filtering.
   *
   * @return \Drupal\ai_agent_modes\AiAgentModeInterface[]
   *   The matching enabled modes, sorted by weight then label.
   */
  public function listModes(?string $parent_agent_id = NULL, ?string $surface = NULL): array;

  /**
   * Resolves a selection into a scope payload.
   *
   * @param string $parent_agent_id
   *   The parent agent plugin ID.
   * @param string[] $selected_sub_agents
   *   Ad-hoc selected sub-agent plugin IDs.
   * @param string|null $mode_id
   *   A saved mode ID to resolve, or NULL for an ad-hoc selection.
   *
   * @return \Drupal\ai_agent_modes\ScopePayload|null
   *   The resolved scope, or NULL when nothing is selected (free-form).
   */
  public function resolve(string $parent_agent_id, array $selected_sub_agents = [], ?string $mode_id = NULL): ?ScopePayload;

  /**
   * Applies a scope to a chat request in place.
   *
   * Only adds guiding text: it prepends the scope directive to the system
   * prompt, naming the sub-agent(s) the orchestrator should use. Tools are not
   * removed, so the orchestrator keeps full capability and simply gets steered.
   *
   * @param \Drupal\ai_agent_modes\ScopePayload $payload
   *   The scope to apply.
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input to mutate.
   *
   * @return bool
   *   TRUE when the directive was prepended to the system prompt.
   */
  public function applyScope(ScopePayload $payload, ChatInput $input): bool;

  /**
   * Builds the hard scope directive injected into the system prompt.
   *
   * @param \Drupal\ai_agent_modes\ScopePayload $payload
   *   The scope to describe.
   *
   * @return string
   *   The directive text.
   */
  public function buildScopeDirective(ScopePayload $payload): string;

}
