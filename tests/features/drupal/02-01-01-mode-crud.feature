@ai-agent-modes @admin
Feature: Creating an AI agent mode through the admin UI
  As a site administrator
  I want to create a mode that scopes an orchestrator to a curated subset of its
  live sub-agents
  So that I can steer the assistant without touching the agent configuration

  # A mode is an ai_agent_mode config entity: a saved subset of one agent's
  # sub-agents. The add form reads the parent agent's LIVE sub-agents (through
  # the AI Agents plugin manager) and offers them as checkboxes. The test
  # fixtures (tests/recipes/ai_agent_modes_test) seed a "Test Orchestrator"
  # agent that exposes "Test Child One" and "Test Child Two", plus one saved
  # mode "Focus on Child One", so the checkboxes and the collection are
  # deterministic and need no live LLM provider.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The collection lists the seeded mode and offers an add action
    When I navigate to "/admin/config/ai/agent-modes"
    Then I should see "Focus on Child One"
     And I should see "Add AI agent mode"
     And I the page should not have PHP errors

  Scenario: An administrator creates a mode by picking a live sub-agent
    When I navigate to "/admin/config/ai/agent-modes/add"
    Then I should see "Sub-agents in this mode"
    When I fill in "Label" with "Focus on Child Two"
     And I select "Test Orchestrator" from "Parent agent"
    Then I should see "Test Child Two (test_child_two)"
    When I check "Test Child Two (test_child_two)"
     And I fill in "System prompt addition" with "Only use Test Child Two."
     And I save the form
    Then I should see "The AI agent mode Focus on Child Two has been saved."
     And I should see "Focus on Child Two"
     And I should see "test_orchestrator"
     And I should see "test_child_two"
     And I the page should not have PHP errors
    # Delete what this scenario created, so the suite can run twice against the
    # same site. Without this the second run fails on a duplicate machine name,
    # which only shows up locally: CI builds a fresh site every time.
    When I navigate to "/admin/config/ai/agent-modes"
     And I open the "Delete" operation in the "Focus on Child Two" row
     And I press "Delete"
    Then I should see "The AI agent mode Focus on Child Two has been deleted."

