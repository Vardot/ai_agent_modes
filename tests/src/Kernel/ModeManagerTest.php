<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\ScopePayload;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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
        'some:regular_tool' => TRUE,
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
    // The regular tool must not be treated as a sub-agent.
    $this->assertArrayNotHasKey('some', $sub_agents);
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
   * A mode with no sub-agents still steers when it carries a prompt directive.
   *
   * This covers the Varbase AI Figma integration: modes like "Build a page
   * from Figma" point at orchestrator-level tools rather than a sub-agent,
   * so they ship with an empty sub_agents list and rely on the directive
   * alone. Regression guard for the 2026-07-20 isRestrictive()/resolve()
   * change — a mode with neither must still resolve to free-form (NULL).
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
