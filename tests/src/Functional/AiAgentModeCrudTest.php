<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests AI agent mode CRUD and the selector dropdown from live sub-agents.
 *
 * @group ai_agent_modes
 */
#[RunTestsInSeparateProcesses]
class AiAgentModeCrudTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'ai',
    'ai_agents',
    'ai_agent_modes',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The parent agent plugin ID.
   */
  protected string $parentId = 'test_orchestrator';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The "stark" test theme has no default block layout, so local action
    // links (e.g. "Add AI agent mode") are never rendered unless this block
    // is placed explicitly. Same pattern as core's LocalActionTest.
    $this->drupalPlaceBlock('local_actions_block');
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_agent');
    foreach (['child_one' => 'Child One', 'child_two' => 'Child Two'] as $id => $label) {
      $storage->create([
        'id' => $id,
        'label' => $label,
        'description' => $label,
        'system_prompt' => 'You are ' . $label . '.',
        'tools' => [],
      ])->save();
    }
    $storage->create([
      'id' => $this->parentId,
      'label' => 'Test Orchestrator',
      'description' => 'Picks sub-agents.',
      'system_prompt' => 'You orchestrate.',
      'orchestration_agent' => TRUE,
      'tools' => [
        'ai_agents::ai_agent::child_one' => TRUE,
        'ai_agents::ai_agent::child_two' => TRUE,
      ],
    ])->save();
  }

  /**
   * Creates a mode through the admin UI and checks it is scoped correctly.
   */
  public function testCreateAndListMode(): void {
    // Access is protected by the dedicated permission.
    $this->drupalGet('admin/config/ai/agent-modes');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser(['administer ai agent modes']);
    $this->drupalLogin($admin);

    $this->drupalGet('admin/config/ai/agent-modes');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Agent Modes');
    $this->assertSession()->linkExists('Add AI agent mode');

    // The add form offers the parent agent's live sub-agents as checkboxes.
    $this->drupalGet('admin/config/ai/agent-modes/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Sub-agents in this mode');
    $this->assertSession()->fieldExists('sub_agents[child_one]');
    $this->assertSession()->fieldExists('sub_agents[child_two]');

    $this->submitForm([
      'label' => 'Only Child One',
      'id' => 'only_child_one',
      'agent' => $this->parentId,
      'sub_agents[child_one]' => TRUE,
      'system_prompt_addition' => 'Focus on child one.',
    ], 'Save');

    $this->assertSession()->pageTextContains('The AI agent mode Only Child One has been saved.');
    $this->assertSession()->pageTextContains('Only Child One');

    // The stored config entity holds exactly the selected subset.
    $mode = $this->container->get('entity_type.manager')
      ->getStorage('ai_agent_mode')
      ->load('only_child_one');
    $this->assertNotNull($mode);
    $this->assertSame([$this->parentId], [$mode->getAgent()]);
    $this->assertSame(['child_one'], $mode->getSubAgents());
    $this->assertSame('Focus on child one.', $mode->getSystemPromptAddition());
  }

  /**
   * The selector block renders a dropdown built from the live sub-agents.
   */
  public function testSelectorBlockDropdown(): void {
    $this->drupalPlaceBlock('ai_agent_mode_selector', [
      'parent_agent' => $this->parentId,
    ]);

    $user = $this->drupalCreateUser();
    $this->drupalLogin($user);
    $this->drupalGet('<front>');

    $this->assertSession()->fieldExists('mode');
    // Free-form default plus one option per live sub-agent.
    $this->assertSession()->optionExists('mode', 'Free-form (all sub-agents)');
    $this->assertSession()->optionExists('mode', 'Child One');
    $this->assertSession()->optionExists('mode', 'Child Two');
  }

}
