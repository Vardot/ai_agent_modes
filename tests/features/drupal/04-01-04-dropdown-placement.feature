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

  # The settings form groups its questions as tabs, so each scenario opens the
  # "Mode dropdown" tab before touching the placement it is about.

  Background:
    Given I am a logged in user with the "Webmaster" user

  # The radios are chosen by element ID, not by label: the settings form now has
  # a second placement question for the AI Chatbot panel whose options carry some
  # of the same words, so a label match would be ambiguous.
  Scenario Outline: The "<label>" setting is what the Canvas panel is told to use
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Mode dropdown" settings tab
     And I select radio button "#edit-canvas-position-<id>"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Mode dropdown" settings tab
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

  # The AI Chatbot panel is no longer asked about on this form: the settings form
  # covers the Drupal Canvas AI panel, and that panel's placement is a site-wide
  # choice because it belongs to no single assistant. The chatbot panel's own
  # placement is decided per AI Assistant instead, on the assistant's own form,
  # falling back to the site default when it says nothing.
  Scenario: The settings form asks about the Drupal Canvas AI panel only
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Mode dropdown" settings tab
    Then I should see "Where should the dropdown appear in the Drupal Canvas AI panel?"
     And I should not see "Where should the dropdown appear in the AI Chatbot panel?"
     And I the page should not have PHP errors
