<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_agent_modes\Hook\AssistantSettingsHooks;
use Drupal\ai_agent_modes\Hook\SpeechHooks;

/**
 * Tests the per-assistant overrides written onto the AI Assistant entity.
 *
 * The overrides live as third-party settings on another module's configuration
 * entity, which is the supported way to add a setting there, so what matters is
 * that each one survives a save and that choosing "use the site setting" leaves
 * nothing behind: an assistant that overrode nothing must export clean.
 *
 * The entity builder is called directly rather than through the AI Assistant
 * form, because that form refuses to save unless the site has a working AI
 * provider and model, which has nothing to do with what is being tested here.
 *
 * @group ai_agent_modes
 * @coversDefaultClass \Drupal\ai_agent_modes\Hook\AssistantSettingsHooks
 */
class AssistantOverridesTest extends KernelTestBase {

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
    'ai_assistant_api',
    'ai_agent_modes',
  ];

  /**
   * The assistant the overrides are written onto.
   */
  protected object $assistant;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_assistant');
    $this->assistant = $storage->create([
      'id' => 'override_test_assistant',
      'label' => 'Override test assistant',
      // Every typed property the entity declares without a default has to be
      // given a value, or the entity cannot be loaded back.
      'description' => 'Used to test the per-assistant overrides.',
      'system_role' => 'You are a helpful assistant.',
      'preprompt_instructions' => '',
      'assistant_message' => '',
      'error_message' => 'Something went wrong.',
      'history_context_length' => '2',
      'allow_history' => 'session_one_thread',
      'llm_provider' => '',
      'llm_model' => '',
      'llm_configuration' => [],
      'actions_enabled' => [],
      'specific_error_messages' => [],
      'system_prompt' => '',
      'pre_action_prompt' => '',
      'roles' => [],
    ]);
    $this->assistant->save();
  }

  /**
   * Each override is stored, and survives being loaded again.
   *
   * @covers ::entityBuilder
   */
  public function testOverridesAreStored(): void {
    $this->build([
      'ai_agent_modes_chatbot_position' => 'below_input',
      'ai_agent_modes_microphone' => 'on',
      'ai_agent_modes_microphone_position' => 'outside_end',
      'ai_agent_modes_text_to_speech' => 'off',
    ]);

    $this->assertSame([
      AssistantSettingsHooks::POSITION_KEY => 'below_input',
      SpeechHooks::SPEECH_TO_TEXT_KEY => 'on',
      SpeechHooks::POSITION_KEY => 'outside_end',
      SpeechHooks::TEXT_TO_SPEECH_KEY => 'off',
    ], $this->reload()->getThirdPartySettings('ai_agent_modes'));
  }

  /**
   * Choosing the site setting again removes the override entirely.
   *
   * @covers ::entityBuilder
   */
  public function testSiteSettingStoresNothing(): void {
    $this->build([
      'ai_agent_modes_chatbot_position' => 'header',
      'ai_agent_modes_microphone' => 'on',
      'ai_agent_modes_microphone_position' => 'input_start',
      'ai_agent_modes_text_to_speech' => 'on',
    ]);
    $this->assertNotSame([], $this->reload()->getThirdPartySettings('ai_agent_modes'));

    $this->build([
      'ai_agent_modes_chatbot_position' => '',
      'ai_agent_modes_microphone' => '',
      'ai_agent_modes_microphone_position' => '',
      'ai_agent_modes_text_to_speech' => '',
    ]);
    $this->assertSame([], $this->reload()->getThirdPartySettings('ai_agent_modes'));
  }

  /**
   * A value that is not on offer is not stored.
   *
   * @covers ::entityBuilder
   */
  public function testUnknownValuesAreRefused(): void {
    $this->build([
      'ai_agent_modes_chatbot_position' => 'somewhere_else',
      'ai_agent_modes_microphone' => 'maybe',
      'ai_agent_modes_microphone_position' => 'under_the_desk',
      'ai_agent_modes_text_to_speech' => 'sometimes',
    ]);
    $this->assertSame([], $this->reload()->getThirdPartySettings('ai_agent_modes'));
  }

  /**
   * Runs the entity builder over the assistant with the given values.
   *
   * @param array $values
   *   The submitted values.
   */
  protected function build(array $values): void {
    $form_state = new FormState();
    $form_state->setValues($values);
    $form = [];
    AssistantSettingsHooks::entityBuilder('ai_assistant', $this->assistant, $form, $form_state);
    $this->assistant->save();
  }

  /**
   * Loads the assistant fresh from storage.
   *
   * @return object
   *   The reloaded assistant.
   */
  protected function reload(): object {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_assistant');
    $this->assistant = $storage->loadUnchanged('override_test_assistant');
    return $this->assistant;
  }

}
