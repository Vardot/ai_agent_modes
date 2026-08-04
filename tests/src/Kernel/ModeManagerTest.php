<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Kernel;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai_agent_modes\AiAgentModeInterface;
use Drupal\ai_agent_modes\ModeManager;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\ScopePayload;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Tests the mode manager: sub-agent listing, resolution and scope application.
 *
 * @group ai_agent_modes
 * @coversDefaultClass \Drupal\ai_agent_modes\ModeManager
 */
#[RunTestsInSeparateProcesses]
class ModeManagerTest extends KernelTestBase {

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
   * The mode manager under test.
   */
  protected ModeManagerInterface $modeManager;

  /**
   * The parent agent plugin ID used across the tests.
   */
  protected string $parentId = 'test_orchestrator';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->modeManager = $this->container->get('ai_agent_modes.manager');
    $this->createAgents();
  }

  /**
   * Creates a parent orchestrator with two sub-agent tools and its children.
   */
  protected function createAgents(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_agent');
    foreach (['child_one' => 'Child One', 'child_two' => 'Child Two', 'child_three' => 'Child Three'] as $id => $label) {
      $storage->create([
        'id' => $id,
        'label' => $label,
        'description' => $label . ' does things.',
        'system_prompt' => 'You are ' . $label . '.',
        'tools' => [],
      ])->save();
    }
    // Parent exposes child_one and child_two as sub-agents, and one tool.
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
   * Creates an enabled mode selecting a single sub-agent.
   *
   * @param string $sub_agent
   *   The sub-agent plugin ID the mode exposes.
   *
   * @return string
   *   The created mode ID.
   */
  protected function createMode(string $sub_agent): string {
    $this->container->get('entity_type.manager')->getStorage('ai_agent_mode')->create([
      'id' => 'only_' . $sub_agent,
      'label' => 'Only ' . $sub_agent,
      'status' => TRUE,
      'agent' => $this->parentId,
      'sub_agents' => [$sub_agent],
      'system_prompt_addition' => 'Focus on ' . $sub_agent . '.',
    ])->save();
    return 'only_' . $sub_agent;
  }

  /**
   * The sub-agent list is read from the parent's live tool list.
   *
   * @covers ::listSubAgents
   */
  public function testListSubAgents(): void {
    $sub_agents = $this->modeManager->listSubAgents($this->parentId);
    $this->assertEqualsCanonicalizing(['child_one', 'child_two'], array_keys($sub_agents));
    $this->assertSame('ai_agents::ai_agent::child_one', $sub_agents['child_one']['tool_id']);
    // The non-agent tool must not be treated as a sub-agent.
    $this->assertArrayNotHasKey('ai_agent:html_to_markdown', $sub_agents);
    // An unknown parent returns nothing rather than erroring.
    $this->assertSame([], $this->modeManager->listSubAgents('does_not_exist'));
  }

  /**
   * A saved mode and an ad-hoc selection both resolve to a scope payload.
   *
   * @covers ::resolve
   * @covers ::listModes
   */
  public function testResolve(): void {
    $mode_id = $this->createMode('child_one');

    $modes = $this->modeManager->listModes($this->parentId);
    $this->assertCount(1, $modes);
    $this->assertSame($mode_id, $modes[0]->id());

    // Resolve via the saved mode.
    $payload = $this->modeManager->resolve($this->parentId, [], $mode_id);
    $this->assertInstanceOf(ScopePayload::class, $payload);
    $this->assertSame(['child_one'], $payload->subAgents);
    $this->assertTrue($payload->isRestrictive());

    // Resolve via an ad-hoc selection.
    $adhoc = $this->modeManager->resolve($this->parentId, ['child_two']);
    $this->assertSame(['child_two'], $adhoc->subAgents);

    // A requested sub-agent that is not available is filtered out, which for a
    // single invalid pick means free-form (NULL).
    $this->assertNull($this->modeManager->resolve($this->parentId, ['not_a_child']));

    // No selection at all is free-form.
    $this->assertNull($this->modeManager->resolve($this->parentId, []));
  }

  /**
   * Applying a scope only prepends a directive and leaves tools untouched.
   *
   * @covers ::applyScope
   * @covers ::buildScopeDirective
   */
  public function testApplyScopeAddsDirective(): void {
    $input = new ChatInput([new ChatMessage('user', 'Build me a card.')]);
    $input->setSystemPrompt('BASE PROMPT.');
    $input->setChatTools(new ToolsInput([
      new ToolsFunctionInput('child_one'),
      new ToolsFunctionInput('child_two'),
      new ToolsFunctionInput('regular_tool'),
    ]));

    $payload = new ScopePayload($this->parentId, ['child_one'], 'Focus.');
    $applied = $this->modeManager->applyScope($payload, $input);
    $this->assertTrue($applied);

    // Tools are left untouched: the mode only adds guiding text.
    $names = array_map(
      static fn (ToolsFunctionInput $f): string => $f->getName(),
      $input->getChatTools()->getFunctions(),
    );
    $this->assertEqualsCanonicalizing(['child_one', 'child_two', 'regular_tool'], $names);

    // The directive is prepended to the system prompt and names the sub-agent.
    $prompt = $input->getSystemPrompt();
    $this->assertStringStartsWith('MODE (AI Agent Modes):', $prompt);
    $this->assertStringContainsString('child_one', $prompt);
    $this->assertStringContainsString('BASE PROMPT.', $prompt);
    $this->assertStringContainsString('Focus.', $prompt);
  }

  /**
   * The prompt seam and the chat-input seam produce the same string.
   *
   * @covers ::applyScopeToPrompt
   * @covers ::applyScope
   */
  public function testApplyScopeToPromptMatchesApplyScope(): void {
    $payload = new ScopePayload($this->parentId, ['child_one'], 'Focus.');
    $input = new ChatInput([new ChatMessage('user', 'Hi.')]);
    $input->setSystemPrompt('BASE PROMPT.');
    $this->modeManager->applyScope($payload, $input);
    $this->assertSame(
      $input->getSystemPrompt(),
      $this->modeManager->applyScopeToPrompt($payload, 'BASE PROMPT.'),
    );
  }

  /**
   * Every generated directive opens with the shared marker constant.
   *
   * @covers ::buildScopeDirective
   */
  public function testDirectiveMarkerIsTheOnlySource(): void {
    $with_sub_agents = new ScopePayload($this->parentId, ['child_one']);
    $prompt_only = new ScopePayload($this->parentId, [], 'Just guidance.');
    foreach ([$with_sub_agents, $prompt_only] as $payload) {
      $this->assertStringStartsWith(
        ModeManagerInterface::DIRECTIVE_MARKER,
        $this->modeManager->buildScopeDirective($payload),
      );
    }
  }

  /**
   * The scope strength travels from the mode onto the payload.
   *
   * A generic mode is downgraded, because its sub-agent names mean nothing for
   * whichever agent happens to be running, and an ad-hoc pick never withholds.
   *
   * @covers ::resolve
   */
  public function testResolveCarriesScopeStrength(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_agent_mode');
    $storage->create([
      'id' => 'strict_mode',
      'label' => 'Strict mode',
      'status' => TRUE,
      'agent' => $this->parentId,
      'sub_agents' => ['child_one'],
      'scope_strength' => AiAgentModeInterface::SCOPE_RESTRICT,
    ])->save();
    $storage->create([
      'id' => 'strict_generic',
      'label' => 'Strict generic',
      'status' => TRUE,
      'agent' => '',
      'sub_agents' => ['child_one'],
      'system_prompt_addition' => 'Generic guidance.',
      'scope_strength' => AiAgentModeInterface::SCOPE_RESTRICT,
    ])->save();

    $strict = $this->modeManager->resolve($this->parentId, [], 'strict_mode');
    $this->assertSame(AiAgentModeInterface::SCOPE_RESTRICT, $strict->scopeStrength);

    $generic = $this->modeManager->resolve($this->parentId, [], 'strict_generic');
    $this->assertSame(AiAgentModeInterface::SCOPE_GUIDE, $generic->scopeStrength);

    $adhoc = $this->modeManager->resolve($this->parentId, ['child_two']);
    $this->assertSame(AiAgentModeInterface::SCOPE_GUIDE, $adhoc->scopeStrength);
  }

  /**
   * Only the sub-agent tools a restrict mode omits are withheld.
   *
   * @covers ::restrictedTools
   */
  public function testRestrictedToolsWithholdsOnlySubAgents(): void {
    $payload = new ScopePayload($this->parentId, ['child_one'], '', 'Strict', AiAgentModeInterface::SCOPE_RESTRICT);
    $tools = $this->modeManager->restrictedTools($payload, [
      'ai_agents::ai_agent::child_one' => TRUE,
      'ai_agents::ai_agent::child_two' => TRUE,
      'ai_agent:html_to_markdown' => TRUE,
      'ai_agents::ai_agent::child_three' => FALSE,
    ]);

    // The named sub-agent and the agent's own tool are kept.
    $this->assertTrue($tools['ai_agents::ai_agent::child_one']);
    $this->assertTrue($tools['ai_agent:html_to_markdown']);
    // The unnamed sub-agent is withheld.
    $this->assertFalse($tools['ai_agents::ai_agent::child_two']);
    // An already disabled tool is copied through, never re-enabled.
    $this->assertFalse($tools['ai_agents::ai_agent::child_three']);
  }

  /**
   * Every guard that makes restrictedTools() decline to withhold anything.
   *
   * @covers ::restrictedTools
   */
  public function testRestrictedToolsGuards(): void {
    $live = [
      'ai_agents::ai_agent::child_one' => TRUE,
      'ai_agents::ai_agent::child_two' => TRUE,
    ];

    // A steering payload never withholds.
    $guide = new ScopePayload($this->parentId, ['child_one'], '', 'Guide');
    $this->assertSame([], $this->modeManager->restrictedTools($guide, $live));

    // A restrict payload that resolved to no sub-agent steers only.
    $empty = new ScopePayload($this->parentId, [], 'Prompt only.', 'Strict', AiAgentModeInterface::SCOPE_RESTRICT);
    $this->assertSame([], $this->modeManager->restrictedTools($empty, $live));

    // Naming everything the agent has flips nothing, so the override is not
    // claimed at all.
    $all = new ScopePayload($this->parentId, ['child_one', 'child_two'], '', 'Strict', AiAgentModeInterface::SCOPE_RESTRICT);
    $this->assertSame([], $this->modeManager->restrictedTools($all, $live));

    // A map that would leave the agent with no tool at all is refused.
    $unknown = new ScopePayload($this->parentId, ['child_three'], '', 'Strict', AiAgentModeInterface::SCOPE_RESTRICT);
    $this->assertSame([], $this->modeManager->restrictedTools($unknown, $live));

    // The site-wide switch turns withholding off without touching any mode.
    $this->config('ai_agent_modes.settings')->set('tool_scope_enforcement', FALSE)->save();
    $strict = new ScopePayload($this->parentId, ['child_one'], '', 'Strict', AiAgentModeInterface::SCOPE_RESTRICT);
    $this->assertSame([], $this->modeManager->restrictedTools($strict, $live));
  }

  /**
   * An absent enforcement setting counts as enabled.
   *
   * Guards the upgrade path: config/install is only read at install time, so an
   * existing site reads NULL until the post-update runs, and NULL must not mean
   * "feature off".
   *
   * @covers ::restrictedTools
   */
  public function testToolScopeEnforcementDefaultsOnWhenKeyAbsent(): void {
    $this->config('ai_agent_modes.settings')->clear('tool_scope_enforcement')->save();
    $payload = new ScopePayload($this->parentId, ['child_one'], '', 'Strict', AiAgentModeInterface::SCOPE_RESTRICT);
    $tools = $this->modeManager->restrictedTools($payload, [
      'ai_agents::ai_agent::child_one' => TRUE,
      'ai_agents::ai_agent::child_two' => TRUE,
    ]);
    $this->assertFalse($tools['ai_agents::ai_agent::child_two']);
  }

  /**
   * The site-wide mode gate answers honestly.
   *
   * @covers ::hasAnyMode
   */
  public function testHasAnyMode(): void {
    $this->assertFalse($this->modeManager->hasAnyMode());
    $this->createMode('child_one');
    $this->assertTrue($this->modeManager->hasAnyMode());
  }

  /**
   * An empty parent agent means generic modes only, never agent-bound ones.
   *
   * This is what lets an AI Assistant with no agent be offered modes safely.
   *
   * @covers ::listModes
   */
  public function testListModesGenericOnlyForEmptyString(): void {
    $bound = $this->createMode('child_one');
    $this->container->get('entity_type.manager')->getStorage('ai_agent_mode')->create([
      'id' => 'generic_mode',
      'label' => 'Generic mode',
      'status' => TRUE,
      'agent' => '',
      'system_prompt_addition' => 'Answer plainly.',
    ])->save();

    $ids = array_map(
      static fn ($mode): string => (string) $mode->id(),
      $this->modeManager->listModes(''),
    );
    $this->assertContains('generic_mode', $ids);
    $this->assertNotContains($bound, $ids);
  }

  /**
   * A mode depends on its parent agent, and on nothing else it names.
   *
   * Deleting the agent should take the mode with it, because a mode is a subset
   * of one agent. Deleting an unrelated sub-agent must not, because the module
   * already drops unavailable sub-agent names at run time and losing the whole
   * mode would throw away an administrator's work.
   *
   * @covers \Drupal\ai_agent_modes\Entity\AiAgentMode::calculateDependencies
   */
  public function testDependenciesNameOnlyTheParentAgent(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_agent_mode');
    $storage->create([
      'id' => 'depends_mode',
      'label' => 'Depends mode',
      'status' => TRUE,
      'agent' => $this->parentId,
      'sub_agents' => ['child_one'],
      'assistants' => ['some_assistant'],
    ])->save();

    $config = $this->config('ai_agent_modes.ai_agent_mode.depends_mode');
    $dependencies = $config->get('dependencies')['config'] ?? [];
    $this->assertContains('ai_agents.ai_agent.' . $this->parentId, $dependencies);
    $this->assertNotContains('ai_agents.ai_agent.child_one', $dependencies);
    $this->assertNotContains('ai_assistant_api.ai_assistant.some_assistant', $dependencies);

    // A generic mode names no agent, so it depends on nothing.
    $storage->create([
      'id' => 'generic_depends',
      'label' => 'Generic depends',
      'status' => TRUE,
      'agent' => '',
      'system_prompt_addition' => 'Guidance.',
    ])->save();
    $this->assertSame(
      [],
      $this->config('ai_agent_modes.ai_agent_mode.generic_depends')->get('dependencies')['config'] ?? [],
    );
  }

  /**
   * Switching enforcement off is stated in the log, not silent.
   *
   * Without the message an administrator reading the log cannot tell whether
   * the switch or the mode itself was the reason nothing was withheld.
   *
   * @covers ::restrictedTools
   */
  public function testKillSwitchIsLogged(): void {
    $this->config('ai_agent_modes.settings')->set('tool_scope_enforcement', FALSE)->save();

    // A real channel with a spy attached, so the assertion is on the message
    // the module actually logs.
    $spy = new class() extends AbstractLogger {

      /**
       * Every message logged, in order.
       *
       * @var string[]
       */
      public array $messages = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = []): void {
        $this->messages[] = strtr((string) $message, $context);
      }

    };
    $channel = new LoggerChannel('ai_agent_modes_test');
    $channel->addLogger($spy);
    $manager = new ModeManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('plugin.manager.ai_agents'),
      $this->container->get('plugin.manager.ai.function_calls'),
      $this->container->get('config.factory'),
      $channel,
    );

    $payload = new ScopePayload($this->parentId, ['child_one'], '', 'Strict mode', AiAgentModeInterface::SCOPE_RESTRICT);
    $this->assertSame([], $manager->restrictedTools($payload, [
      'ai_agents::ai_agent::child_one' => TRUE,
      'ai_agents::ai_agent::child_two' => TRUE,
    ]));

    $this->assertCount(1, $spy->messages);
    $this->assertStringContainsString('Strict mode', $spy->messages[0]);
    $this->assertStringContainsString('enforcement is switched off', $spy->messages[0]);
  }

  /**
   * A mode limited to selected AI Assistants is only offered for those.
   *
   * The assistants are named by ID, so this holds whether or not the AI
   * Assistant API is installed: the mode is offered for the assistant it
   * names, withheld from every other assistant, and withheld from a surface
   * with no assistant at all (the Drupal Canvas AI panel, which is driven by
   * an agent).
   *
   * @covers ::listModes
   */
  public function testAssistantRestrictedModes(): void {
    $open_mode = $this->createMode('child_one');
    $this->container->get('entity_type.manager')->getStorage('ai_agent_mode')->create([
      'id' => 'support_only',
      'label' => 'Support assistant only',
      'status' => TRUE,
      'agent' => $this->parentId,
      'sub_agents' => ['child_two'],
      'assistants' => ['support_assistant'],
    ])->save();

    $ids = static fn (array $modes): array => array_map(
      static fn ($mode): string => (string) $mode->id(),
      $modes,
    );

    // No assistant in play: only the unrestricted mode is offered.
    $this->assertSame([$open_mode], $ids($this->modeManager->listModes($this->parentId)));

    // The named assistant gets both.
    $this->assertEqualsCanonicalizing(
      [$open_mode, 'support_only'],
      $ids($this->modeManager->listModes($this->parentId, NULL, 'support_assistant')),
    );

    // Another assistant gets only the unrestricted one.
    $this->assertSame(
      [$open_mode],
      $ids($this->modeManager->listModes($this->parentId, NULL, 'sales_assistant')),
    );

    /** @var \Drupal\ai_agent_modes\AiAgentModeInterface $mode */
    $mode = $this->container->get('entity_type.manager')
      ->getStorage('ai_agent_mode')
      ->load('support_only');
    $this->assertSame(['support_assistant'], $mode->getAssistants());
    $this->assertTrue($mode->appliesToAssistant('support_assistant'));
    $this->assertFalse($mode->appliesToAssistant('sales_assistant'));
    $this->assertFalse($mode->appliesToAssistant(NULL));
    $this->assertFalse($mode->appliesToAssistant(''));

    // A mode that names no assistant is offered everywhere, assistant or not.
    /** @var \Drupal\ai_agent_modes\AiAgentModeInterface $open */
    $open = $this->container->get('entity_type.manager')
      ->getStorage('ai_agent_mode')
      ->load($open_mode);
    $this->assertSame([], $open->getAssistants());
    $this->assertTrue($open->appliesToAssistant(NULL));
    $this->assertTrue($open->appliesToAssistant('support_assistant'));

    // The restriction does not change how a selection resolves: a mode is
    // resolved by ID once it has been chosen.
    $payload = $this->modeManager->resolve($this->parentId, [], 'support_only');
    $this->assertSame(['child_two'], $payload->subAgents);
  }

  /**
   * A mode with no sub-agents still steers when it carries a prompt directive.
   *
   * This covers the Varbase AI Figma integration: modes like "Build a page
   * from Figma" point at orchestrator-level tools rather than a sub-agent,
   * so they ship with an empty sub_agents list and rely on the directive
   * alone. Regression guard for the 2026-07-20 isRestrictive()/resolve()
   * change: a mode with neither must still resolve to free-form (NULL).
   *
   * @covers ::resolve
   * @covers ::buildScopeDirective
   */
  public function testPromptOnlyModeStillSteers(): void {
    $this->container->get('entity_type.manager')->getStorage('ai_agent_mode')->create([
      'id' => 'figma_workflow',
      'label' => 'Figma workflow',
      'status' => TRUE,
      'agent' => $this->parentId,
      'sub_agents' => [],
      'system_prompt_addition' => 'Use the Figma tools in order.',
    ])->save();

    $payload = $this->modeManager->resolve($this->parentId, [], 'figma_workflow');
    $this->assertInstanceOf(ScopePayload::class, $payload);
    $this->assertSame([], $payload->subAgents);
    $this->assertTrue($payload->isRestrictive());

    // The directive skips the sub-agent routing line (nothing to route to)
    // but still opens with the MODE marker and carries the guidance.
    $directive = $this->modeManager->buildScopeDirective($payload);
    $this->assertStringStartsWith('MODE (AI Agent Modes): Follow this working mode', $directive);
    $this->assertStringNotContainsString('use the following sub-agent(s)', $directive);
    $this->assertStringContainsString('Use the Figma tools in order.', $directive);

    // A disabled mode with the same shape resolves to NULL, not applied.
    $this->container->get('entity_type.manager')->getStorage('ai_agent_mode')->create([
      'id' => 'disabled_prompt_only',
      'label' => 'Disabled prompt-only',
      'status' => FALSE,
      'agent' => $this->parentId,
      'sub_agents' => [],
      'system_prompt_addition' => 'Should never apply.',
    ])->save();
    $this->assertNull($this->modeManager->resolve($this->parentId, [], 'disabled_prompt_only'));

    // A ScopePayload with neither sub-agents nor a directive is not
    // restrictive: an empty ad-hoc selection stays free-form.
    $empty = new ScopePayload($this->parentId, []);
    $this->assertFalse($empty->isRestrictive());
    $this->assertSame('', $this->modeManager->buildScopeDirective($empty));
  }

}
