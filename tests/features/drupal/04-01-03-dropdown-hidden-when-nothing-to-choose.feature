@ai-agent-modes @admin @canvas
Feature: No dropdown when there is nothing to choose
  As a person using an assistant that has no modes saved for it
  I do not want a mode dropdown in the chat at all
  So that the panel never offers a control whose only entry is "let the
  assistant decide"

  # Both client-side surfaces refuse to render anything while the options
  # payload holds a single entry: js/canvas-ai.js and js/chatbot-deepchat.js
  # each return early on `data.options.length <= 1`. So the number of options
  # the endpoint reports is the whole decision, and it is asserted from both
  # sides here:
  #
  # - Test Child One has no modes saved for it, so its payload is the lone
  #   "All" entry and no dropdown is built.
  # - Test Orchestrator has a saved mode, so its payload holds more than one
  #   entry and the dropdown is built.
  #
  # The negative assertion is deliberately paired with that positive one: an
  # options endpoint that answered with an empty payload for every agent would
  # otherwise pass the first scenario on its own.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: An agent with no saved modes offers nothing to choose from
    When I navigate to "/ai-agent-modes/options/test_child_one"
    Then the options endpoint should offer 1 option
     And the JSON response should contain "let the assistant decide"
     And the JSON response should not contain "Focus on Child One"
     And I the page should not have PHP errors

  Scenario: An agent with a saved mode has something to offer
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the options endpoint should offer more than 1 option
     And the JSON response should contain "let the assistant decide"
     And the JSON response should contain "Focus on Child One"
     And I the page should not have PHP errors
