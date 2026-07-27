# Managing modes

This page is for site administrators. Everything here lives at
**Configuration** > **AI** > **AI Agent Modes** (`/admin/config/ai/agent-modes`)
and needs the **Administer AI agent modes** permission.

The screen has two tabs: **Modes**, which lists the modes on the site, and
**Settings**, which controls where the dropdown appears in the Drupal Canvas AI
panel.

## The Modes tab

![The Modes listing with its Mode, Parent agent, Sub-agents, Enabled and Operations columns](https://www.drupal.org/files/issues/2026-07-27/3b-ai-agent-modes-listing.png)

*Configuration > AI > AI Agent Modes, Modes tab.*

The listing has one row per mode:

- **Mode.** The label people see in the dropdown.
- **Parent agent.** The agent the mode belongs to, or **Any (generic)** when it
  is not tied to one.
- **Sub-agents.** The sub-agents the mode names. When a mode names none but
  carries an instruction of its own, this reads **Prompt only**, because such a
  mode does still steer the assistant. It reads **None** only when the mode has
  neither, in which case it steers nothing at all.
- **Enabled.** Disabled modes stay in configuration but are never offered in any
  dropdown.

Rows are ordered by weight, then by label. Use **Add AI agent mode** to create
one, and the **Edit** button with its drop-button for **Delete**.

## Adding or editing a mode

The add and edit forms are the same. The fields, in order:

**Label.** Required. What people see in the dropdown, so write it as a job:
"Page Builder Only", "Content Types & Fields".

**Machine name.** Set once when you create the mode and fixed afterwards. It is
the name the configuration file carries, so choose it as carefully as you would
any other machine name.

**Description.** Optional, for your own records. It is not shown in the chat
dropdown.

**Enabled.** On by default for a new mode. Turn it off to withdraw a mode from
the dropdowns without deleting it.

**Parent agent.** The agent this mode scopes. The list holds every AI agent on
the site. Leave it on **- Any (generic) -** for a mode that should be offered for
every agent. Changing this field reloads the sub-agent checkboxes underneath it,
so pick the agent first.

**Sub-agents in this mode.** The curated subset. The checkboxes are built from
the parent agent's live sub-agents, read from the tools that agent currently has
enabled, so the list always reflects the site as it is now rather than a fixed
list. Each option shows the sub-agent label with its machine name in brackets.
When the parent agent is left generic, the checkboxes offer the sub-agents of
every agent on the site.

Keep the subset small. A tight subset is the whole point: it is what keeps the
assistant from weighing up every skill it has before it answers.

**System prompt addition.** Optional. A short instruction added to the request
whenever this mode is active, on top of the line that names the sub-agents. Use
it for house rules that belong to this kind of work, for example "Only work on
the section that is currently selected."

A mode with an instruction here and no sub-agents ticked is valid and does steer
the assistant. That is the **Prompt only** case in the listing.

**Surfaces.** Optional, one surface ID per line. Leave it empty, which is the
usual choice, to offer the mode everywhere. The module itself uses one surface
ID, `ai_assistant`, for the AI Assistant chat. Not every surface filters on this
field, so it is a tidying tool rather than an access control. See
[Developing with modes](developing-with-modes.md#surfaces) for exactly where it
applies. To withdraw a mode from every surface, uncheck **Enabled**.

**Weight.** Orders the mode in the listing and in the dropdown. Lower comes
first.

Saving returns you to the listing with a confirmation.

## Deleting a mode

Delete removes the mode from configuration after a confirmation step. Anyone who
had it selected falls back to the default the next time they use the assistant,
so nothing breaks. If you only want it out of the way for now, uncheck
**Enabled** instead.

## The Settings tab

![The settings tab with four placement options, each with a small diagram of the assistant panel](https://www.drupal.org/files/issues/2026-07-27/3-ai-agent-modes-settings.png)

*Configuration > AI > AI Agent Modes, Settings tab.*

One question, four answers, and the small diagram beside each option shows where
the dropdown lands in the assistant panel:

- **Top of the panel.** A full-width row at the top of the panel, above the
  chat.
- **Above the message box.** Its own row directly above the box you type in.
- **Under the message box.** Its own row directly under the box you type in.
- **In the toolbar (compact).** A small control inside the message box toolbar,
  between the attach button and the send button. This is the default.

The setting applies to everyone using the assistant, and it controls the
**Drupal Canvas AI** panel. The chatbot always places the dropdown above the
chat, and the selector block sits wherever you place the block.

One thing to expect from the compact toolbar option: it is tied to the send
button, and Drupal Canvas AI keeps the send button hidden until there is
something to send, so the control is not visible on an empty message box. It
appears as soon as text is typed.

## Permission

A single permission, **Administer AI agent modes**
(`administer ai agent modes`), covers the listing, the add and edit forms, the
delete form and the settings form. It is marked as a restricted permission, so
grant it the way you would grant any other administrative permission.

Choosing a mode in a chat needs no permission beyond being logged in and being
able to use the assistant. Creating modes and choosing modes are deliberately
separate jobs.

## Watch it

<video controls preload="metadata" width="100%" style="max-width:960px"
       poster="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-managing-modes-poster.jpg">
  <source src="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-managing-modes.mp4" type="video/mp4">
  Your browser cannot play this video.
  <a href="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-managing-modes.mp4">Download it instead</a>.
</video>

Managing modes as an administrator, 48 seconds: the Modes listing, a mode edit form, and the placement setting.
