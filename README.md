# AI Agent Modes

Adds a **Mode** selector in front of any AI agent chat. Selecting a mode
restricts the orchestrator to a curated subset of its sub-agents, with a tight,
scoped context — instead of making it enumerate every available sub-agent to
decide what to do.

## Documentation

Full documentation is in [`docs/`](docs/index.md): choosing a mode while you
work, managing modes, developing with modes, and troubleshooting.

## Why

Free-form prompting to a page-building orchestrator works well when mapping to
existing components, but struggles on genuinely custom components: as the
orchestrator loads descriptions and context for *all* of its sub-agents, the
context window grows and the model starts inventing props. Letting the user
declare intent by selecting a sub-agent (or a curated subset) shrinks that
context so the model behaves reliably.

## How it works

- A **mode** is an `ai_agent_mode` config entity: a saved subset of one agent's
  sub-agents (shippable in config, recipes and other modules).
- A mode can be limited to **selected AI Assistants**, so two assistants sharing
  one parent agent each offer the modes that belong to their job. Naming no
  assistant, the default, offers the mode everywhere.
- The selector dropdown is populated **dynamically** from the parent agent's
  live sub-agents (read through the AI Agents plugin manager), plus any saved
  modes. The default option is free-form (the full set).
- When a mode is active, an event subscriber prepends a short directive to the
  agent's system prompt naming the sub-agent(s) the work should be routed to. It
  hooks `ai_agents.pre_system_prompt`, so the agent still replaces tokens
  afterwards and a mode's own text may contain them.
- Steering leaves every tool in place, which is the default. A mode can also be
  set to **withhold**: the sub-agent tools it does not name are then dropped from
  the request through the agent's own function override, so the model never sees
  them. Only sub-agent tools are ever withheld, and a site-wide switch turns the
  behaviour off without editing any mode.
- An AI Assistant with no agent behind it is steered through the AI Assistant
  API's own system-prompt event, so it can use the generic modes too.
- The selection persists per conversation (private tempstore).

## Surfaces

- **Drupal Canvas AI**: the dropdown is injected into the Canvas AI chat input
  row, next to the upload-image button.
- **AI Assistant chatbot**: the dropdown is added to the assistant chat form,
  with the parent agent read from the assistant configuration.
- **Anywhere**: place the *AI Agent Mode selector* block, or embed the
  `ai_agent_mode_select` render element.

## Usage

1. Enable the module (requires only `ai` and `ai_agents`).
2. Manage modes at **Administration › Configuration › AI › AI Agent Modes**
   (`/admin/config/ai/agent-modes`, permission *administer ai agent modes*).
3. Place the **AI Agent Mode selector** block next to any agent chat UI and
   point it at the parent agent (e.g. `canvas_ai_orchestrator`), or embed the
   `ai_agent_mode_select` render element in your own surface.

The module installs cleanly with only `ai` + `ai_agents` present and degrades
gracefully when other modules are absent. The default Canvas modes (page,
section, component, pattern) install automatically only when the matching Canvas
agents exist.
