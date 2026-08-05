@ai-agent-modes @admin
Feature: Offering speech in the assistant chat
  As a site administrator
  I want to decide whether the assistant chat listens and speaks, and where the
  microphone sits
  So that people can dictate a prompt instead of typing it and hear the reply
  read back, with the button where it suits the panel

  # deep-chat, which draws both chat surfaces, can already do both halves:
  # speechToText shows a microphone and dictates into the message box, and
  # textToSpeech reads each reply aloud. Neither surface exposes any of it. The AI
  # Chatbot module strips the speech configuration out of the settings it renders,
  # after its own hook_deepchat_settings has run, and the Drupal Canvas AI panel
  # is mounted by the Canvas editor's React bundle, so there is no render array to
  # configure. So the module carries the settings to both surfaces the way it
  # carries the mode dropdown: js/speech.js rides along with each surface's own
  # library and sets the properties on the mounted element.
  #
  # The recognition and the reading are the browser's, so nothing is sent to a
  # speech service and no key is needed. Neither can be driven from here (both
  # need a real device, and dictation needs a granted permission), so these
  # scenarios cover what the site administrator actually controls: the two
  # switches, the four placements, the languages, the voice commands, and that
  # both halves ship switched off.
  #
  # The settings form groups them as tabs, so a field is in the page but not
  # visible until its own tab is open: each scenario clicks its tab first, and
  # asserts on fields by element ID rather than on text being visible.
  #
  # The rows run in order and the last one restores the shipped defaults, so the
  # site is left exactly as it was found.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: Both halves are off until the site asks for them
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Microphone (speech to text)" settings tab
    Then the "#edit-speech-to-text-enabled" checkbox should not be checked
     And the "#edit-text-to-speech-enabled" checkbox should not be checked
     And I the page should not have PHP errors

  Scenario: The microphone half offers every deep-chat option
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Microphone (speech to text)" settings tab
     And I check the checkbox "#edit-speech-to-text-enabled"
     And I select "English (United Kingdom) - en-GB" from "#edit-speech-to-text-language"
     And I open the "Voice commands" details
     And I fill in the field "#edit-speech-to-text-commands-stop" with "stop listening"
     And I fill in the field "#edit-speech-to-text-commands-submit" with "send it"
     And I fill in the field "#edit-speech-to-text-translations" with "varbase|Varbase"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Microphone (speech to text)" settings tab
     And I open the "Voice commands" details
    Then the "#edit-speech-to-text-enabled" checkbox should be checked
     And the "#edit-speech-to-text-language" field should contain "en-GB"
     And the "#edit-speech-to-text-commands-stop" field should contain "stop listening"
     And the "#edit-speech-to-text-commands-submit" field should contain "send it"
     And the "#edit-speech-to-text-translations" field should contain "varbase|Varbase"
     And I the page should not have PHP errors

  Scenario: Reading replies aloud is its own choice, with its own voice
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Reading replies aloud (text to speech)" settings tab
     And I check the checkbox "#edit-text-to-speech-enabled"
     And I select "English (United States) - en-US" from "#edit-text-to-speech-language"
     And I fill in the field "#edit-text-to-speech-rate" with "1.2"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Reading replies aloud (text to speech)" settings tab
    Then the "#edit-text-to-speech-enabled" checkbox should be checked
     And the "#edit-text-to-speech-language" field should contain "en-US"
     And the "#edit-text-to-speech-rate" field should contain "1.2"
     And I the page should not have PHP errors

  # The placements are chosen by element ID rather than by label, as the dropdown
  # placement scenarios do: the form carries several placement questions whose
  # options share some of the same words.
  Scenario Outline: The "<label>" placement is saved
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Microphone (speech to text)" settings tab
     And I check the checkbox "#edit-speech-to-text-enabled"
     And I select radio button "#edit-speech-to-text-position-<id>"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Microphone (speech to text)" settings tab
    Then the "#edit-speech-to-text-enabled" checkbox should be checked
     And the radio button "#edit-speech-to-text-position-<id>" should be selected
     And I the page should not have PHP errors

    Examples:
      | label                                | id            |
      | Inside the message box, bottom right | input-end     |
      | Outside the message box, after it    | outside-end   |
      | Outside the message box, before it   | outside-start |
      | Inside the message box, bottom left  | input-start   |

  # Leaves the site as it was found: both halves off, no commands, no
  # corrections, and the languages back to the browser's own choice.
  #
  # Every field is set in one page, because the tabs are panes of a single form
  # and moving between them keeps what was typed, while navigating to the page
  # again throws it away. Each half is switched on first so that its fields are
  # shown, then switched off last; a field the states hide is still submitted.
  Scenario: Restoring the shipped defaults
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Microphone (speech to text)" settings tab
     And I check the checkbox "#edit-speech-to-text-enabled"
     And I open the "Voice commands" details
     And I fill in the field "#edit-speech-to-text-commands-stop" with ""
     And I fill in the field "#edit-speech-to-text-commands-submit" with ""
     And I fill in the field "#edit-speech-to-text-translations" with ""
     And I select "Let the browser choose" from "#edit-speech-to-text-language"
     And I open the "Reading replies aloud (text to speech)" settings tab
     And I check the checkbox "#edit-text-to-speech-enabled"
     And I select "Let the browser choose" from "#edit-text-to-speech-language"
     And I fill in the field "#edit-text-to-speech-rate" with "1"
     And I uncheck the checkbox "#edit-text-to-speech-enabled"
     And I open the "Microphone (speech to text)" settings tab
     And I uncheck the checkbox "#edit-speech-to-text-enabled"
     And I save the form
    Then I should see "The configuration options have been saved."
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Microphone (speech to text)" settings tab
    Then the "#edit-speech-to-text-enabled" checkbox should not be checked
    When I navigate to "/admin/config/ai/agent-modes/settings"
     And I open the "Reading replies aloud (text to speech)" settings tab
    Then the "#edit-text-to-speech-enabled" checkbox should not be checked
     And I the page should not have PHP errors
