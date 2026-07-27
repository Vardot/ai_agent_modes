# AI Agent Modes - functional test suite

Two layers of automated tests ship with this module.

## PHPUnit (`tests/src/`)

- `Unit/Hook/ChatbotHooksTest.php`, `Kernel/ModeManagerTest.php`,
  `Functional/AiAgentModeCrudTest.php` - run by the Drupal GitLabCI template.

## Varbase functional testing suite (webship-js: Playwright + Cucumber)

A browser BDD acceptance suite under `tests/features/drupal/`, driven through
[webship-js](https://webship.co/docs/webship-js/2.0.x) (Playwright + Cucumber).
It is tested against **Drupal CMS**, the distribution that ships the AI
Assistant chatbot (`drupal_cms_ai` / `ai_chatbot`) and Canvas AI this module
integrates with.

It covers: clean install with `ai` + `ai_agents`; creating a mode through the
admin UI by picking a parent agent's live sub-agents; permission gating of the
admin surfaces; the Canvas AI panel's client-side data source (the
`/ai-agent-modes/options/<agent>` endpoint); and the AI Assistant chatbot mode
selector dropdown (the placeable selector block, which uses the same
`resolveAgent()` + `ai_agent_mode_select` render path the chatbot hooks use).

Feature files, in the order the runner takes them:

| Feature | What it holds the module to |
|---|---|
| `00-01-01-users-login` | every configured test user can log in |
| `01-01-01-install` | the module, its two dependencies and its admin surfaces install clean |
| `02-01-01-mode-crud` | creating a mode by picking a parent agent's live sub-agents |
| `02-01-02-mode-listing` | what each listing row says: parent agent or "Any (generic)", the sub-agent IDs or "Prompt only" or "None", and enabled |
| `02-01-03-mode-delete` | deleting a mode withdraws it from the listing and from the dropdown options |
| `03-01-01-access-control` | the admin surfaces need "administer ai agent modes" |
| `03-01-02-anonymous-and-chat-user-access` | a visitor is denied everything including the options endpoint, while a logged-in non-admin still gets the dropdown data |
| `04-01-01-canvas-ai-surface` | the options endpoint feeds the Canvas AI dropdown |
| `04-01-02-prompt-only-and-generic-modes` | prompt-only modes and modes with no parent agent are reachable from the chat |
| `04-01-03-dropdown-hidden-when-nothing-to-choose` | an agent with no modes offers a single option, which is where both client scripts stop rendering a dropdown |
| `04-01-04-dropdown-placement` | each of the four placement settings is what the panel is told to use |
| `05-01-01-chatbot-assistant-surface` | the AI Assistant chatbot mode selector dropdown |
| `06-01-01-ai-live-agent` (`@ai`) | the same surfaces with a live chat provider configured |

Every scenario that needs a mode of a particular shape creates it through the
add form and deletes it again, so the suite can be pointed at an existing site
and leaves it as it was found.

Placing the dropdown inside the Drupal Canvas AI panel itself (the React island
plus the deep-chat shadow DOM) is still the one thing the suite does not drive;
`04-01-04` covers that contract up to the placement the panel is handed.

### Fixtures

`tests/recipes/ai_agent_modes_test` seeds a deterministic, provider-free
fixture: a parent **Test Orchestrator** agent exposing **Test Child One** /
**Test Child Two**, an **AI Assistant** backed by that orchestrator, one saved
mode, and the selector block placed in the Gin admin content region.
`tests/recipes/ai_agent_modes_amazee` provisions amazee.ai's keyless free-trial
provider for the `@ai` live-provider lane.

### Running locally

```bash
npm install
npx playwright install chromium
# Point at a running site with ai_agent_modes enabled and the test recipe applied:
LAUNCH_URL=https://your-site.ddev.site npm test          # default provider-free lane
CUCUMBER_TAGS="@ai and not @wip" LAUNCH_URL=... npm test  # live-provider lane
```

Reports, screenshots and videos are written under `tests/reports`,
`tests/screenshots` and `tests/videos` (git-ignored).

CI runs the suite in the `functional-testing (Drupal CMS)` and
`functional-testing (Drupal CMS - AI live agent - amazee.ai)` jobs; step
definitions are linted by the `eslint (step definitions)` job.
