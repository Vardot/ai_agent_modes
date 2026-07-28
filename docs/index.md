---
hide:
  - toc
---

# AI Agent Modes documentation

AI Agent Modes puts a **Mode** dropdown in front of an AI agent chat, so the
person using the assistant can say what kind of job they want done before they
start typing.

![The Mode dropdown in the Drupal Canvas AI message box toolbar, set to All, let the assistant decide](https://www.drupal.org/files/project-images/a2-2-mode-selector-toolbar.png)

Picking a mode does not take anything away from the assistant. It adds a short
instruction to the request that names the sub-agents the work should be routed
to. The assistant keeps every tool it had and is simply pointed in the right
direction.

A mode is a configuration entity (`ai_agent_mode`): a saved subset of one parent
agent's sub-agents, with an optional instruction of its own. Because it is
configuration, a mode can be exported, shipped in a recipe or module, and moved
between sites.

## Pages

| Page | For | What it covers |
| --- | --- | --- |
| [Choosing a mode](./info/choosing-a-mode.md) | Anyone using the assistant | Where the dropdown is, how to pick a mode, how long the choice lasts, and why the dropdown is sometimes not there |
| [Managing modes](./info/managing-modes.md) | Site administrators | Creating, editing and deleting modes, every field on the form, the four dropdown placements, and the permission |
| [Developing with modes](./info/developing-with-modes.md) | Developers | The entity shape, shipping modes in config and recipes, the block and the render element, the JSON endpoints, and exactly how the steering is applied |
| [Troubleshooting](./help/troubleshooting.md) | Everyone | The traps that look like bugs and are not |

## Watch it work

Two short walkthroughs, recorded on a Drupal CMS site:


<video controls preload="metadata" width="100%" style="max-width:960px"
       poster="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-using-the-mode-selector-poster.jpg">
  <source src="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-using-the-mode-selector.mp4" type="video/mp4">
  Your browser cannot play this video.
  <a href="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-using-the-mode-selector.mp4">Download it instead</a>.
</video>

Using the mode selector, 43 seconds: opening the assistant panel, picking a mode, and the assistant scoped to that job.

<video controls preload="metadata" width="100%" style="max-width:960px"
       poster="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-managing-modes-poster.jpg">
  <source src="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-managing-modes.mp4" type="video/mp4">
  Your browser cannot play this video.
  <a href="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-managing-modes.mp4">Download it instead</a>.
</video>

Managing modes as an administrator, 48 seconds: the Modes listing, a mode edit form, and the placement setting.

## Requirements

- Drupal 11.
- The [AI](https://www.drupal.org/project/ai) and
  [AI Agents](https://www.drupal.org/project/ai_agents) modules. Nothing else is
  required.
- A parent agent with sub-agents, since a mode is normally a subset of them. A
  mode can also steer with an instruction alone, without naming any sub-agent.

The chat integrations are optional and switch themselves on when the matching
module is present: Drupal Canvas AI for the Canvas AI panel, and AI Chatbot or
AI Assistant API for the assistant chat.

## Getting started

1. Install the module.
2. Go to **Configuration** > **AI** > **AI Agent Modes**
   (`/admin/config/ai/agent-modes`) and add your first mode.
3. Open your assistant and pick the mode from the dropdown before you type.

The module ships no modes of its own. The list your site offers is the list
someone created on it, or one that arrived with a recipe.

## About this documentation

Written for the `1.0.x` branch, verified against release `1.0.0-alpha2`.
