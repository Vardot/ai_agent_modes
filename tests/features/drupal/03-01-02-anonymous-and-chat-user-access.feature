@ai-agent-modes @admin @security
Feature: Who may reach the mode admin surfaces and the dropdown data
  As a security-conscious site owner
  I want the mode administration kept to administrators and the dropdown data
  kept to people who are logged in
  So that a visitor can neither administer modes nor read which modes the site
  offers, while everyone using the assistant still gets their dropdown

  # Two different requirements guard this module, and they are deliberately not
  # the same:
  #
  # - The four admin surfaces require the "administer ai agent modes"
  #   permission. 03-01-01 proves an authenticated non-admin is turned away;
  #   this outline adds the visitor who is not logged in at all.
  # - The options endpoint the chat surfaces read only requires a logged-in user
  #   (route ai_agent_modes.options, _user_is_logged_in: TRUE), because the
  #   people picking a mode in the chat are not administrators. So a visitor is
  #   turned away from it, and an authenticated non-admin still gets the same
  #   payload the dropdown is built from.
  #
  # Both directions are asserted, so tightening the endpoint to an admin
  # permission (which would silently take the dropdown away from every chat
  # user) fails a named scenario, and loosening it to anonymous fails another.

  Scenario Outline: A visitor who is not logged in is denied <area>
    Given I am an anonymous user
    Then I am denied access to "<path>"

    Examples: The admin surfaces and the dropdown data source
      | area                     | path                                             |
      | the mode collection      | /admin/config/ai/agent-modes                     |
      | the add-mode form        | /admin/config/ai/agent-modes/add                 |
      | the settings form        | /admin/config/ai/agent-modes/settings            |
      # test_child_one deliberately, not test_orchestrator: keeps this row's
      # URL from being the exact one the next scenario requests as a logged
      # in user, so a caching layer cannot serve this anonymous 403 back to
      # that authenticated request.
      | the dropdown options     | /ai-agent-modes/options/test_child_one           |

  Scenario: An authenticated non-admin still gets the dropdown options
    Given I am a logged in user with the "Webmaster" user
     And I add testing users
    Given I am a logged in user with the "Authenticated user" user
    When I navigate to "/ai-agent-modes/options/test_orchestrator"
    Then the JSON response should contain "let the assistant decide"
     And the JSON response should contain "Focus on Child One"
     And the options endpoint should offer more than 1 option
     And I the page should not have PHP errors
    Then I am denied access to "/admin/config/ai/agent-modes"
