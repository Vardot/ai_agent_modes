<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

/**
 * Immutable result of resolving a mode selection against a parent agent.
 *
 * This is the payload the orchestrator integration consumes: the subset of
 * sub-agents to steer toward and the scoped system prompt directive.
 */
final class ScopePayload {

  /**
   * Constructs a ScopePayload.
   *
   * @param string $parentAgent
   *   The parent agent plugin ID the scope applies to.
   * @param string[] $subAgents
   *   The sub-agent plugin IDs the orchestrator is restricted to.
   * @param string $systemPromptAddition
   *   The scoped directive to prepend to the system prompt.
   * @param string $label
   *   A human-readable label for the active scope (mode label or ad-hoc).
   * @param string $scopeStrength
   *   Either AiAgentModeInterface::SCOPE_GUIDE, the default, which steers with
   *   the directive alone, or SCOPE_RESTRICT, which also withholds the
   *   sub-agent tools the scope does not name. New parameters must be appended
   *   here: this class is final and is constructed positionally.
   */
  public function __construct(
    public readonly string $parentAgent,
    public readonly array $subAgents,
    public readonly string $systemPromptAddition = '',
    public readonly string $label = '',
    public readonly string $scopeStrength = AiAgentModeInterface::SCOPE_GUIDE,
  ) {}

  /**
   * Whether the scope actually steers anything.
   *
   * @return bool
   *   TRUE when at least one sub-agent is selected, or the mode carries a
   *   prompt directive of its own (e.g. steering toward orchestrator tools).
   */
  public function isRestrictive(): bool {
    return $this->subAgents !== [] || trim($this->systemPromptAddition) !== '';
  }

}
