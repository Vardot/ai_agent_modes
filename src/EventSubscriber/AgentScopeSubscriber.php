<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\SelectionStoreInterface;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the active mode scope to an agent request.
 *
 * This is the thin, agent-agnostic integration point: whenever any agent is
 * about to send a request, if a mode scope is active for it, the orchestrator
 * is restricted to the selected subset of sub-agents and the scope directive
 * is prepended to the system prompt.
 */
class AgentScopeSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an AgentScopeSubscriber.
   *
   * @param \Drupal\ai_agent_modes\ModeManagerInterface $modeManager
   *   The mode manager.
   * @param \Drupal\ai_agent_modes\SelectionStoreInterface $selectionStore
   *   The selection store.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected ModeManagerInterface $modeManager,
    protected SelectionStoreInterface $selectionStore,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentRequestEvent::EVENT_NAME => ['onAgentRequest', 100],
    ];
  }

  /**
   * Restricts the request to the active mode's sub-agent subset.
   *
   * @param \Drupal\ai_agents\Event\AgentRequestEvent $event
   *   The agent request event.
   */
  public function onAgentRequest(AgentRequestEvent $event): void {
    $agent_id = $event->getAgentId();
    $selection = $this->selectionStore->get($agent_id, $event->getThreadId());
    if ($selection === NULL) {
      return;
    }

    $payload = $this->modeManager->resolve(
      $agent_id,
      $selection['sub_agents'] ?? [],
      $selection['mode_id'] ?? NULL,
    );
    if ($payload === NULL || !$payload->isRestrictive()) {
      return;
    }

    $applied = $this->modeManager->applyScope($payload, $event->getChatInput());
    if ($applied) {
      $this->logger->info('Applied mode "@label" to agent @agent: steering to sub-agent(s) [@subset] via the system prompt.', [
        '@label' => $payload->label !== '' ? $payload->label : implode(', ', $payload->subAgents),
        '@agent' => $agent_id,
        '@subset' => implode(', ', $payload->subAgents),
      ]);
    }
  }

}
