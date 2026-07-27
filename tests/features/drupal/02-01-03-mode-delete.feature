@ai-agent-modes @admin
Feature: Deleting an AI agent mode
  As a site administrator who no longer wants a mode offered
  I want deleting it to withdraw it from the listing and from the dropdown the
  assistant offers
  So that nobody can pick a mode that is no longer supposed to exist

  # Deleting an ai_agent_mode config entity has to take effect in two places:
  # the admin listing, and the data source every chat surface builds its
  # dropdown from (/ai-agent-modes/options/<agent>, read by js/canvas-ai.js and
  # js/chatbot-deepchat.js). Both sides are asserted before and after the
  # delete, so a stale cache on either side fails this scenario instead of
  # quietly leaving a dead mode on offer.
  #
  # The mode is created through the add form inside the scenario, so the site is
  # left exactly as it was found.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: Deleting a mode withdraws it from the listing and from the dropdown options
    When I navigate to "/admin/config/ai/agent-modes/add"
     And I fill in "Label" with "Retired mode"
     And I select "Test Orchestrator" from "Parent agent"
    Then I should see "Test Child Two (test_child_two)"
    When I check "Test Child Two (test_child_two)"
     And I save the form
    Then I should see "The AI agent mode Retired mode has been saved."
     And I should see "test_child_two" in the "Retired mode" row
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the JSON response should contain "Retired mode"
     And the JSON response should contain "Focus on Child One"
    When I navigate to "/admin/config/ai/agent-modes"
     And I open the "Delete" operation in the "Retired mode" row
    Then I should see "Are you sure you want to delete the AI agent mode Retired mode?"
    When I press "Delete"
    Then I should see "The AI agent mode Retired mode has been deleted."
    When I navigate to "/admin/config/ai/agent-modes"
    Then I should not see "Retired mode"
     And I should see "Focus on Child One"
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the JSON response should not contain "Retired mode"
     And the JSON response should contain "Focus on Child One"
     And I the page should not have PHP errors
