@ai-agent-modes @admin @canvas
Feature: The Canvas AI chat panel dropdown data source
  As a page builder using the Drupal Canvas AI panel
  I want the mode dropdown that AI Agent Modes injects into the Canvas AI chat
  to be populated from a live server endpoint
  So that I can scope the orchestrator to a saved mode from inside the panel

  # The Canvas AI panel renders its chat as a deep-chat web component inside a
  # React island, so AI Agent Modes injects the dropdown client-side: js/canvas-ai.js
  # fetches GET /ai-agent-modes/options/<agent> and builds the <select> from the
  # returned JSON, injecting only when the payload offers more than the single
  # "All" option. No live LLM provider is needed to serve or render this data;
  # the provider is only used to actually answer a prompt (see the @ai suite).
  #
  # This scenario asserts that JSON contract for the seeded "Test Orchestrator"
  # (which has one saved mode). Driving the injected dropdown inside the Canvas
  # editor's shadow DOM is covered by the @canvas-editor suite, kept out of CI.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The options endpoint feeds the Canvas AI dropdown with the saved mode
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the JSON response should contain "let the assistant decide"
     And the JSON response should contain "Focus on Child One"
     And the JSON response should contain "mode:test_focus_one"
     And the JSON response should contain "toolbar"
     And I the page should not have PHP errors
