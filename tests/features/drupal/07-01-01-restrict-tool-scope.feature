@ai-agent-modes @admin
Feature: Choosing how strongly a mode scopes the assistant
  As a site administrator
  I want to say whether a mode only steers the assistant or also withholds the
  sub-agent tools it does not name
  So that I can shrink what the model has to weigh up when steering alone is not
  enough

  # A mode has always steered by adding a line to the system prompt, leaving every
  # tool in place. "Steer and withhold" additionally hides the sub-agent tools the
  # mode does not name, so they are never sent to the model at all. Steering stays
  # the default, so an existing site behaves exactly as before.
  #
  # Withholding only makes sense for a mode that names a parent agent and at least
  # one sub-agent, because sub-agent IDs mean nothing without an agent, and hiding
  # every sub-agent would leave the assistant with none. Both rules are enforced
  # on the form, and both are asserted here.
  #
  # What cannot be asserted through a browser is the withheld tool list itself:
  # it exists only inside the request the site makes to the model. That is covered
  # by the kernel tests, which read the tool set the agent would send.
  #
  # The fixtures (tests/recipes/ai_agent_modes_test) seed "Focus on Child One"
  # (steer only) and "Only Child One, withhold the rest" (steer and withhold), so
  # both values of the Scope column are on the listing from the start.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The listing shows how strongly each mode scopes
    When I navigate to "/admin/config/ai/agent-modes"
    Then I should see "Scope"
     And I should see "Steer only" in the "Focus on Child One" row
     And I should see "Steer and withhold" in the "Only Child One, withhold the rest" row
     And I the page should not have PHP errors

  Scenario: Withholding is refused for a mode with no parent agent
    When I navigate to "/admin/config/ai/agent-modes/add"
    Then I should see "Scope strength"
    When I fill in "Label" with "Withhold without an agent"
     And I select "- Any (generic) -" from "Parent agent"
    # A generic mode offers every agent's sub-agents, and ticking one is what
    # reveals the scope strength radios.
    When I check "Test Child One (test_child_one)"
     And I select radio button "restrict"
     And I save the form
    Then I should see "A mode that withholds tools must name its parent agent"
     And I the page should not have PHP errors

  Scenario: An administrator turns a mode into a withholding one
    When I navigate to "/admin/config/ai/agent-modes/add"
     And I fill in "Label" with "Child Two only, withheld"
     And I select "Test Orchestrator" from "Parent agent"
    Then I should see "Test Child Two (test_child_two)"
    When I check "Test Child Two (test_child_two)"
     And I select radio button "restrict"
     And I save the form
    Then I should see "The AI agent mode Child Two only, withheld has been saved."
     And I should see "Steer and withhold" in the "Child Two only, withheld" row
     And I the page should not have PHP errors
    When I navigate to "/admin/config/ai/agent-modes"
     And I open the "Delete" operation in the "Child Two only, withheld" row
     And I press "Delete"
    Then I should see "The AI agent mode Child Two only, withheld has been deleted."

  Scenario: The site-wide switch can turn every withholding mode back into steering
    When I navigate to "/admin/config/ai/agent-modes/settings"
    Then I should see "Enforce tool scope"
     And I the page should not have PHP errors
