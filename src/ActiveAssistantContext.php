<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

/**
 * Remembers which AI Assistant an agent run belongs to, for this request only.
 *
 * Nothing upstream carries an ai_assistant ID into the agent pipeline. The AI
 * Assistant API hands the agent runner its *agent* ID, not the assistant, and
 * no ai_agents event exposes an assistant: an agent event offers the agent, the
 * agent ID, the chat history, the loop count, the runner ID, the thread ID and
 * the caller ID, and nothing more. The one interface-declared free slot,
 * AiAgentInterface::setData(), is aliased inside the config-entity wrapper to
 * the pending tool-call array, so writing a mode's own data there would destroy
 * the agent's pending tool calls.
 *
 * So the assistant identity is noted here when the assistant hands off to the
 * agent (ai_assistant.pass_context_to_agent), keyed by the agent runner ID,
 * which is the same value the later agent events report as their thread ID. The
 * handoff, the started-execution event and the request event all happen in one
 * PHP request, so a plain in-memory service is enough and nothing is persisted.
 */
final class ActiveAssistantContext {

  /**
   * Assistant IDs keyed by agent thread (runner) ID.
   *
   * @var array<string, string>
   */
  protected array $assistants = [];

  /**
   * Notes the assistant a thread belongs to.
   *
   * @param string $thread_id
   *   The agent thread (runner) ID. An empty string is ignored.
   * @param string $assistant_id
   *   The ai_assistant entity ID. An empty string is ignored.
   */
  public function note(string $thread_id, string $assistant_id): void {
    if ($thread_id === '' || $assistant_id === '') {
      return;
    }
    $this->assistants[$thread_id] = $assistant_id;
  }

  /**
   * Gets the assistant a thread belongs to, when it is known.
   *
   * @param string|null $thread_id
   *   The agent thread (runner) ID, or NULL.
   *
   * @return string|null
   *   The ai_assistant entity ID, or NULL when this run carries no assistant
   *   identity. NULL means "unknown", never "no assistant": a Drupal Canvas
   *   panel run and a bare agent run both report NULL.
   */
  public function assistantFor(?string $thread_id): ?string {
    if ($thread_id === NULL || $thread_id === '') {
      return NULL;
    }
    return $this->assistants[$thread_id] ?? NULL;
  }

}
