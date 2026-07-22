<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

/**
 * Persists the active mode selection per conversation.
 *
 * The selection is stored per user (private tempstore) and optionally keyed by
 * a conversation/thread ID so it survives follow-up turns.
 */
interface SelectionStoreInterface {

  /**
   * Stores a selection for a parent agent and conversation.
   *
   * @param string $parent_agent_id
   *   The parent agent plugin ID.
   * @param string[] $sub_agents
   *   The selected sub-agent plugin IDs (ad-hoc selection).
   * @param string|null $mode_id
   *   The selected saved mode ID, or NULL.
   * @param string|null $conversation_id
   *   The conversation/thread ID, or NULL for the session-wide selection.
   */
  public function set(string $parent_agent_id, array $sub_agents = [], ?string $mode_id = NULL, ?string $conversation_id = NULL): void;

  /**
   * Gets the stored selection for a parent agent and conversation.
   *
   * @param string $parent_agent_id
   *   The parent agent plugin ID.
   * @param string|null $conversation_id
   *   The conversation/thread ID, or NULL for the session-wide selection.
   *
   * @return array{sub_agents: string[], mode_id: string|null}|null
   *   The stored selection, or NULL when none is set.
   */
  public function get(string $parent_agent_id, ?string $conversation_id = NULL): ?array;

  /**
   * Clears the stored selection.
   *
   * @param string $parent_agent_id
   *   The parent agent plugin ID.
   * @param string|null $conversation_id
   *   The conversation/thread ID, or NULL for the session-wide selection.
   */
  public function clear(string $parent_agent_id, ?string $conversation_id = NULL): void;

}
