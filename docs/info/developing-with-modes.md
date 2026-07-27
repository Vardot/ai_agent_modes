# Developing with modes

This page is for developers integrating AI Agent Modes, shipping modes with a
module or recipe, or adding the selector to a chat surface of their own.

## The mode entity

A mode is the `ai_agent_mode` configuration entity, stored as
`ai_agent_modes.ai_agent_mode.<id>`. Full shape, with every exported property:

```yaml
langcode: en
status: true
dependencies:
  config:
    - ai_agents.ai_agent.canvas_ai_orchestrator
id: page_builder_only
label: 'Page Builder Only'
description: 'Scopes the Canvas orchestrator to page building.'
weight: 0
agent: canvas_ai_orchestrator
sub_agents:
  - canvas_page_builder_agent
  - canvas_metadata_generation_agent
system_prompt_addition: 'Work on page structure only.'
surfaces: {  }
```

| Property | Type | Meaning |
| --- | --- | --- |
| `id` | machine name | The mode ID, used as `mode:<id>` in a selection |
| `label` | string | Shown in the dropdown |
| `description` | text | Administrative note, not shown in the chat |
| `weight` | integer | Order in the listing and the dropdown |
| `status` | boolean | Disabled modes are never offered |
| `agent` | string | Parent agent plugin ID. An empty string makes the mode generic and it is then offered for every agent |
| `sub_agents` | list of strings | The sub-agent plugin IDs this mode names |
| `system_prompt_addition` | text | Extra instruction added when the mode is active |
| `surfaces` | list of strings | Surface IDs. An empty list means all surfaces |

The entity does not calculate a dependency on the agent it names, so add that
dependency yourself when you ship a mode, as in the example above.

## Shipping modes in a module or a recipe

The module ships no modes of its own, by design: what a useful mode is depends
entirely on the agents a site has.

In a module, put the file in `config/install/` when the mode should always be
created, or in `config/optional/` when it should only be created if the agent it
depends on is present. Optional configuration is installed only once its
declared dependencies are satisfied, which is why the `dependencies` block
matters.

```text
my_module/config/optional/ai_agent_modes.ai_agent_mode.page_builder_only.yml
```

In a recipe, install the module and put the mode alongside it:

```yaml
name: 'My AI modes'
type: 'Site'
install:
  - ai
  - ai_agents
  - ai_agent_modes
```

```text
my_recipe/config/ai_agent_modes.ai_agent_mode.page_builder_only.yml
```

There is a working example in the repository:
`tests/recipes/ai_agent_modes_test/`, which seeds a parent agent, two
sub-agents, an assistant, a saved mode and the selector block, with no AI
provider needed.

## How a sub-agent list is discovered

`ModeManagerInterface::listSubAgents($parent_agent_id)` reads the parent agent
entity's enabled `tools`, keeps the tool plugins whose group is `agent_tools`
(falling back to the `ai_agents::ai_agent::` ID prefix when the definition is
not available), and resolves each one to the child agent it wraps. So the list
follows the agent's current configuration, and there is no hardcoded set
anywhere in the module.

This is also why a mode can name a sub-agent that is not offered: if the parent
agent later loses that tool, the name stays in configuration and is ignored at
runtime.

## How the steering is applied

`AgentScopeSubscriber` listens on the `ai_agents` request event at priority 100.
For each request it:

1. Reads the stored selection for the agent ID and thread ID.
2. Resolves it with `ModeManagerInterface::resolve()` into a `ScopePayload`.
3. Applies it with `ModeManagerInterface::applyScope()`, which **prepends** the
   directive to the existing system prompt.

Tools are never removed from the request. The assistant keeps its full
capability and is steered by text alone. That keeps the integration safe on any
agent, including agents that were never written with this module in mind.

The directive, when the mode names sub-agents:

```text
MODE (AI Agent Modes): For this request, use the following sub-agent(s): a, b.
Route the task to them and prefer them over other sub-agents for this conversation.
```

And when it steers by instruction alone:

```text
MODE (AI Agent Modes): Follow this working mode for the conversation.
```

The mode's `system_prompt_addition` is appended to whichever of the two applies,
then the whole block is placed in front of the agent's own system prompt.

### Resolution rules

`resolve($parent_agent_id, $selected_sub_agents, $mode_id)`:

- A saved mode wins over an ad-hoc sub-agent selection.
- A mode that is missing or disabled resolves to nothing, so the request is left
  untouched.
- A generic mode (empty `agent`) is resolved against the agent that is making
  the request.
- The mode's `sub_agents` are intersected with the parent agent's live
  sub-agents. Names that are not currently available are dropped.
- If that intersection is empty **and** the mode has no
  `system_prompt_addition`, the mode resolves to nothing. This is what
  `ScopePayload::isRestrictive()` decides, and it is the same test the listing
  uses to show a mode as **Prompt only** rather than **None**.

Every applied scope is logged at info level to the `ai_agent_modes` channel,
naming the mode, the agent and the sub-agents it steered to, which is the
quickest way to confirm a mode really took effect.

## Where a selection is stored

`SelectionStore` writes to the private tempstore, collection `ai_agent_modes`,
under the key `<agent_id>:<conversation_id>`, or `<agent_id>:session` when no
conversation ID is given. So a selection belongs to one user, is scoped to a
conversation when the surface supplies an ID, and is never a permanent setting.

Reads fall back from the conversation key to the session key, so the first turn
of a new conversation is still covered by a selection made before it started.

A selection is one of three values everywhere in the module:

- `''` clears the selection.
- `mode:<mode_id>` selects a saved mode.
- `agent:<sub_agent_id>` selects a single sub-agent ad hoc.

## Surfaces

`surfaces` is a free list of IDs, and the module uses one of its own:
`ai_assistant`. Which surfaces actually filter on it is worth being precise
about:

- The **AI Assistant chat form** filters. It builds the render element with
  `#surface` set to `ai_assistant`, so a mode restricted to another surface is
  not offered there.
- The **selector block** and the **render element** filter only when you give
  them a surface, and both leave it empty by default.
- The **chatbot block** uses `ai_assistant` only to decide whether to attach its
  script at all. The list it then shows comes from the JSON options endpoint,
  which does not take a surface.
- The **Drupal Canvas AI panel** does not filter either, for the same reason. It
  lists every enabled mode for `canvas_ai_orchestrator`, whatever the `surfaces`
  field says.

So use `surfaces` to keep a mode out of the assistant chat form and out of your
own surfaces, not as an access control. To withdraw a mode everywhere, disable
it.

## Adding the selector to your own surface

### The block

The **AI Agent Mode selector** block (plugin ID `ai_agent_mode_selector`,
category AI) can go anywhere. Its settings:

- **AI Assistant.** The agent is read from the assistant, the same way the
  chatbot block is configured. Takes precedence over the field below.
- **Parent agent.** The agent plugin ID, used when no assistant is selected.
- **Surface.** Optional surface ID used to filter the modes listed.

The block renders a small form with an **Apply mode** button and reports the
result with a status message.

### The render element

For your own form, use the `ai_agent_mode_select` element:

```php
$form['mode'] = [
  '#type' => 'ai_agent_mode_select',
  '#parent_agent' => 'canvas_ai_orchestrator',
  '#surface' => 'canvas',
];
```

It is a `select` whose options are built for you: a free-form default, then a
**Modes** group, then a **Sub-agents** group listing the parent agent's live
sub-agents so a single one can be picked ad hoc. Each group is added only when
it has something in it. The element adds the `config:ai_agent_mode_list` and
`config:ai_agent_list` cache tags.

Note the difference from the client-rendered surfaces: the JSON endpoint below
deliberately offers modes only, because raw sub-agent names are meaningful to
developers and not to the people building pages.

To persist a change made in your own form, attach the `ai_agent_modes/chat`
library, give the select the `ai-agent-modes-mode` class, and set
`drupalSettings.aiAgentModes` with `agent` and `conversation`. That is how the
AI Assistant chat form integration works.

### The JSON endpoints

`GET /ai-agent-modes/options/{agent}` returns the option list for a client-side
dropdown. It requires a logged-in user and applies no further access check, and
returns:

```json
{
  "agent": "canvas_ai_orchestrator",
  "value": "mode:page_builder_only",
  "options": [
    { "value": "", "label": "All, let the assistant decide", "group": "" },
    { "value": "mode:page_builder_only", "label": "Page Builder Only", "group": "" }
  ],
  "position": "toolbar"
}
```

`position` is the `ai_agent_modes.settings:canvas_position` value, carried on the
response so the client does not need a second request to know where to put the
control.

`POST /ai-agent-modes/selection` stores a selection. It requires a logged-in
user and a CSRF request header token, obtained from `/session/token`, with a
JSON body:

```json
{ "agent": "canvas_ai_orchestrator", "value": "mode:page_builder_only", "conversation": "" }
```

It answers `{"status": "applied"}`, or `{"status": "cleared"}` for an empty
value, and returns 400 for a missing agent or a value that is neither empty nor
prefixed with `mode:` or `agent:`.

## How the two chat integrations attach

Both are soft: they do nothing when the host module is absent.

- **Drupal Canvas AI.** `hook_library_info_alter()` adds
  `ai_agent_modes/canvas_ai` as a dependency of the Canvas editor bundle
  (`canvas/canvas-ui`), and only when `canvas_ai` is installed. The Canvas AI
  panel is a React island whose chat is a web component, so the dropdown is
  injected client-side rather than through the render pipeline. The script polls
  for the panel, fetches the options endpoint, and places the control according
  to `position`.
- **AI Chatbot.** `hook_library_info_alter()` adds
  `ai_agent_modes/chatbot_deepchat` to the `ai_chatbot/deepchat` library, and
  `hook_block_view_ai_deepchat_block_alter()` resolves the assistant's agent and
  passes it to the script in `drupalSettings.aiAgentModesChatbot.agent`. It
  bails out when `ai_assistant_api` is absent, when the block has no assistant,
  when the assistant has no agent, or when that agent has neither modes nor
  sub-agents.

Both scripts stop before injecting anything when the endpoint returns a single
option, since a dropdown with one choice is not a choice.

## Services

| Service | Interface | Use it for |
| --- | --- | --- |
| `ai_agent_modes.manager` | `ModeManagerInterface` | Listing sub-agents and modes, resolving a selection, applying a scope |
| `ai_agent_modes.selection_store` | `SelectionStoreInterface` | Reading, setting and clearing a selection |

Both interfaces are aliased to their service, so they can be autowired by type.
