<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai_agent_modes\ActiveAssistantContext;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\SelectionStoreInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Integrates modes with the AI Assistant API's own events.
 *
 * Two jobs, both soft: the class does nothing at all when the AI Assistant API
 * is absent, and it never references a class from that module.
 *
 * - ai_assistant.pass_context_to_agent: notes which assistant an agent run
 *   belongs to, so the agent-side subscriber can refuse a mode that is limited
 *   to a different assistant. Nothing in the event is mutated.
 * - ai_assistant.change_assistant_message: prepends the mode directive to the
 *   assistant's own system prompt. This is the seam for an assistant that has
 *   no agent behind it, which the agent events can never reach. For an
 *   assistant that does have an agent, upstream returns before this prompt is
 *   ever built, so the two seams cannot both fire for one turn.
 *
 * A separate class from AgentScopeSubscriber on purpose, so a plain agent run
 * never instantiates the AI Assistant API runner service graph.
 */
class AssistantScopeSubscriber implements EventSubscriberInterface {

  /**
   * The priority both handlers run at.
   */
  public const PRIORITY = 100;

  /**
   * The prefix used to store a selection made against an assistant, not agent.
   */
  public const ASSISTANT_SCOPE_PREFIX = 'assistant:';

  /**
   * Constructs an AssistantScopeSubscriber.
   *
   * @param object|null $assistantRunner
   *   The AI Assistant API runner, or NULL when that module is absent.
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
    protected ?object $assistantRunner,
    protected ModeManagerInterface $modeManager,
    protected SelectionStoreInterface $selectionStore,
    protected ActiveAssistantContext $assistantContext,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Literal event names on purpose. This method runs while the container is
    // compiled, so fetching a ::EVENT_NAME constant from an optional module's
    // class would autoload that class and fatal on every cache rebuild when the
    // AI Assistant API is not installed.
    return [
      'ai_assistant.pass_context_to_agent' => ['onPassContextToAgent', self::PRIORITY],
      'ai_assistant.change_assistant_message' => ['onAssistantSystemRole', self::PRIORITY],
    ];
  }

  /**
   * Notes which assistant the agent run about to start belongs to.
   *
   * The event's context array is deliberately left alone: upstream dispatches
   * this event and then runs the agent without reading the context again, and
   * on the chatbot surface the inbound contexts are client-supplied, so they
   * could not be trusted for scoping even if they were read.
   *
   * @param object $event
   *   The AI Assistant API pass-context-to-agent event.
   */
  public function onPassContextToAgent(object $event): void {
    if ($this->assistantRunner === NULL || !method_exists($event, 'getAgent')) {
      return;
    }
    $assistant_id = $this->resolveAssistantId();
    if ($assistant_id === NULL) {
      return;
    }
    $agent = $event->getAgent();
    if (!method_exists($agent, 'getRunnerId')) {
      return;
    }
    // The runner ID is exactly the value the later agent events report as their
    // thread ID, because the assistant sets both from one job ID.
    $this->assistantContext->note((string) ($agent->getRunnerId() ?? ''), $assistant_id);
  }

  /**
   * Prepends the mode directive to an assistant's own system prompt.
   *
   * Only reachable for an assistant with no agent: when an assistant has an
   * agent, upstream hands off to the agent and returns before this prompt is
   * built, so the agent-side subscriber owns that turn instead.
   *
   * @param object $event
   *   The AI Assistant API system-role event.
   */
  public function onAssistantSystemRole(object $event): void {
    if ($this->assistantRunner === NULL) {
      return;
    }
    if (!method_exists($event, 'getSystemPrompt') || !method_exists($event, 'setSystemPrompt')) {
      return;
    }
    $assistant_id = $this->resolveAssistantId();
    if ($assistant_id === NULL) {
      return;
    }
    $selection = $this->selectionStore->get(self::ASSISTANT_SCOPE_PREFIX . $assistant_id);
    if ($selection === NULL || empty($selection['mode_id'])) {
      return;
    }
    // Re-validate at run time rather than trusting the stored selection. An
    // empty parent agent returns generic modes only, so a mode bound to an
    // agent can never be applied to an assistant that has none, and a mode
    // limited to other assistants is refused here as well as in the dropdown.
    $offered = array_map(
      static fn ($mode): string => (string) $mode->id(),
      $this->modeManager->listModes('', ModeManagerInterface::SURFACE_ASSISTANT, $assistant_id),
    );
    if (!in_array($selection['mode_id'], $offered, TRUE)) {
      return;
    }
    $payload = $this->modeManager->resolve('', [], $selection['mode_id']);
    if ($payload === NULL || !$payload->isRestrictive()) {
      return;
    }
    $prompt = $event->getSystemPrompt();
    if (str_contains($prompt, ModeManagerInterface::DIRECTIVE_MARKER)) {
      return;
    }
    $event->setSystemPrompt(trim($this->modeManager->buildScopeDirective($payload) . "\n\n" . $prompt));
    $this->logger->info('Applied mode "@label" to AI Assistant @assistant, which has no agent, via its system prompt.', [
      '@label' => $payload->label !== '' ? $payload->label : $selection['mode_id'],
      '@assistant' => $assistant_id,
    ]);
  }

  /**
   * Reads the ID of the assistant currently running.
   *
   * The system-role event carries a bare string and the message builder keeps
   * its assistant private, so the identity can only be read off the shared
   * runner service.
   *
   * @return string|null
   *   The ai_assistant entity ID, or NULL when it cannot be determined.
   */
  protected function resolveAssistantId(): ?string {
    // Order matters: several runner methods dereference their assistant
    // property with no guard of their own, so nothing else may be called before
    // getAssistant() has returned something.
    if (!method_exists($this->assistantRunner, 'getAssistant')) {
      return NULL;
    }
    $assistant = $this->assistantRunner->getAssistant();
    if ($assistant === NULL || !method_exists($assistant, 'id')) {
      return NULL;
    }
    $id = (string) ($assistant->id() ?? '');
    return $id === '' ? NULL : $id;
  }

}
