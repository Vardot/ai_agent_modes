# AI Agent Modes

Adds a **Mode** selector in front of any AI agent chat. Selecting a mode
restricts the orchestrator to a curated subset of its sub-agents, with a tight,
scoped context, instead of making it enumerate every available sub-agent to
decide what to do.

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
- The selector dropdown is populated **dynamically** from the parent agent's
  live sub-agents (read through the AI Agents plugin manager), plus any saved
  modes. The default option is free-form (the full set).
- When a mode is active, an event subscriber on `ai_agents.request` only adds
  guiding text: it prepends a short directive to the system prompt naming the
  sub-agent(s) the orchestrator should route the work to. Tools are left
  untouched, so the orchestrator keeps full capability and is simply steered.
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
