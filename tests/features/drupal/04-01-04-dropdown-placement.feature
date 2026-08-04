@ai-agent-modes @admin @canvas
Feature: Choosing where the mode dropdown sits in the assistant panel
  As a site administrator
  I want to choose where the mode dropdown appears in the assistant panel
  So that the control sits where it suits the people using the panel, and my
  choice reaches the panel itself

  # The settings form offers four placements, each with a small diagram:
  # "Top of the panel", "Above the message box", "Under the message box" and
  # "In the toolbar (compact)". They are saved as ai_agent_modes.settings
  # canvas_position (top, above_input, below_input, toolbar).
  #
  # The choice reaches the Canvas AI panel through the options payload:
  # SelectionController::options() rides the placement on the same response the
  # panel already fetches, and js/canvas-ai.js places the control from it - top
  # and below_input as a light-DOM row before or after the chat, above_input and
  # toolbar anchored inside the message box (the compact toolbar variant only
  # shows itself while the send button is visible, so it appears once there is
  # something to send). Asserting the saved radio plus the placement the panel
  # is told to use covers both ends of that contract without driving the Canvas
  # editor's React panel, which the @canvas-editor lane does.
  #
  # The rows run in order and the last one restores the shipped default
  # (toolbar), so the site is left exactly as it was found.

  Background:
    Given I am a logged in user with the "Webmaster" user

  # The radios are chosen by element ID, not by label: the settings form now has
  # a second placement question for the AI Chatbot panel whose options carry some
  # of the same words, so a label match would be ambiguous.
  Scenario Outline: The "<label>" setting is what the Canvas panel is told to use
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I select radio button "#edit-canvas-position-<id>"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
    Then the radio button "#edit-canvas-position-<id>" should be selected
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the options endpoint should report the "<placement>" dropdown placement
     And I the page should not have PHP errors

    Examples: The four placements the Drupal Canvas AI panel offers
      | label                     | id           | placement    |
      | Top of the panel          | top          | top          |
      | Above the message box     | above-input  | above_input  |
      | Under the message box     | below-input  | below_input  |
      | In the toolbar (compact)  | toolbar      | toolbar      |

  # The AI Chatbot panel has its own placement question, whose value never
  # reaches the options endpoint (the chatbot script is handed it directly), so
  # this row is asserted on the form alone.
  Scenario Outline: The AI Chatbot panel keeps the "<label>" placement it was given
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I select radio button "#edit-chatbot-position-<id>"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
    Then the radio button "#edit-chatbot-position-<id>" should be selected
     And I the page should not have PHP errors

    Examples: The three placements the AI Chatbot panel offers
      | label                                        | id           |
      | Under the message box                        | below-input  |
      | In the panel header, beside the assistant     | header       |
      | Above the chat, under the panel header        | above-chat   |
