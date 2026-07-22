<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Stores mode selections in the private tempstore.
 */
class SelectionStore implements SelectionStoreInterface {

  /**
   * The tempstore collection name.
   */
  protected const COLLECTION = 'ai_agent_modes';

  /**
   * Constructs a SelectionStore.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function set(string $parent_agent_id, array $sub_agents = [], ?string $mode_id = NULL, ?string $conversation_id = NULL): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $store->set($this->key($parent_agent_id, $conversation_id), [
      'sub_agents' => array_values(array_filter($sub_agents, 'is_string')),
      'mode_id' => $mode_id !== '' ? $mode_id : NULL,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $parent_agent_id, ?string $conversation_id = NULL): ?array {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $value = $store->get($this->key($parent_agent_id, $conversation_id));
    if (!is_array($value)) {
      // Fall back to the session-wide selection when a conversation-scoped one
      // is not present, so the first turn of a conversation is still covered.
      if ($conversation_id !== NULL) {
        $value = $store->get($this->key($parent_agent_id, NULL));
      }
      if (!is_array($value)) {
        return NULL;
      }
    }
    return [
      'sub_agents' => $value['sub_agents'] ?? [],
      'mode_id' => $value['mode_id'] ?? NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function clear(string $parent_agent_id, ?string $conversation_id = NULL): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $store->delete($this->key($parent_agent_id, $conversation_id));
  }

  /**
   * Builds the tempstore key for an agent and conversation.
   *
   * @param string $parent_agent_id
   *   The parent agent plugin ID.
   * @param string|null $conversation_id
   *   The conversation/thread ID, or NULL.
   *
   * @return string
   *   The tempstore key.
   */
  protected function key(string $parent_agent_id, ?string $conversation_id): string {
    $suffix = $conversation_id !== NULL && $conversation_id !== '' ? $conversation_id : 'session';
    return $parent_agent_id . ':' . $suffix;
  }

}
