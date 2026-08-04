# Managing modes

This page is for site administrators. Everything here lives at
**Configuration** > **AI** > **AI Agent Modes** (`/admin/config/ai/agent-modes`)
and needs the **Administer AI agent modes** permission.

The screen has two tabs: **Modes**, which lists the modes on the site, and
**Settings**, which controls where the dropdown appears in the Drupal Canvas AI
panel.

## The Modes tab

![The Modes listing with its Mode, Parent agent, Sub-agents, AI Assistants, Enabled and Operations columns](https://www.drupal.org/files/issues/2026-07-27/3b-ai-agent-modes-listing.png)

*Configuration > AI > AI Agent Modes, Modes tab.*

The listing has one row per mode:

- **Mode.** The label people see in the dropdown.
- **Parent agent.** The agent the mode belongs to, or **Any (generic)** when it
  is not tied to one.
- **Sub-agents.** The sub-agents the mode names. When a mode names none but
  carries an instruction of its own, this reads **Prompt only**, because such a
  mode does still steer the assistant. It reads **None** only when the mode has
  neither, in which case it steers nothing at all.
- **Scope.** **Steer only** for a mode that just adds an instruction, or **Steer
  and withhold** for one that also hides the sub-agent tools it does not name.
  The words match the field on the mode form.
- **AI Assistants.** The assistants the mode is offered for, or **Any assistant**
  when it is not tied to any.
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

**Scope strength.** Appears once you tick at least one sub-agent, and decides how
firmly the mode is applied.

- **Steer only** is the default and is what every mode created before this field
  existed does. The assistant is told which sub-agents to route the work to and
  keeps every tool it had.
- **Steer and withhold** also hides the sub-agent tools this mode does not name,
  so they are never sent to the model at all. That is what actually shrinks the
  context the model has to weigh up.

Four things are worth knowing before choosing to withhold:

1. Only sub-agent tools are ever hidden. The agent keeps its own tools, and a
   sub-agent that is kept keeps all of its own.
2. It needs a parent agent and at least one sub-agent ticked. The form refuses to
   save otherwise, because sub-agent names mean nothing without an agent and
   hiding every sub-agent would leave the assistant with none.
3. It narrows the context window rather than granting or denying permission. Each
   tool still authorises itself when it runs, so this is not an access control.
4. If the model calls a hidden sub-agent anyway, which can happen when the mode is
   changed part-way through a conversation, the current AI provider code raises a
   PHP error instead of answering gracefully. Pick the mode before starting the
   conversation, and see [Troubleshooting](../help/troubleshooting.md).

**System prompt addition.** Optional. A short instruction added to the request
whenever this mode is active, on top of the line that names the sub-agents. Use
it for house rules that belong to this kind of work, for example "Only work on
the section that is currently selected."

Drupal tokens in this text are replaced before the request is sent, so
`[site:name]` or `[user:display-name]` arrive resolved. The text is
administrator-authored configuration behind the *Administer AI agent modes*
permission, so it is no wider a surface than the agent's own prompt already is.

A mode with an instruction here and no sub-agents ticked is valid and does steer
the assistant. That is the **Prompt only** case in the listing.

**AI Assistants.** Optional. The field is only on the form when the site has at
least one AI Assistant. Leave every box unchecked, which is the usual choice, to
offer the mode for every assistant. Tick one or more assistants to offer the mode
for those only, which is what you want when two assistants share the same parent
agent and each should offer its own set of modes.

A mode that names assistants belongs to them. It is withheld from any chat that
is not backed by one of those assistants, and that includes the **Drupal Canvas
AI** panel, which is driven by an agent rather than by an assistant. So leave the
field empty for a mode that should be offered in Canvas.

Unlike **Surfaces** below, this is real filtering rather than tidying: every
place the module offers a mode passes the assistant along.

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

Three questions. The first is where the dropdown lands in the **Drupal Canvas AI**
panel, and the small diagram beside each option shows it:

- **Top of the panel.** A full-width row at the top of the panel, above the
  chat.
- **Above the message box.** Its own row directly above the box you type in.
- **Under the message box.** Its own row directly under the box you type in.
- **In the toolbar (compact).** A small control inside the message box toolbar,
  between the attach button and the send button. This is the default.

The second question is where it lands in the **AI Chatbot** panel, which is a
separate surface with its own markup, so it has its own answer:

- **Above the chat, under the panel header.** The default, and what the module
  did before this was configurable.
- **Under the message box.**
- **In the panel header, beside the assistant name.** The most compact option.

The chatbot panel can be held back by a privacy gate (Klaro) until the visitor
accepts it. The dropdown waits for the chat to appear rather than landing
somewhere else, so give it a moment after accepting.

That chatbot answer is the site default. A single assistant can override it on
its own edit form (**Configuration** > **AI** > **AI Assistant**, then edit one),
where **Mode dropdown position** offers the same three placements plus
**- Use the site setting -**, which is where every assistant starts. Use it when
one assistant needs a different layout from the rest; the choice is stored on
that assistant, so it travels with it when the assistant is exported.

The third question is **Enforce tool scope**, which is on by default. Turn it off
and every mode set to **Steer and withhold** behaves as **Steer only**: the
assistant is still pointed at the right sub-agents, but nothing is hidden from it.
It is the switch to reach for when you want to rule the withholding out as the
cause of a problem without editing any mode.

Both placement settings apply to everyone using the assistant. The selector block
sits wherever you place the block.

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
