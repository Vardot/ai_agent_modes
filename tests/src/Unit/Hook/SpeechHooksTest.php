<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Unit\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_agent_modes\Hook\SpeechHooks;

/**
 * Tests the speech settings carried to the chat by SpeechHooks.
 *
 * The speaking and the listening are deep-chat's, done in the browser, so what
 * is worth asserting here is what PHP decides: which library each surface gets,
 * the two payloads handed to deep-chat, and whose choice wins when one AI
 * Assistant disagrees with the site setting.
 *
 * @group ai_agent_modes
 * @coversDefaultClass \Drupal\ai_agent_modes\Hook\SpeechHooks
 */
class SpeechHooksTest extends UnitTestCase {

  /**
   * Builds a SpeechHooks instance with the given mocked collaborators.
   *
   * @param array $settings
   *   The values ai_agent_modes.settings reports.
   * @param bool $canvas_ai_exists
   *   Whether the Canvas AI module is installed.
   * @param bool $assistant_api_exists
   *   Whether the AI Assistant API is installed.
   * @param array|null $overrides
   *   The third-party settings of the loaded assistant, or NULL for no
   *   assistant at all.
   *
   * @return \Drupal\ai_agent_modes\Hook\SpeechHooks
   *   The hooks object under test.
   */
  protected function buildHooks(array $settings, bool $canvas_ai_exists = TRUE, bool $assistant_api_exists = TRUE, ?array $overrides = NULL): SpeechHooks {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')
      ->willReturnCallback(static fn (string $module): bool => match ($module) {
        'canvas_ai' => $canvas_ai_exists,
        'ai_assistant_api' => $assistant_api_exists,
        default => FALSE,
      });

    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static fn (string $key) => $settings[$key] ?? NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    if ($overrides !== NULL) {
      $assistant = $this->createMock(ConfigEntityInterface::class);
      $assistant->method('getThirdPartySetting')
        ->willReturnCallback(static fn (string $module, string $key, mixed $default = NULL) => $overrides[$key] ?? $default);
      $assistant->method('getCacheTags')
        ->willReturn(['config:ai_assistant_api.ai_assistant.stub']);
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturn($assistant);
      $entityTypeManager->method('getStorage')->willReturn($storage);
    }

    return new SpeechHooks($moduleHandler, $entityTypeManager, $configFactory);
  }

  /**
   * The Canvas editor bundle carries the behaviour when Canvas AI is there.
   *
   * @covers ::libraryInfoAlter
   */
  public function testCanvasLibraryGetsTheBehaviour(): void {
    $libraries = ['canvas-ui' => ['dependencies' => ['canvas/existing']]];
    $this->buildHooks([])->libraryInfoAlter($libraries, 'canvas');
    $this->assertContains(SpeechHooks::LIBRARY, $libraries['canvas-ui']['dependencies']);
  }

  /**
   * Nothing is added to Canvas when the Canvas AI module is absent.
   *
   * @covers ::libraryInfoAlter
   */
  public function testCanvasLibraryUntouchedWithoutCanvasAi(): void {
    $libraries = ['canvas-ui' => ['dependencies' => ['canvas/existing']]];
    $this->buildHooks([], FALSE)->libraryInfoAlter($libraries, 'canvas');
    $this->assertSame(['canvas/existing'], $libraries['canvas-ui']['dependencies']);
  }

  /**
   * The AI Chatbot's own deep-chat library carries the behaviour too.
   *
   * @covers ::libraryInfoAlter
   */
  public function testChatbotLibraryGetsTheBehaviour(): void {
    $libraries = ['deepchat' => ['dependencies' => []]];
    $this->buildHooks([])->libraryInfoAlter($libraries, 'ai_chatbot');
    $this->assertSame([SpeechHooks::LIBRARY], $libraries['deepchat']['dependencies']);
  }

  /**
   * An unrelated extension's libraries are left alone.
   *
   * @covers ::libraryInfoAlter
   */
  public function testUnrelatedLibrariesUntouched(): void {
    $libraries = ['some-library' => ['dependencies' => []]];
    $this->buildHooks([])->libraryInfoAlter($libraries, 'some_module');
    $this->assertSame(['some-library' => ['dependencies' => []]], $libraries);
  }

  /**
   * With both halves off, no settings entry is put on the page at all.
   *
   * @covers ::jsSettingsAlter
   */
  public function testNoSettingsWhenBothAreOff(): void {
    $settings = [];
    $this->buildHooks([
      'speech_to_text' => ['enabled' => FALSE],
      'text_to_speech' => ['enabled' => FALSE],
    ])->jsSettingsAlter($settings, NULL);
    $this->assertArrayNotHasKey(SpeechHooks::SETTINGS_KEY, $settings);
  }

  /**
   * Reading replies aloud alone is enough to deliver the settings.
   *
   * @covers ::jsSettingsAlter
   */
  public function testSettingsDeliveredForReadingAloneToo(): void {
    $settings = [];
    $this->buildHooks([
      'speech_to_text' => ['enabled' => FALSE],
      'text_to_speech' => ['enabled' => TRUE, 'language' => 'en-GB', 'rate' => 1.2],
    ])->jsSettingsAlter($settings, NULL);

    $delivered = $settings[SpeechHooks::SETTINGS_KEY];
    $this->assertFalse($delivered['speechToText']);
    $this->assertTrue($delivered['textToSpeech']);
    $this->assertSame('en-GB', $delivered['textToSpeechLanguage']);
    $this->assertSame(['rate' => 1.2], $delivered['textToSpeechConfig']);
  }

  /**
   * A voice left at its own pitch, speed and volume is not described at all.
   *
   * The browser has its own neutral values, and sending 1 for all three would
   * freeze today's idea of neutral into every site's configuration.
   *
   * @covers ::jsSettingsAlter
   */
  public function testNeutralVoiceValuesAreNotSent(): void {
    $settings = [];
    $this->buildHooks([
      'text_to_speech' => [
        'enabled' => TRUE,
        'pitch' => 1,
        'rate' => 1,
        'volume' => 1,
        'voice_name' => '  ',
      ],
    ])->jsSettingsAlter($settings, NULL);
    $this->assertSame([], $settings[SpeechHooks::SETTINGS_KEY]['textToSpeechConfig']);
  }

  /**
   * The microphone payload carries every option the site set.
   *
   * @covers ::jsSettingsAlter
   */
  public function testMicrophonePayload(): void {
    $settings = [];
    $this->buildHooks([
      'speech_to_text' => [
        'enabled' => TRUE,
        'position' => 'outside_end',
        'language' => 'site',
        'display_interim_results' => FALSE,
        'stop_after_submit' => FALSE,
        'submit_after_silence' => TRUE,
        'submit_after_silence_ms' => 3500,
        'interim_color' => ' gray ',
        'final_color' => '',
        'commands' => [
          'stop' => 'stop listening',
          'submit' => 'send it',
          'substrings' => FALSE,
          'case_sensitive' => TRUE,
        ],
        'translations' => "varbase|Varbase\nvardot|Vardot\nnot a pair\n",
      ],
    ])->jsSettingsAlter($settings, NULL);

    $delivered = $settings[SpeechHooks::SETTINGS_KEY];
    $this->assertTrue($delivered['speechToText']);
    $this->assertSame('outside_end', $delivered['position']);
    // The language travels unresolved: only the browser knows which page it is
    // on, and the Canvas editor serves one built page for every language.
    $this->assertSame('site', $delivered['speechToTextLanguage']);

    $payload = $delivered['speechToTextConfig'];
    $this->assertFalse($payload['displayInterimResults']);
    $this->assertFalse($payload['stopAfterSubmit']);
    $this->assertSame(3500, $payload['submitAfterSilence']);
    // Only the colour that was set, so the other one keeps the chat theme's.
    $this->assertSame(['interim' => 'gray'], $payload['textColor']);
    $this->assertSame([
      'stop' => 'stop listening',
      'submit' => 'send it',
      'settings' => ['substrings' => FALSE, 'caseSensitive' => TRUE],
    ], $payload['commands']);
    // A line without a separator is not a correction, and is skipped.
    $this->assertSame(['varbase' => 'Varbase', 'vardot' => 'Vardot'], $payload['translations']);
  }

  /**
   * Asking to send after a pause without a length uses deep-chat's own.
   *
   * @covers ::jsSettingsAlter
   */
  public function testSubmitAfterSilenceWithoutLengthIsTrue(): void {
    $settings = [];
    $this->buildHooks([
      'speech_to_text' => [
        'enabled' => TRUE,
        'submit_after_silence' => TRUE,
        'submit_after_silence_ms' => 0,
      ],
    ])->jsSettingsAlter($settings, NULL);
    $this->assertTrue($settings[SpeechHooks::SETTINGS_KEY]['speechToTextConfig']['submitAfterSilence']);
  }

  /**
   * Sending after a pause is on unless the site turns it off.
   *
   * @covers ::jsSettingsAlter
   */
  public function testSendingAfterPauseIsTheDefault(): void {
    $settings = [];
    $this->buildHooks(['speech_to_text' => ['enabled' => TRUE]])
      ->jsSettingsAlter($settings, NULL);
    $payload = $settings[SpeechHooks::SETTINGS_KEY]['speechToTextConfig'];
    $this->assertTrue($payload['submitAfterSilence']);

    $off = [];
    $this->buildHooks([
      'speech_to_text' => ['enabled' => TRUE, 'submit_after_silence' => FALSE],
    ])->jsSettingsAlter($off, NULL);
    $this->assertArrayNotHasKey(
      'submitAfterSilence',
      $off[SpeechHooks::SETTINGS_KEY]['speechToTextConfig'],
    );
  }

  /**
   * Commands are left out entirely when no phrase is configured.
   *
   * The commands object is read by deep-chat as "listen for these", so empty
   * strings would have it listening for nothing at all.
   *
   * @covers ::jsSettingsAlter
   */
  public function testEmptyCommandsAreNotSent(): void {
    $settings = [];
    $this->buildHooks([
      'speech_to_text' => [
        'enabled' => TRUE,
        'commands' => ['stop' => '', 'submit' => '  ', 'substrings' => TRUE],
      ],
    ])->jsSettingsAlter($settings, NULL);
    $this->assertArrayNotHasKey('commands', $settings[SpeechHooks::SETTINGS_KEY]['speechToTextConfig']);
  }

  /**
   * An unknown placement falls back rather than reaching the browser.
   *
   * @covers ::jsSettingsAlter
   */
  public function testUnknownPositionFallsBack(): void {
    $settings = [];
    $this->buildHooks([
      'speech_to_text' => ['enabled' => TRUE, 'position' => 'somewhere_else'],
    ])->jsSettingsAlter($settings, NULL);
    $this->assertSame(SpeechHooks::DEFAULT_POSITION, $settings[SpeechHooks::SETTINGS_KEY]['position']);
  }

  /**
   * A block's own entry survives the site-wide pass over the settings.
   *
   * The hook_js_settings_alter pass runs after the block's attached settings
   * have been merged in, so an assistant that switched the microphone off
   * would be switched back on by the site setting if the pass overwrote it.
   *
   * @covers ::jsSettingsAlter
   */
  public function testAssistantEntryIsNotOverwritten(): void {
    $settings = [
      SpeechHooks::SETTINGS_KEY => ['speechToText' => FALSE],
    ];
    $this->buildHooks([
      'speech_to_text' => ['enabled' => TRUE, 'position' => 'input_start'],
    ])->jsSettingsAlter($settings, NULL);

    $this->assertFalse($settings[SpeechHooks::SETTINGS_KEY]['speechToText']);
    // The keys it did not override are still filled in from the site setting.
    $this->assertSame('input_start', $settings[SpeechHooks::SETTINGS_KEY]['position']);
  }

  /**
   * An assistant with no opinion attaches nothing of its own.
   *
   * @covers ::blockViewAlter
   */
  public function testAssistantWithoutOverrideAttachesNothing(): void {
    $build = [];
    $this->buildHooks(['speech_to_text' => ['enabled' => TRUE]], TRUE, TRUE, [])
      ->blockViewAlter($build, $this->mockBlock('some_assistant'));

    $this->assertArrayNotHasKey('library', $build['#attached'] ?? []);
    // The settings cache tag is still added, so switching a site setting
    // invalidates the rendered block.
    $this->assertContains('config:ai_agent_modes.settings', $build['#cache']['tags']);
  }

  /**
   * An assistant's own choices reach its own block.
   *
   * @covers ::blockViewAlter
   */
  public function testAssistantOverridesReachTheBlock(): void {
    $build = [];
    $this->buildHooks(
      ['speech_to_text' => ['enabled' => TRUE, 'position' => 'input_end']],
      TRUE,
      TRUE,
      [
        SpeechHooks::SPEECH_TO_TEXT_KEY => 'off',
        SpeechHooks::TEXT_TO_SPEECH_KEY => 'on',
        SpeechHooks::POSITION_KEY => 'outside_start',
      ],
    )->blockViewAlter($build, $this->mockBlock('some_assistant'));

    $delivered = $build['#attached']['drupalSettings'][SpeechHooks::SETTINGS_KEY];
    $this->assertContains(SpeechHooks::LIBRARY, $build['#attached']['library']);
    $this->assertFalse($delivered['speechToText']);
    $this->assertTrue($delivered['textToSpeech']);
    $this->assertSame('outside_start', $delivered['position']);
    // The assistant's own tags are added, so editing the assistant is enough
    // to change what its block renders.
    $this->assertContains('config:ai_assistant_api.ai_assistant.stub', $build['#cache']['tags']);
  }

  /**
   * A block with no assistant, and a site without the API, are both left alone.
   *
   * @covers ::blockViewAlter
   */
  public function testBlockWithoutAssistantIsLeftAlone(): void {
    $build = [];
    $this->buildHooks(['speech_to_text' => ['enabled' => TRUE]], TRUE, TRUE, [])
      ->blockViewAlter($build, $this->mockBlock(''));
    $this->assertArrayNotHasKey('#attached', $build);

    $without_api = [];
    $this->buildHooks(
      ['speech_to_text' => ['enabled' => TRUE]],
      TRUE,
      FALSE,
      [SpeechHooks::SPEECH_TO_TEXT_KEY => 'on'],
    )->blockViewAlter($without_api, $this->mockBlock('some_assistant'));
    $this->assertArrayNotHasKey('#attached', $without_api);
  }

  /**
   * Mocks a DeepChat block configured for one assistant.
   *
   * @param string $assistant_id
   *   The assistant ID, empty for a block with none.
   *
   * @return \Drupal\Core\Block\BlockPluginInterface
   *   The mocked block.
   */
  protected function mockBlock(string $assistant_id): BlockPluginInterface {
    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('getConfiguration')->willReturn(['ai_assistant' => $assistant_id]);
    return $block;
  }

}
