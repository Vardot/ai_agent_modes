@ai-agent-modes @admin @chatbot
Feature: The AI Assistant chatbot mode selector dropdown
  As a site builder placing an AI assistant chat on Drupal CMS
  I want the modes offered for an assistant to be exactly the ones saved for its
  parent agent
  So that a user can scope the assistant before sending a message

  # Both the drupal_cms_ai / AI Assistant chatbot integrations (ChatbotHooks for
  # the deep-chat block, ChatFormHooks for the classic chat form) resolve the
  # parent agent FROM the AI Assistant and render the same `ai_agent_mode_select`
  # element, and both client-rendered chats fill that dropdown from the JSON
  # options endpoint. Reading the endpoint therefore proves what the chat will
  # offer, without the third-party deep-chat web component's shadow DOM and
  # without a live LLM provider.
  #
  # The fixtures (tests/recipes/ai_agent_modes_test) seed the "Test Assistant"
  # (backed by "Test Orchestrator", sub-agents Test Child One / Test Child Two,
  # plus two saved modes). They deliberately place no block: a fixture that put a
  # Mode dropdown on every admin page of the host site was the wrong way to make
  # this checkable. Both client-rendered surfaces build their dropdown from the
  # JSON options endpoint, so reading that endpoint is what proves the dropdown
  # they will show, without a live LLM provider and without touching the site's
  # block layout.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The endpoint offers the assistant's modes for its parent agent
    When I navigate to "/ai-agent-modes/options/test_orchestrator?assistant=test_assistant"
    Then the JSON response should contain "All, let the assistant decide"
     And the JSON response should contain "Focus on Child One"
     And the options endpoint should offer more than 1 option
     And I the page should not have PHP errors
