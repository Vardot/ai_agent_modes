@ai-agent-modes @admin
Feature: AI Agent Modes installs cleanly on Drupal CMS
  As a site administrator
  I want the AI Agent Modes module to install on Drupal CMS with only its real
  dependencies (AI Core and AI Agents) present
  So that I can trust it raises no PHP errors on a stock Drupal CMS site

  # The module declares exactly two dependencies (ai:ai and ai_agents:ai_agents).
  # These scenarios prove the module and those dependencies are enabled and that
  # its admin surface and the status report load with no PHP errors.

  Background:
    Given I am a logged in user with the "Webmaster" user

  Scenario: The module and its dependencies are enabled on the modules page
    When I navigate to "/admin/modules"
    Then I should see "AI Agent Modes"
     And I should see "AI Agents"
     And I the page should not have PHP errors

  Scenario: The AI Agent Modes admin collection is reachable
    When I navigate to "/admin/config/ai/agent-modes"
    Then the "drupal page heading" element should contain text "AI Agent Modes"
     And I the page should not have PHP errors

  Scenario: The AI Agent Modes settings form is reachable
    When I navigate to "/admin/config/ai/agent-modes/settings"
    Then the "drupal page heading" element should contain text "AI Agent Modes settings"
     And I should see "Where should the dropdown appear in the Drupal Canvas AI panel?"
     And I should see "Where should the dropdown appear in the AI Chatbot panel?"
     And I the page should not have PHP errors

  Scenario: The status report loads with no PHP errors
    When I navigate to "/admin/reports/status"
    Then the "drupal page heading" element should contain text "Status report"
     And I the page should not have PHP errors
