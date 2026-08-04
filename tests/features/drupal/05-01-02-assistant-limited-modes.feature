@ai-agent-modes @admin @chatbot
Feature: Limiting a mode to selected AI Assistants
  As a site administrator running more than one AI Assistant on the same parent
  agent
  I want to offer a mode for the assistants I choose
  So that each assistant offers the modes that belong to its job and nothing
  else

  # A mode carries an "assistants" list: the AI Assistants it is offered for.
  # An empty list, the default, means every assistant, which is why the seeded
  # "Focus on Child One" mode keeps showing up everywhere in this feature. Once
  # a mode names assistants it belongs to them: it is offered for the assistant
  # named in the options endpoint's `assistant` parameter, withheld from every
  # other assistant, and withheld from a surface that has no assistant at all,
  # which is the Drupal Canvas AI panel (driven by an agent, not an assistant).
  #
  # The fixtures (tests/recipes/ai_agent_modes_test) seed the "Test Assistant"
  # (ID test_assistant, backed by "Test Orchestrator"). They place no block, so
  # this is asserted through the JSON options endpoint every client-rendered chat
  # reads. The mode is created and deleted inside the scenario, so the site is
  # left as it was found.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: A mode limited to one assistant is offered for that assistant only
    When I navigate to "/admin/config/ai/agent-modes/add"
    Then I should see "AI Assistants"
    When I fill in "Label" with "Test assistant only mode"
     And I select "Test Orchestrator" from "Parent agent"
     And I check "Test Assistant"
     And I fill in "System prompt addition" with "Only answer as the test assistant."
     And I save the form
    Then I should see "The AI agent mode Test assistant only mode has been saved."
     And I should see "test_assistant" in the "Test assistant only mode" row
     And I should see "Any assistant" in the "Focus on Child One" row

    # Named assistant: the mode is offered, alongside the unrestricted one.
    # The counts are deliberately not asserted here: a site can carry any number
    # of unrestricted modes, so what matters is which of the two is present.
    When I navigate to "/ai-agent-modes/options/test_orchestrator?assistant=test_assistant"
    Then the JSON response should contain "Test assistant only mode"
     And the JSON response should contain "Focus on Child One"
     And the options endpoint should offer more than 1 option
     And I the page should not have PHP errors

    # Another assistant: only the unrestricted mode is offered.
    When I navigate to "/ai-agent-modes/options/test_orchestrator?assistant=another_assistant"
    Then the JSON response should not contain "Test assistant only mode"
     And the JSON response should contain "Focus on Child One"

    # No assistant, as the Drupal Canvas AI panel asks: withheld.
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the JSON response should not contain "Test assistant only mode"
     And the JSON response should contain "Focus on Child One"

    When I navigate to "/admin/config/ai/agent-modes"
     And I open the "Delete" operation in the "Test assistant only mode" row
     And I press "Delete"
    Then I should see "The AI agent mode Test assistant only mode has been deleted."
    When I navigate to "/ai-agent-modes/options/test_orchestrator?assistant=test_assistant"
    Then the JSON response should not contain "Test assistant only mode"
     And the JSON response should contain "Focus on Child One"
