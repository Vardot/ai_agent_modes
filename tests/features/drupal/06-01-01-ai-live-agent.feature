@ai-agent-modes @admin @ai
Feature: AI Agent Modes with a live chat provider configured
  As a site owner running the AI stack with a real provider
  I want the mode selector surfaces to keep working when a live chat provider is
  configured
  So that steering a real assistant conversation behaves the same as the
  provider-free acceptance run

  # This suite runs only on the amazee.ai leg, which provisions a keyless free
  # trial (tests/recipes/ai_agent_modes_amazee, ensureAmazeeAiAccess) and makes
  # amazee.ai the default chat provider. No API key/secret is required, so it
  # runs on every pipeline including issue-fork ones.
  #
  # Rendering the dropdown never needs a provider (it is a JSON options endpoint
  # plus a Form API render element); this leg proves the same surfaces still
  # render once a real provider backs the assistant, and is the place to add
  # scenarios that send an actual prompt.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The AI configuration group loads with a live provider configured
    When I navigate to "/admin/config/ai"
    Then the "drupal page heading" element should be visible
     And I the page should not have PHP errors

  Scenario: The chatbot mode selector renders with a live provider backing the assistant
    When I navigate to "/admin/content"
    Then the mode selector should offer the option "Free-form (all sub-agents)"
     And the mode selector should offer the option "Focus on Child One"
     And I the page should not have PHP errors
