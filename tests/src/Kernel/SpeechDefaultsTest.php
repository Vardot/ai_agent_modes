<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that an updated site gets the same speech defaults as a new one.
 *
 * The post-update hook writes the speech settings on a site that predates them,
 * so the same defaults live in two places: the shipped
 * ai_agent_modes.settings and the hook. They drifted once already, leaving a
 * new install waiting four seconds before sending a dictated message and an
 * updated site waiting three. The hook is run here against a site whose speech
 * settings have been taken away, and what it writes is compared with what a
 * fresh install ships.
 *
 * @group ai_agent_modes
 */
class SpeechDefaultsTest extends KernelTestBase {

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
   * The keys the post-update hook and the shipped config must agree on.
   */
  protected const SPEECH_KEYS = [
    'enabled',
    'position',
    'language',
    'display_interim_results',
    'stop_after_submit',
    'submit_after_silence',
    'submit_after_silence_ms',
    'interim_color',
    'final_color',
  ];

  /**
   * Runs one post-update hook on a site missing the settings key it seeds.
   *
   * @param string $function
   *   The post-update function name.
   * @param string $key
   *   The settings key it seeds, cleared first so the hook does its work.
   *
   * @return array
   *   What the hook wrote.
   */
  protected function runPostUpdate(string $function, string $key): array {
    $module_handler = $this->container->get('module_handler');
    $path = $module_handler->getModule('ai_agent_modes')->getPath();
    require_once $this->root . '/' . $path . '/ai_agent_modes.post_update.php';
    $this->assertTrue(function_exists($function), "$function is defined.");

    // An existing site from before the setting existed.
    $this->config('ai_agent_modes.settings')->clear($key)->save();
    $this->assertNull($this->config('ai_agent_modes.settings')->get($key));

    $function();

    $written = $this->config('ai_agent_modes.settings')->get($key);
    $this->assertIsArray($written, "$function wrote $key.");
    return $written;
  }

  /**
   * The speech-to-text defaults match the shipped configuration.
   */
  public function testSpeechToTextDefaultsMatchShippedConfig(): void {
    $this->installConfig(['ai_agent_modes']);
    $shipped = $this->config('ai_agent_modes.settings')->get('speech_to_text');
    $seeded = $this->runPostUpdate(
      'ai_agent_modes_post_update_add_speech',
      'speech_to_text',
    );

    foreach (self::SPEECH_KEYS as $key) {
      $this->assertArrayHasKey($key, $shipped, "Shipped config has $key.");
      $this->assertArrayHasKey($key, $seeded, "The post-update seeds $key.");
      $this->assertSame(
        $shipped[$key],
        $seeded[$key],
        sprintf(
          'speech_to_text.%s is the same on a new install and an updated site.',
          $key,
        ),
      );
    }
  }

  /**
   * The pause before sending is the same four seconds either way.
   *
   * Named on its own because this is the value that drifted.
   */
  public function testPauseBeforeSendingIsFourSecondsEitherWay(): void {
    $this->installConfig(['ai_agent_modes']);
    $shipped = $this->config('ai_agent_modes.settings')->get('speech_to_text');
    $this->assertSame(4000, $shipped['submit_after_silence_ms']);

    $seeded = $this->runPostUpdate(
      'ai_agent_modes_post_update_add_speech',
      'speech_to_text',
    );
    $this->assertSame(4000, $seeded['submit_after_silence_ms']);
  }

  /**
   * Both halves ship switched off, so an update never starts recording.
   */
  public function testBothHalvesShipOff(): void {
    $this->installConfig(['ai_agent_modes']);
    $settings = $this->config('ai_agent_modes.settings');
    $this->assertFalse($settings->get('speech_to_text.enabled'));
    $this->assertFalse($settings->get('text_to_speech.enabled'));

    $seeded = $this->runPostUpdate(
      'ai_agent_modes_post_update_add_speech',
      'speech_to_text',
    );
    $this->assertFalse($seeded['enabled']);
  }

}
