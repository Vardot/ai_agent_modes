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
   * Marker that opens every generated mode directive.
   *
   * Extracted so the directive builder and the de-duplication guard in the
   * request-event fallback cannot drift apart.
   */
  const DIRECTIVE_MARKER = 'MODE (AI Agent Modes):';

  /**
   * Surface ID for the AI Assistant chat surfaces.
   */
  const SURFACE_ASSISTANT = 'ai_assistant';

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
   * @param string|null $assistant_id
   *   The ai_assistant entity ID the surface is backed by, or NULL when it is
   *   not backed by one. Modes that name assistants are only offered for the
   *   assistants they name, so they are withheld when this is NULL.
   *
   * @return \Drupal\ai_agent_modes\AiAgentModeInterface[]
   *   The matching enabled modes, sorted by weight then label.
   */
  public function listModes(?string $parent_agent_id = NULL, ?string $surface = NULL, ?string $assistant_id = NULL): array;

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
   * Applies a scope to a system prompt string.
   *
   * This is the primary seam. It is called from the ai_agents.pre_system_prompt
   * event, which hands the composed prompt over as a string and reads it back,
   * so the returned string is still token-replaced by the agent afterwards and
   * a mode's own text may therefore contain tokens.
   *
   * @param \Drupal\ai_agent_modes\ScopePayload $payload
   *   The scope to apply.
   * @param string $system_prompt
   *   The system prompt to prepend the directive to.
   *
   * @return string
   *   The prompt with the directive in front, or the prompt unchanged when the
   *   scope steers nothing.
   *
   * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::determineSolvability()
   */
  public function applyScopeToPrompt(ScopePayload $payload, string $system_prompt): string;

  /**
   * Applies a scope to a chat request in place.
   *
   * Delegates to applyScopeToPrompt() and is kept for the ai_agents.request
   * fallback, which covers a dispatcher that never fires the prompt event.
   *
   * Tools are not removed here. Withholding sub-agent tools happens earlier, on
   * ai_agents.started_execution, and only for a mode whose scope strength is
   * restrict.
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
   * Builds the tools map that withholds the sub-agent tools a mode omits.
   *
   * The result is a complete replacement map, because the agent's
   * functions_override replaces the tools map rather than merging into it.
   * Only sub-agent tools (the upstream agent_tools function group) are ever
   * withheld: the orchestrator's own tools are copied through untouched, and a
   * tool that is already disabled is never re-enabled.
   *
   * @param \Drupal\ai_agent_modes\ScopePayload $payload
   *   The resolved scope. Anything other than a restrict scope with at least
   *   one available sub-agent returns an empty array.
   * @param array<string, bool> $entity_tools
   *   The agent instance's live tools map, that is
   *   AiAgentEntityWrapper::getAiAgentEntity()->get('tools'), which is the
   *   override-applied clone rather than the stored entity.
   *
   * @return array<string, bool>
   *   A complete replacement map for the agent's functions override, or an
   *   empty array when nothing should be withheld.
   */
  public function restrictedTools(ScopePayload $payload, array $entity_tools): array;

  /**
   * Whether the site has any AI agent mode at all.
   *
   * A cheap gate, so an agent on a site with no modes never has its function
   * overrides touched. Deliberately not per agent: a generic mode matches every
   * agent ID, so a per-agent count could not tell the truth.
   *
   * @return bool
   *   TRUE when at least one mode entity exists.
   */
  public function hasAnyMode(): bool;

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
