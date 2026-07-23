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
