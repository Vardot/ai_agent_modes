<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agent_modes\AiAgentModeInterface;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\SelectionStoreInterface;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Drupal\ai_agents\Event\AgentStartedExecutionEvent;
use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how a mode reaches a real agent run through the ai_agents events.
 *
 * Two things are proven here that a manager-level test cannot show: that the
 * directive is injected early enough for the agent to replace tokens in it, and
 * that a withholding mode really removes sub-agent tools from the set the
 * provider is given.
 *
 * @group ai_agent_modes
 * @coversDefaultClass \Drupal\ai_agent_modes\EventSubscriber\AgentScopeSubscriber
 */
#[RunTestsInSeparateProcesses]
class AgentScopeSubscriberTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'key',
    'modeler_api',
    'ai',
    'ai_agents',
    'ai_agent_modes',
  ];

  /**
   * The parent agent plugin ID.
   */
  protected string $parentId = 'test_orchestrator';

  /**
   * The selection store.
   */
  protected SelectionStoreInterface $selectionStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['ai_agent_modes']);
    $this->selectionStore = $this->container->get('ai_agent_modes.selection_store');
    $this->setUpCurrentUser();

    $storage = $this->container->get('entity_type.manager')->getStorage('ai_agent');
    foreach (['child_one' => 'Child One', 'child_two' => 'Child Two'] as $id => $label) {
      $storage->create([
        'id' => $id,
        'label' => $label,
        'description' => $label . ' does things.',
        'system_prompt' => 'You are ' . $label . '.',
        'tools' => [],
      ])->save();
    }
    $storage->create([
      'id' => $this->parentId,
      'label' => 'Test Orchestrator',
      'description' => 'Picks sub-agents.',
      'system_prompt' => 'You orchestrate sub-agents.',
      'orchestration_agent' => TRUE,
      'tools' => [
        'ai_agents::ai_agent::child_one' => TRUE,
        'ai_agents::ai_agent::child_two' => TRUE,
        'ai_agent:html_to_markdown' => TRUE,
      ],
    ])->save();
  }

  /**
   * Creates a mode and selects it for the parent agent.
   *
   * @param string $id
   *   The mode ID.
   * @param array $values
   *   Extra entity values, merged over the defaults.
   *
   * @return string
   *   The mode ID.
   */
  protected function selectMode(string $id, array $values = []): string {
    $this->container->get('entity_type.manager')->getStorage('ai_agent_mode')->create($values + [
      'id' => $id,
      'label' => 'Mode ' . $id,
      'status' => TRUE,
      'agent' => $this->parentId,
      'sub_agents' => ['child_one'],
      'system_prompt_addition' => 'Stay on task.',
    ])->save();
    $this->selectionStore->set($this->parentId, [], $id);
    return $id;
  }

  /**
   * Dispatches the prompt event and returns the resulting prompt.
   *
   * @param string $prompt
   *   The base system prompt.
   *
   * @return \Drupal\ai_agents\Event\BuildSystemPromptEvent
   *   The dispatched event, after any subscriber has changed it.
   */
  protected function dispatchPrompt(string $prompt): BuildSystemPromptEvent {
    $event = new BuildSystemPromptEvent($prompt, $this->parentId, []);
    $this->container->get('event_dispatcher')->dispatch($event, BuildSystemPromptEvent::EVENT_NAME);
    return $event;
  }

  /**
   * The directive is prepended on the prompt event.
   *
   * @covers ::onBuildSystemPrompt
   */
  public function testPromptEventInjects(): void {
    $this->selectMode('prompt_mode');
    $prompt = $this->dispatchPrompt('BASE PROMPT')->getSystemPrompt();

    $this->assertStringStartsWith(ModeManagerInterface::DIRECTIVE_MARKER, $prompt);
    $this->assertStringContainsString('child_one', $prompt);
    $this->assertStringContainsString('Stay on task.', $prompt);
    $this->assertStringEndsWith('BASE PROMPT', $prompt);
  }

  /**
   * A token written into a mode's own text is replaced by the agent.
   *
   * This is the regression test for the reported bug: injecting on the request
   * event happened after the agent had already replaced its tokens, so a token
   * in a mode's text was sent to the model literally.
   *
   * @covers ::onBuildSystemPrompt
   */
  public function testTokenInModeTextIsResolved(): void {
    $this->selectMode('token_mode', [
      'system_prompt_addition' => 'Agent instructions: [ai_agent:agent_instructions]',
    ]);

    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $event = $this->dispatchPrompt('BASE PROMPT');
    $this->assertStringContainsString('[ai_agent:agent_instructions]', $event->getSystemPrompt());

    // Mirror what the agent does with the event's result.
    $wrapper->setTokenContexts($event->getTokens());
    $resolved = $wrapper->applyTokens($event->getSystemPrompt());
    $this->assertStringNotContainsString('[ai_agent:agent_instructions]', $resolved);

    // The old seam ran after this replacement, which is why it could not work:
    // a token injected there is never looked at again.
    $late = new ChatInput([new ChatMessage('user', 'Hi.')]);
    $late->setSystemPrompt($resolved);
    $this->container->get('event_dispatcher')->dispatch(
      new AgentRequestEvent($wrapper, $late, $resolved, $this->parentId, '', [], 1, 'runner-1', NULL, NULL),
      AgentRequestEvent::EVENT_NAME,
    );
    $this->assertSame($resolved, $late->getSystemPrompt(), 'The request fallback stays out of the way once the prompt event has run.');
  }

  /**
   * The directive is never added twice when both events fire.
   *
   * @covers ::onBuildSystemPrompt
   * @covers ::onAgentRequest
   */
  public function testNoDoubleInjection(): void {
    $this->selectMode('once_mode');
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);

    $prompt = $this->dispatchPrompt('BASE PROMPT')->getSystemPrompt();
    $input = new ChatInput([new ChatMessage('user', 'Hi.')]);
    $input->setSystemPrompt($prompt);
    $this->container->get('event_dispatcher')->dispatch(
      new AgentRequestEvent($wrapper, $input, $prompt, $this->parentId, '', [], 1, 'runner-1', NULL, NULL),
      AgentRequestEvent::EVENT_NAME,
    );

    $this->assertSame(1, substr_count($input->getSystemPrompt(), ModeManagerInterface::DIRECTIVE_MARKER));
  }

  /**
   * The request event alone still applies the mode.
   *
   * Preserves the behaviour the module shipped with, for any dispatcher that
   * never fires the prompt event.
   *
   * @covers ::onAgentRequest
   */
  public function testRequestEventFallbackStillWorks(): void {
    $this->selectMode('fallback_mode');
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);

    $input = new ChatInput([new ChatMessage('user', 'Hi.')]);
    $input->setSystemPrompt('BASE PROMPT');
    $this->container->get('event_dispatcher')->dispatch(
      new AgentRequestEvent($wrapper, $input, 'BASE PROMPT', $this->parentId, '', [], 1, 'runner-1', NULL, NULL),
      AgentRequestEvent::EVENT_NAME,
    );

    $this->assertStringStartsWith(ModeManagerInterface::DIRECTIVE_MARKER, $input->getSystemPrompt());
  }

  /**
   * Each loop injects exactly once, with no accumulation.
   *
   * @covers ::onBuildSystemPrompt
   */
  public function testEachLoopInjectsOnce(): void {
    $this->selectMode('loop_mode');
    for ($loop = 0; $loop < 3; $loop++) {
      $prompt = $this->dispatchPrompt('BASE PROMPT')->getSystemPrompt();
      $this->assertSame(1, substr_count($prompt, ModeManagerInterface::DIRECTIVE_MARKER));
    }
  }

  /**
   * Dispatches the started-execution event for the parent agent.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper
   *   The agent wrapper.
   * @param string|null $caller_id
   *   The calling agent's runner ID, for a nested run.
   * @param string|null $thread_id
   *   The thread ID.
   */
  protected function dispatchStarted($wrapper, ?string $caller_id = NULL, ?string $thread_id = 'thread-1'): void {
    $event = new AgentStartedExecutionEvent($wrapper, $this->parentId, [], 'runner-1', 0, $thread_id, $caller_id);
    $this->container->get('event_dispatcher')->dispatch($event, AgentStartedExecutionEvent::EVENT_NAME);
  }

  /**
   * Returns the function names the agent would send to the provider.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper
   *   The agent wrapper.
   *
   * @return string[]
   *   The normalised function names.
   */
  protected function toolNames($wrapper): array {
    $functions = $wrapper->getFunctions();
    // The normalised list is keyed by tool plugin ID, so drop the keys: this
    // returns what the provider is told it may call.
    return array_values(array_map(
      static fn ($function): string => $function->getName(),
      $functions['normalized'] ?? [],
    ));
  }

  /**
   * A steering mode leaves the tool set exactly as configured.
   *
   * @covers ::onAgentStarted
   */
  public function testGuideModeLeavesToolsAlone(): void {
    $this->selectMode('guide_mode');
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $before = $this->toolNames($wrapper);
    $this->dispatchStarted($wrapper);
    $this->assertEqualsCanonicalizing($before, $this->toolNames($wrapper));
  }

  /**
   * A withholding mode removes the sub-agent tools it does not name.
   *
   * @covers ::onAgentStarted
   */
  public function testRestrictModeWithholds(): void {
    $this->selectMode('strict_mode', [
      'scope_strength' => AiAgentModeInterface::SCOPE_RESTRICT,
    ]);
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $this->dispatchStarted($wrapper);

    $names = $this->toolNames($wrapper);
    $this->assertContains('child_one', $names, 'The named sub-agent is kept.');
    $this->assertNotContains('child_two', $names, 'The unnamed sub-agent never reaches the provider.');
    $this->assertContains(
      'ai_agent_html_to_markdown',
      $names,
      "The agent's own tools are never withheld.",
    );
  }

  /**
   * A nested sub-agent run is never narrowed.
   *
   * @covers ::onAgentStarted
   */
  public function testNestedRunIsNeverNarrowed(): void {
    $this->selectMode('nested_mode', [
      'scope_strength' => AiAgentModeInterface::SCOPE_RESTRICT,
    ]);
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $before = $this->toolNames($wrapper);
    $this->dispatchStarted($wrapper, 'runner-parent');
    $this->assertEqualsCanonicalizing($before, $this->toolNames($wrapper));
  }

  /**
   * Clearing the selection gives the withheld tools back.
   *
   * Regression test for a stranded restriction: the override survives between
   * turns, so it has to be undone even when there is no longer a mode.
   *
   * @covers ::onAgentStarted
   */
  public function testSelectionClearedConverges(): void {
    $this->selectMode('converge_mode', [
      'scope_strength' => AiAgentModeInterface::SCOPE_RESTRICT,
    ]);
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $this->dispatchStarted($wrapper);
    $this->assertNotContains('child_two', $this->toolNames($wrapper));

    // Stand in for the between-turn round trip: what survives is the functions
    // override, which the AI Assistant API restores onto the next turn's
    // wrapper. Rebuilding it directly avoids needing a configured AI provider,
    // which toArray() would demand and which says nothing about this behaviour.
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $next_turn */
    $next_turn = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $next_turn->overrideFunctions(['tools' => ['ai_agents::ai_agent::child_one' => TRUE]]);

    $this->selectionStore->clear($this->parentId);
    $this->dispatchStarted($next_turn);
    $this->assertContains('child_two', $this->toolNames($next_turn), 'The restriction is undone once the mode is cleared.');
  }

  /**
   * Switching to a mode that withholds nothing gives the tools back.
   *
   * @covers ::onAgentStarted
   */
  public function testModeSwitchConverges(): void {
    $this->selectMode('switch_strict', [
      'scope_strength' => AiAgentModeInterface::SCOPE_RESTRICT,
    ]);
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $this->dispatchStarted($wrapper);
    $this->assertNotContains('child_two', $this->toolNames($wrapper));

    // Same stand-in for the surviving override as above.
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $next_turn */
    $next_turn = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $next_turn->overrideFunctions(['tools' => ['ai_agents::ai_agent::child_one' => TRUE]]);

    $this->selectMode('switch_guide');
    $this->dispatchStarted($next_turn);
    $this->assertContains('child_two', $this->toolNames($next_turn));
  }

  /**
   * The site-wide switch turns withholding off.
   *
   * @covers ::onAgentStarted
   */
  public function testKillSwitchOff(): void {
    $this->config('ai_agent_modes.settings')->set('tool_scope_enforcement', FALSE)->save();
    $this->selectMode('switched_off', [
      'scope_strength' => AiAgentModeInterface::SCOPE_RESTRICT,
    ]);
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $this->dispatchStarted($wrapper);
    $this->assertContains('child_two', $this->toolNames($wrapper));
  }

  /**
   * A site with no modes never has an agent's override touched.
   *
   * @covers ::onAgentStarted
   */
  public function testSiteWithNoModesIsNeverTouched(): void {
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    // A third party's own override must survive, which proves the module never
    // reached for resetFunctions().
    $wrapper->overrideFunctions(['tools' => ['ai_agents::ai_agent::child_one' => TRUE]]);
    $this->dispatchStarted($wrapper);
    $this->assertSame(['child_one'], $this->toolNames($wrapper));
  }

  /**
   * A mode limited to another assistant is not applied to this run.
   *
   * When the run carries no assistant identity at all the stored mode still
   * applies, which keeps the Canvas panel and the selector block working.
   *
   * @covers ::resolveFor
   */
  public function testAssistantLimitedModeIsWithheldForAnotherAssistant(): void {
    $this->selectMode('assistant_a_only', [
      'assistants' => ['assistant_a'],
    ]);
    /** @var \Drupal\ai_agent_modes\ActiveAssistantContext $context */
    $context = $this->container->get('ai_agent_modes.assistant_context');

    // Known and different: not applied.
    $context->note('thread-1', 'assistant_b');
    $this->assertStringNotContainsString(
      ModeManagerInterface::DIRECTIVE_MARKER,
      $this->promptForThread('thread-1'),
    );

    // Known and matching: applied.
    $context->note('thread-2', 'assistant_a');
    $this->assertStringContainsString(
      ModeManagerInterface::DIRECTIVE_MARKER,
      $this->promptForThread('thread-2'),
    );

    // Unknown: applied, deliberately.
    $this->assertStringContainsString(
      ModeManagerInterface::DIRECTIVE_MARKER,
      $this->promptForThread(NULL),
    );
  }

  /**
   * Runs the request event for one thread and returns the resulting prompt.
   *
   * The request event is used here because it is the only one of the three that
   * carries a thread ID.
   *
   * @param string|null $thread_id
   *   The thread ID.
   *
   * @return string
   *   The system prompt after the subscriber ran.
   */
  protected function promptForThread(?string $thread_id): string {
    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $wrapper */
    $wrapper = $this->container->get('plugin.manager.ai_agents')->createInstance($this->parentId);
    $input = new ChatInput([new ChatMessage('user', 'Hi.')]);
    $input->setSystemPrompt('BASE PROMPT');
    // Called directly rather than through the dispatcher: ai_agents' own
    // status subscriber expects a started-execution event for the same
    // runner first, and this case is only about the thread.
    $this->container->get('ai_agent_modes.agent_scope_subscriber')->onAgentRequest(
      new AgentRequestEvent($wrapper, $input, 'BASE PROMPT', $this->parentId, '', [], 1, 'runner-1', $thread_id, NULL),
    );
    return $input->getSystemPrompt();
  }

}
