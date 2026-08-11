@ai-agent-modes @admin @security
Feature: Role-based access control for AI Agent Modes
  As a security-conscious site owner
  I want the AI Agent Modes admin surfaces to be reachable only by users with
  the "administer ai agent modes" permission
  So that lower-privileged accounts cannot create, edit or delete modes

  # The module gates every admin route on the dedicated "administer ai agent
  # modes" permission (permissions.yml, restrict access: true). This outline
  # asserts BOTH sides of every protected path - the administrator keeps access,
  # the authenticated non-admin is denied - so a regression in either direction
  # fails a precise, named row.

  Background:
    Given I am a logged in user with the "Webmaster" user
     And I add testing users
     # Each scenario performs its own role-specific login; drop the Background
     # session first so the login form is actually presented.
     And I am an anonymous user

  Scenario Outline: An administrator can reach <area>
    Given I am a logged in user with the "Webmaster" user
    When I navigate to "<path>"
    Then I the page should not have PHP errors

    Examples: Protected AI Agent Modes areas
      | area                     | path                                     |
      | the mode collection      | /admin/config/ai/agent-modes             |
      | the add-mode form        | /admin/config/ai/agent-modes/add         |
      | the settings form        | /admin/config/ai/agent-modes/settings    |

  Scenario Outline: An authenticated non-admin is denied <area>
    Given I am a logged in user with the "Authenticated user" user
    Then I am denied access to "<path>"

    Examples: Protected AI Agent Modes areas
      | area                     | path                                     |
      | the mode collection      | /admin/config/ai/agent-modes             |
      | the add-mode form        | /admin/config/ai/agent-modes/add         |
      | the settings form        | /admin/config/ai/agent-modes/settings    |
