@ai-agent-modes @admin @canvas
Feature: Which saved modes the dropdown offers for an agent
  As a page builder opening the assistant on one agent
  I want the dropdown to offer the modes that steer that agent, including the
  ones that steer through their prompt alone and the ones that are not tied to
  any agent
  So that every mode a site administrator saved is actually reachable from the
  chat

  # Two shapes of mode are easy to lose on the way from the admin UI to the
  # chat, and both are asserted here through the endpoint every chat surface
  # builds its dropdown from (/ai-agent-modes/options/<agent>):
  #
  # 1. A prompt-only mode: no sub-agents, but a system prompt addition. It is
  #    still restrictive (ScopePayload::isRestrictive()) and still steers the
  #    orchestrator, which is the shape of the shipped Varbase modes that point
  #    the assistant at the orchestrator's own tools rather than at a sub-agent.
  # 2. A generic mode: saved with no parent agent, so ModeManager::listModes()
  #    offers it for every agent. This scenario proves that by reading the
  #    options of two different agents.
  #
  # Both modes are created through the add form and deleted again inside their
  # scenario, so the site is left exactly as it was found. The generic scenario
  # reads Test Child One, an agent with no modes of its own: while the generic
  # mode exists it has something to offer, and once the mode is deleted its
  # payload is back to the lone "All" entry, which is the point at which both
  # client-side surfaces stop rendering a dropdown at all.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: A mode that steers by its prompt alone is offered for its agent
    When I navigate to "/admin/config/ai/agent-modes/add"
     And I fill in "Label" with "Metadata polish mode"
     And I select "Test Orchestrator" from "Parent agent"
     And I fill in "System prompt addition" with "Only polish the page metadata."
     And I save the form
    Then I should see "The AI agent mode Metadata polish mode has been saved."
     And I should see "Prompt only" in the "Metadata polish mode" row
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the JSON response should contain "Metadata polish mode"
     And the JSON response should contain "Focus on Child One"
     And I the page should not have PHP errors
    When I navigate to "/admin/config/ai/agent-modes"
     And I open the "Delete" operation in the "Metadata polish mode" row
     And I press "Delete"
    Then I should see "The AI agent mode Metadata polish mode has been deleted."

  Scenario: A mode with no parent agent is offered for every agent
    When I navigate to "/admin/config/ai/agent-modes/add"
     And I fill in "Label" with "Plain language mode"
     And I select "- Any (generic) -" from "Parent agent"
     And I fill in "System prompt addition" with "Answer in plain language."
     And I save the form
    Then I should see "The AI agent mode Plain language mode has been saved."
     And I should see "Any (generic)" in the "Plain language mode" row
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the JSON response should contain "Plain language mode"
     And the JSON response should contain "Focus on Child One"
    When I navigate to "/ai-agent-modes/options/test_child_one"
    Then the JSON response should contain "Plain language mode"
     And the JSON response should not contain "Focus on Child One"
     And the options endpoint should offer more than 1 option
     And I the page should not have PHP errors
    When I navigate to "/admin/config/ai/agent-modes"
     And I open the "Delete" operation in the "Plain language mode" row
     And I press "Delete"
    Then I should see "The AI agent mode Plain language mode has been deleted."
    When I navigate to "/ai-agent-modes/options/test_child_one"
    Then the JSON response should not contain "Plain language mode"
     And the options endpoint should offer 1 option
