@ai-agent-modes @admin @chatbot
Feature: The AI Assistant chatbot mode selector dropdown
  As a site builder placing an AI assistant chat on Drupal CMS
  I want the AI Agent Mode selector to render a "Mode" dropdown built from the
  assistant's parent agent and its live sub-agents
  So that a user can scope the assistant before sending a message

  # Both the drupal_cms_ai / AI Assistant chatbot integrations (ChatbotHooks for
  # the deep-chat block, ChatFormHooks for the classic chat form) resolve the
  # parent agent FROM the AI Assistant and render the same `ai_agent_mode_select`
  # element. The placeable "AI Agent Mode selector" block uses the identical
  # resolveAgent() path, so reading its rendered <select> proves that dropdown
  # renders on Drupal CMS - without the third-party deep-chat web component's
  # shadow DOM, and without a live LLM provider (the dropdown is a Form API
  # render element; the provider is only used to answer a prompt).
  #
  # The fixtures (tests/recipes/ai_agent_modes_test) place a selector block in
  # the Gin admin theme's content region, reading its parent agent from the
  # seeded "Test Assistant" (backed by "Test Orchestrator", sub-agents Test
  # Child One / Test Child Two, plus one saved mode). The Gin content region
  # reliably renders placed blocks on /admin/content.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The selector renders the assistant's parent agent, its modes and its live sub-agents
    When I navigate to "/admin/content"
    Then the mode selector should offer the option "Free-form (all sub-agents)"
     And the mode selector should offer the option "Focus on Child One"
     And the mode selector should offer the option "Test Child One"
     And the mode selector should offer the option "Test Child Two"
     And I the page should not have PHP errors
