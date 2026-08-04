<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Unit\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_agent_modes\Hook\ChatbotHooks;
use Drupal\ai_agent_modes\ModeManagerInterface;

/**
 * Tests the DeepChat block integration added by ChatbotHooks.
 *
 * The `ai_chatbot` module ships two ways to place an assistant: a classic
 * Drupal form (handled by ChatFormHooks via hook_form_alter) and a
 * client-rendered `<deep-chat>` block (`ai_deepchat_block`), which Drupal
 * CMS's own `drupal_cms_ai` recipe places by default. ChatbotHooks is the
 * soft, client-side integration for that second surface; see
 * docs/ai_agent_modes/00-analysis.md for how this was root-caused.
 *
 * @group ai_agent_modes
 * @coversDefaultClass \Drupal\ai_agent_modes\Hook\ChatbotHooks
 */
class ChatbotHooksTest extends UnitTestCase {

  /**
   * Builds a ChatbotHooks instance with the given mocked collaborators.
   */
  protected function buildHooks(
    bool $ai_assistant_api_exists,
    ?string $agent_id,
    array $modes,
    array $sub_agents,
    string $position = 'above_chat',
    string $assistant_override = '',
  ): ChatbotHooks {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->with('ai_assistant_api')
      ->willReturn($ai_assistant_api_exists);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    if ($ai_assistant_api_exists) {
      $assistant = NULL;
      if ($agent_id !== NULL) {
        $assistant = new class($agent_id, $assistant_override) {

          /**
           * Constructs the stub assistant.
           */
          public function __construct(protected string $agentId, protected string $override) {}

          /**
           * Stubs AiAssistantInterface::get().
           */
          public function get(string $key) {
            return $key === 'ai_agent' ? $this->agentId : NULL;
          }

          /**
           * Stubs the config entity's third-party settings.
           */
          public function getThirdPartySetting(string $module, string $key, $default = NULL) {
            return $module === 'ai_agent_modes' && $key === 'chatbot_position' && $this->override !== ''
              ? $this->override
              : $default;
          }

        };
      }
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->with('the_assistant')->willReturn($assistant);
      $entityTypeManager->method('getStorage')->with('ai_assistant')->willReturn($storage);
    }

    $modeManager = $this->createMock(ModeManagerInterface::class);
    $modeManager->method('listModes')->willReturn($modes);
    $modeManager->method('listSubAgents')->willReturn($sub_agents);

    $config = $this->createMock(Config::class);
    $config->method('get')->with('chatbot_position')->willReturn($position);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('ai_agent_modes.settings')->willReturn($config);

    return new ChatbotHooks($moduleHandler, $entityTypeManager, $modeManager, $configFactory);
  }

  /**
   * The DeepChat library gains our JS as a dependency, only for ai_chatbot.
   *
   * @covers ::libraryInfoAlter
   */
  public function testLibraryInfoAlterOnlyTargetsAiChatbotDeepchat(): void {
    $hooks = $this->buildHooks(TRUE, NULL, [], []);

    $libraries = ['deepchat' => ['dependencies' => []]];
    $hooks->libraryInfoAlter($libraries, 'ai_chatbot');
    $this->assertSame(
      ['ai_agent_modes/chatbot_deepchat'],
      $libraries['deepchat']['dependencies'],
    );

    // A different extension, or one without a "deepchat" library, is left
    // untouched.
    $other = ['deepchat' => ['dependencies' => []]];
    $hooks->libraryInfoAlter($other, 'some_other_module');
    $this->assertSame([], $other['deepchat']['dependencies']);

    $no_deepchat = ['other_library' => ['dependencies' => []]];
    $hooks->libraryInfoAlter($no_deepchat, 'ai_chatbot');
    $this->assertSame(['other_library' => ['dependencies' => []]], $no_deepchat);
  }

  /**
   * Nothing is attached when ai_assistant_api is absent (soft dependency).
   *
   * @covers ::blockViewAlter
   */
  public function testBlockViewAlterSkipsWithoutAiAssistantApi(): void {
    $hooks = $this->buildHooks(FALSE, 'drupal_cms_assistant', [], ['child_one' => []]);
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => 'the_assistant']);

    $build = [];
    $hooks->blockViewAlter($build, $block);
    $this->assertSame([], $build);
  }

  /**
   * Nothing is attached when the block has no configured assistant.
   *
   * @covers ::blockViewAlter
   */
  public function testBlockViewAlterSkipsWithoutAssistantConfigured(): void {
    $hooks = $this->buildHooks(TRUE, 'drupal_cms_assistant', [], ['child_one' => []]);
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => '']);

    $build = [];
    $hooks->blockViewAlter($build, $block);
    $this->assertSame([], $build);
  }

  /**
   * Nothing is attached for a legacy assistant with no backing agent.
   *
   * @covers ::blockViewAlter
   */
  public function testBlockViewAlterSkipsAssistantWithoutAgent(): void {
    $hooks = $this->buildHooks(TRUE, NULL, [], ['child_one' => []]);
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => 'the_assistant']);

    $build = [];
    $hooks->blockViewAlter($build, $block);
    $this->assertSame([], $build);
  }

  /**
   * Nothing is attached when the agent has neither modes nor sub-agents.
   *
   * @covers ::blockViewAlter
   */
  public function testBlockViewAlterSkipsWithNothingToScope(): void {
    $hooks = $this->buildHooks(TRUE, 'drupal_cms_assistant', [], []);
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => 'the_assistant']);

    $build = [];
    $hooks->blockViewAlter($build, $block);
    $this->assertSame([], $build);
  }

  /**
   * The library and settings are attached when there is something to scope.
   *
   * This is the fix for the Drupal-CMS-specific gap: drupal_cms_ai's default
   * block is the DeepChat variant, which never runs through the Form API, so
   * ChatFormHooks::chatFormAlter() (hook_form_ai_foundation_chat_alter) never
   * fires for it.
   *
   * @covers ::blockViewAlter
   */
  public function testBlockViewAlterAttachesWhenAgentHasSubAgents(): void {
    $hooks = $this->buildHooks(TRUE, 'drupal_cms_assistant', [], ['child_one' => []]);
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => 'the_assistant']);

    $build = [];
    $hooks->blockViewAlter($build, $block);

    $this->assertContains('ai_agent_modes/chatbot_deepchat', $build['#attached']['library']);
    // The assistant travels to the script alongside the agent, so the options
    // endpoint can offer the modes limited to this assistant, and so does the
    // placement the site chose for the chatbot panel.
    $this->assertSame(
      [
        'agent' => 'drupal_cms_assistant',
        'assistant' => 'the_assistant',
        'position' => 'above_chat',
      ],
      $build['#attached']['drupalSettings']['aiAgentModesChatbot'],
    );
    $this->assertContains('config:ai_agent_modes.settings', $build['#cache']['tags']);
    $this->assertContains('user', $build['#cache']['contexts']);
  }

  /**
   * The configured chatbot placement is what the script is told to use.
   *
   * @covers ::blockViewAlter
   */
  public function testConfiguredPositionIsPassedThrough(): void {
    $hooks = $this->buildHooks(TRUE, 'drupal_cms_assistant', [], ['child_one' => []], 'header');
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => 'the_assistant']);

    $build = [];
    $hooks->blockViewAlter($build, $block);

    $this->assertSame(
      'header',
      $build['#attached']['drupalSettings']['aiAgentModesChatbot']['position'],
    );
  }

  /**
   * An assistant's own placement beats the site setting.
   *
   * @covers ::blockViewAlter
   */
  public function testAssistantOverrideBeatsTheSiteSetting(): void {
    $hooks = $this->buildHooks(TRUE, 'drupal_cms_assistant', [], ['child_one' => []], 'above_chat', 'header');
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => 'the_assistant']);

    $build = [];
    $hooks->blockViewAlter($build, $block);

    $this->assertSame(
      'header',
      $build['#attached']['drupalSettings']['aiAgentModesChatbot']['position'],
    );
  }

  /**
   * An unknown stored override falls back to the site setting.
   *
   * @covers ::blockViewAlter
   */
  public function testUnknownAssistantOverrideFallsBack(): void {
    $hooks = $this->buildHooks(TRUE, 'drupal_cms_assistant', [], ['child_one' => []], 'below_input', 'nonsense');
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => 'the_assistant']);

    $build = [];
    $hooks->blockViewAlter($build, $block);

    $this->assertSame(
      'below_input',
      $build['#attached']['drupalSettings']['aiAgentModesChatbot']['position'],
    );
  }

}
