<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai_agent_modes\ActiveAssistantContext;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\ScopePayload;
use Drupal\ai_agent_modes\SelectionStoreInterface;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Drupal\ai_agents\Event\AgentStartedExecutionEvent;
use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the active mode scope to an agent run.
 *
 * Three upstream events, in the order ai_agents fires them:
 *
 * - ai_agents.started_execution: the only event that hands out the live agent
 *   wrapper before it assembles its tool set, so this is where a mode that
 *   withholds sub-agent tools does so.
 * - ai_agents.pre_system_prompt: the designed seam for changing an agent's
 *   system prompt. It runs before the agent replaces tokens, so a mode's own
 *   text is tokenised too.
 * - ai_agents.request: a fallback for a dispatcher that never fires the prompt
 *   event, guarded so the directive is never added twice.
 */
class AgentScopeSubscriber implements EventSubscriberInterface {

  /**
   * The priority every handler runs at.
   *
   * The pre_system_prompt event has no other subscriber in ai 1.4.5 and
   * ai_agents 1.3.2. Both started_execution and request do:
   * ai_agents' own AgentStatusSubscriber listens at priority 0, and running
   * ahead of it is what makes the scoped prompt and the narrowed tool set
   * visible in the status log it writes.
   */
  public const PRIORITY = 100;

  /**
   * Constructs an AgentScopeSubscriber.
   *
   * @param \Drupal\ai_agent_modes\ModeManagerInterface $modeManager
   *   The mode manager.
   * @param \Drupal\ai_agent_modes\SelectionStoreInterface $selectionStore
   *   The selection store.
   * @param \Drupal\ai_agent_modes\ActiveAssistantContext $assistantContext
   *   The active assistant context.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected ModeManagerInterface $modeManager,
    protected SelectionStoreInterface $selectionStore,
    protected ActiveAssistantContext $assistantContext,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentStartedExecutionEvent::EVENT_NAME => ['onAgentStarted', self::PRIORITY],
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', self::PRIORITY],
      AgentRequestEvent::EVENT_NAME => ['onAgentRequest', self::PRIORITY],
    ];
  }

  /**
   * Withholds the sub-agent tools a restrict mode does not name.
   *
   * This is the earliest point at which the agent's tool set can still be
   * changed: the wrapper reads its functions override when it assembles the
   * tools, so a withheld tool is never instantiated, never normalised and never
   * reaches the provider.
   *
   * @param \Drupal\ai_agents\Event\AgentStartedExecutionEvent $event
   *   The started-execution event.
   */
  public function onAgentStarted(AgentStartedExecutionEvent $event): void {
    // A nested sub-agent run is never narrowed. The child carries its caller's
    // runner ID and inherits the parent thread, so a mode chosen for the
    // orchestrator must not reach into a sub-agent's own tool set.
    if ($event->getCallerId() !== NULL) {
      return;
    }
    // With no mode anywhere on the site this module can never withhold
    // anything, so it must not touch the agent's override at all. This is
    // deliberately not a per-agent check: a generic mode matches every agent
    // ID, so a per-agent count would not be the truth.
    if (!$this->modeManager->hasAnyMode()) {
      return;
    }
    $agent = $event->getAgent();
    // overrideFunctions(), resetFunctions() and getAiAgentEntity() are
    // concrete-class API on the config-entity wrapper: they are declared on
    // neither AiAgentInterface nor ConfigAiAgentInterface. Without the wrapper
    // the module degrades to prompt-only steering, which is what a legacy
    // code-plugin agent gets.
    if (!$agent instanceof AiAgentEntityWrapper) {
      return;
    }

    // Convergence first, before any early return below. A functions override
    // survives the wrapper's own serialisation between turns and is restored by
    // the AI Assistant API on the next turn, so a restriction from an earlier
    // turn has to be undone even when the user has since cleared the mode or
    // switched to a mode that withholds nothing.
    $agent->resetFunctions();

    $payload = $this->resolveFor($event->getAgentId(), $event->getThreadId());
    if ($payload === NULL) {
      return;
    }
    $entity_tools = $agent->getAiAgentEntity()->get('tools');
    $tools = $this->modeManager->restrictedTools($payload, is_array($entity_tools) ? $entity_tools : []);
    if ($tools === []) {
      return;
    }
    // Only the tools key is overridden, so tool usage limits and tool settings
    // keep falling back to the agent's own configuration.
    $agent->overrideFunctions(['tools' => $tools]);
    // The started-execution event counts the first loop as 0, while the request
    // event counts it as 1, so this guard cannot share a constant with the
    // other handlers.
    if ($event->getLoopCount() === 0) {
      $withheld = array_keys(array_filter($tools, static fn ($enabled): bool => empty($enabled)));
      $this->logger->info('Mode "@label" withheld sub-agent tool(s) [@withheld] from agent @agent for this run.', [
        '@label' => $payload->label !== '' ? $payload->label : implode(', ', $payload->subAgents),
        '@withheld' => implode(', ', $withheld),
        '@agent' => $event->getAgentId(),
      ]);
    }
  }

  /**
   * Prepends the mode directive to the agent's system prompt.
   *
   * The primary seam. The agent replaces tokens after this event, so a mode's
   * system prompt addition may itself contain tokens.
   *
   * @param \Drupal\ai_agents\Event\BuildSystemPromptEvent $event
   *   The prompt-building event.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    $prompt = $event->getSystemPrompt();
    // The agent rebuilds this prompt from scratch on every loop, so this only
    // trips when the agent's own configured prompt already carries the marker.
    if (str_contains($prompt, ModeManagerInterface::DIRECTIVE_MARKER)) {
      return;
    }
    // This event carries no thread ID, which costs nothing today: every writer
    // stores a session-wide selection, so a thread-keyed read returns the same
    // row. Wiring the conversation dimension needs the thread ID from the
    // started-execution event, the only earlier event that carries one.
    $payload = $this->resolveFor($event->getAgentId(), NULL);
    if ($payload === NULL) {
      return;
    }
    $event->setSystemPrompt($this->modeManager->applyScopeToPrompt($payload, $prompt));
    $this->logMode($payload, $event->getAgentId());
  }

  /**
   * Prepends the mode directive when the prompt event never fired.
   *
   * Unreachable with ai_agents 1.3.2, where the request event always follows
   * the prompt event inside one run and the composed prompt is copied into this
   * chat input. Kept so a patched or future dispatcher that skips the prompt
   * event still gets the directive.
   *
   * @param \Drupal\ai_agents\Event\AgentRequestEvent $event
   *   The agent request event.
   */
  public function onAgentRequest(AgentRequestEvent $event): void {
    $input = $event->getChatInput();
    if (str_contains($input->getSystemPrompt(), ModeManagerInterface::DIRECTIVE_MARKER)) {
      return;
    }
    $payload = $this->resolveFor($event->getAgentId(), $event->getThreadId());
    if ($payload === NULL) {
      return;
    }
    if ($this->modeManager->applyScope($payload, $input)) {
      $this->logMode($payload, $event->getAgentId());
    }
  }

  /**
   * Resolves the scope that applies to one agent run.
   *
   * @param string $agent_id
   *   The agent plugin ID.
   * @param string|null $thread_id
   *   The thread ID, or NULL when the event carries none.
   *
   * @return \Drupal\ai_agent_modes\ScopePayload|null
   *   The scope to apply, or NULL for free-form.
   */
  protected function resolveFor(string $agent_id, ?string $thread_id): ?ScopePayload {
    $selection = $this->selectionStore->get($agent_id, $thread_id);
    if ($selection === NULL) {
      return NULL;
    }

    $mode_id = $selection['mode_id'] ?? NULL;
    $assistant_id = $this->assistantContext->assistantFor($thread_id);
    if (!empty($mode_id) && $assistant_id !== NULL) {
      // Only withhold the mode when this run's assistant is known and is not
      // one the mode belongs to. When the run carries no assistant identity at
      // all, which is every Drupal Canvas panel run, every bare agent run and
      // the selector block placed against an assistant while storing under the
      // agent ID, the stored mode still applies: closing that would silently
      // break a supported surface.
      $offered = array_map(
        static fn ($mode): string => (string) $mode->id(),
        $this->modeManager->listModes($agent_id, NULL, $assistant_id),
      );
      if (!in_array($mode_id, $offered, TRUE)) {
        $this->logger->notice('Mode "@mode" is limited to other AI Assistants, so it was not applied for assistant @assistant.', [
          '@mode' => $mode_id,
          '@assistant' => $assistant_id,
        ]);
        return NULL;
      }
    }

    $payload = $this->modeManager->resolve(
      $agent_id,
      $selection['sub_agents'] ?? [],
      $mode_id,
    );
    if ($payload === NULL || !$payload->isRestrictive()) {
      return NULL;
    }
    return $payload;
  }

  /**
   * Logs an applied scope.
   *
   * @param \Drupal\ai_agent_modes\ScopePayload $payload
   *   The applied scope.
   * @param string $agent_id
   *   The agent plugin ID.
   */
  protected function logMode(ScopePayload $payload, string $agent_id): void {
    $this->logger->info('Applied mode "@label" to agent @agent: steering to sub-agent(s) [@subset] via the system prompt.', [
      '@label' => $payload->label !== '' ? $payload->label : implode(', ', $payload->subAgents),
      '@agent' => $agent_id,
      '@subset' => implode(', ', $payload->subAgents),
    ]);
  }

}
