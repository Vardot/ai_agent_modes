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

  Scenario Outline: The "<label>" setting is what the panel is told to use
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I select radio button "<label>"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
    Then the radio button with value "<placement>" should be selected
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the options endpoint should report the "<placement>" dropdown placement
     And I the page should not have PHP errors

    Examples: The four placements offered by the settings form
      | label                     | placement    |
      | Top of the panel          | top          |
      | Above the message box     | above_input  |
      | Under the message box     | below_input  |
      | In the toolbar (compact)  | toolbar      |
