<?php

/**
 * @file
 * Post update functions for AI Agent Modes.
 */

declare(strict_types=1);

/**
 * Adds the scope strength field and the tool-scope enforcement switch.
 */
function ai_agent_modes_post_update_add_scope_strength(): void {
  // config/install is only read when a module is installed, so an existing site
  // would otherwise read NULL for the new switch. NULL is treated as enabled at
  // run time, but writing it makes the setting visible and editable.
  $settings = \Drupal::configFactory()->getEditable('ai_agent_modes.settings');
  if ($settings->get('tool_scope_enforcement') === NULL) {
    $settings->set('tool_scope_enforcement', TRUE)->save();
  }

  // Re-save every mode so the explicit default lands in exported config rather
  // than being implied by the entity class.
  $storage = \Drupal::entityTypeManager()->getStorage('ai_agent_mode');
  foreach ($storage->loadMultiple() as $mode) {
    $mode->save();
  }
}

/**
 * Adds the AI Chatbot dropdown position setting.
 */
function ai_agent_modes_post_update_add_chatbot_position(): void {
  $settings = \Drupal::configFactory()->getEditable('ai_agent_modes.settings');
  if ($settings->get('chatbot_position') === NULL) {
    // The documented behaviour before this setting existed.
    $settings->set('chatbot_position', 'above_chat')->save();
  }
}

/**
 * Adds the speech settings, both directions switched off.
 */
function ai_agent_modes_post_update_add_speech(): void {
  $settings = \Drupal::configFactory()->getEditable('ai_agent_modes.settings');
  // Off on an existing site: a chat that neither listened nor spoke yesterday
  // should not start doing either because the module was updated.
  if ($settings->get('speech_to_text') === NULL) {
    $settings->set('speech_to_text', [
      'enabled' => FALSE,
      'position' => 'input_start',
      'language' => 'browser',
      'display_interim_results' => TRUE,
      'stop_after_submit' => TRUE,
      'submit_after_silence' => TRUE,
      'submit_after_silence_ms' => 4000,
      'interim_color' => '',
      'final_color' => '',
      'commands' => [
        'stop' => '',
        'pause' => '',
        'resume' => '',
        'remove_all_text' => '',
        'submit' => '',
        'command_mode' => '',
        'substrings' => TRUE,
        'case_sensitive' => FALSE,
      ],
      'translations' => '',
    ]);
  }
  if ($settings->get('text_to_speech') === NULL) {
    $settings->set('text_to_speech', [
      'enabled' => FALSE,
      'language' => 'browser',
      'voice_name' => '',
      'pitch' => 1.0,
      'rate' => 1.0,
      'volume' => 1.0,
    ]);
  }
  $settings->save();
}

/**
 * Adds the switch that offers the mode dropdown, left on.
 */
function ai_agent_modes_post_update_add_show_dropdown(): void {
  $settings = \Drupal::configFactory()->getEditable('ai_agent_modes.settings');
  if ($settings->get('show_dropdown') === NULL) {
    // On, because a site that had the dropdown yesterday should keep it.
    $settings->set('show_dropdown', TRUE)->save();
  }
}
