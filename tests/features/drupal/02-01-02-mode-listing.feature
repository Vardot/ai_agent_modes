@ai-agent-modes @admin
Feature: What the mode listing tells a site administrator about each mode
  As a site administrator looking at the AI Agent Modes listing
  I want each row to say which agent the mode scopes and how it steers that
  agent
  So that I can tell a curated mode, a prompt-only mode and an inert mode apart
  without opening every one of them

  # The listing (AiAgentModeListBuilder) answers three questions per row: which
  # parent agent the mode scopes ("Any (generic)" when it is not tied to one),
  # how it steers (the sub-agent IDs, "Prompt only" when it steers through its
  # prompt directive alone, "None" when it does neither), and whether it is
  # enabled.
  #
  # "Prompt only" is not cosmetic: a mode with no sub-agents but with a system
  # prompt addition is still restrictive (ScopePayload::isRestrictive()) and
  # still steers the orchestrator, which is exactly the shape of the shipped
  # Varbase modes that point the assistant at the orchestrator's own tools
  # rather than at a sub-agent. A mode with neither is inert: ModeManager
  # resolves it to no scope at all, and the row says "None".
  #
  # Each scenario that needs a mode of a given shape creates it through the add
  # form and deletes it again, so the site is left exactly as it was found and
  # the scenarios can run in any order.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: A curated mode names its parent agent and the sub-agents it exposes
    When I navigate to "/admin/config/ai/agent-modes"
    Then I should see "test_orchestrator" in the "Focus on Child One" row
     And I should see "test_child_one" in the "Focus on Child One" row
     And I should see "Yes" in the "Focus on Child One" row
     And I the page should not have PHP errors

  Scenario: A mode that steers by its prompt alone is listed as prompt only
    When I navigate to "/admin/config/ai/agent-modes/add"
     And I fill in "Label" with "Prompt only mode"
     And I select "Test Orchestrator" from "Parent agent"
    Then I should see "Test Child One (test_child_one)"
    When I fill in "System prompt addition" with "Use the orchestrator's own tools for this request."
     And I save the form
    Then I should see "The AI agent mode Prompt only mode has been saved."
     And I should see "Prompt only" in the "Prompt only mode" row
     And I should not see "None" in the "Prompt only mode" row
     And I the page should not have PHP errors
    When I open the "Delete" operation in the "Prompt only mode" row
    Then I should see "Are you sure you want to delete the AI agent mode Prompt only mode?"
    When I press "Delete"
    Then I should see "The AI agent mode Prompt only mode has been deleted."
    When I navigate to "/admin/config/ai/agent-modes"
    Then I should not see "Prompt only mode"

  Scenario: A mode with no sub-agents and no prompt is listed as none
    When I navigate to "/admin/config/ai/agent-modes/add"
     And I fill in "Label" with "Inert mode"
     And I select "Test Orchestrator" from "Parent agent"
     And I save the form
    Then I should see "The AI agent mode Inert mode has been saved."
     And I should see "None" in the "Inert mode" row
     And I should not see "Prompt only" in the "Inert mode" row
     And I the page should not have PHP errors
    When I open the "Delete" operation in the "Inert mode" row
    Then I should see "Are you sure you want to delete the AI agent mode Inert mode?"
    When I press "Delete"
    Then I should see "The AI agent mode Inert mode has been deleted."
    When I navigate to "/admin/config/ai/agent-modes"
    Then I should not see "Inert mode"

  Scenario: A mode saved without a parent agent is listed as generic
    When I navigate to "/admin/config/ai/agent-modes/add"
     And I fill in "Label" with "Any agent mode"
     And I select "- Any (generic) -" from "Parent agent"
     And I fill in "System prompt addition" with "Keep every answer short."
     And I save the form
    Then I should see "The AI agent mode Any agent mode has been saved."
     And I should see "Any (generic)" in the "Any agent mode" row
     And I should see "Prompt only" in the "Any agent mode" row
     And I the page should not have PHP errors
    When I open the "Delete" operation in the "Any agent mode" row
    Then I should see "Are you sure you want to delete the AI agent mode Any agent mode?"
    When I press "Delete"
    Then I should see "The AI agent mode Any agent mode has been deleted."
    When I navigate to "/admin/config/ai/agent-modes"
    Then I should not see "Any agent mode"
