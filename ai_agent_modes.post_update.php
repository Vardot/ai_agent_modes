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
