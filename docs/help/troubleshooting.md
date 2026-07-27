# Troubleshooting

The behaviors on this page look like faults and are not. Each one is
deliberate, and each one has a check you can run.

## The dropdown is not there at all

Both the Drupal Canvas AI panel and the chatbot leave the dropdown out when
there is only one thing to choose. With no modes saved for that assistant, the
only option would be "All, let the assistant decide", so nothing is shown rather
than a dropdown that cannot change anything.

Check, in order:

1. **Are there modes?** Go to **Configuration** > **AI** > **AI Agent Modes**. An
   empty listing means an empty dropdown, so nothing appears.
2. **Do the modes point at the right agent?** A mode is offered only for its own
   parent agent, or for every agent when it is generic. The Canvas AI panel asks
   for the `canvas_ai_orchestrator` agent, so a mode built for another agent
   never shows there.
3. **Are they enabled?** The Enabled column must read Yes.
4. **Is the host integration present?** The Canvas AI dropdown needs Drupal
   Canvas AI installed. The chatbot dropdown needs the chatbot block to be backed
   by an AI Assistant that has an agent set.

## Nothing appears in the toolbar until I start typing

Expected. In the **In the toolbar (compact)** placement the control is tied to
the send button, and Drupal Canvas AI hides the send button until there is
something to send. On an empty message box there is no send button and therefore
no dropdown.

Type a few characters and the control appears next to the send button. If you
would rather it were always visible, switch the placement on the Settings tab to
**Top of the panel**, **Above the message box** or **Under the message box**.

## A mode shows "Prompt only" in the Sub-agents column

Expected, and not a broken mode. A mode with no sub-agents ticked but with text
in **System prompt addition** still steers the assistant, using that instruction
alone. The listing says **Prompt only** so it is not mistaken for an empty mode.

**None** is the case to worry about. It means the mode has neither sub-agents nor
an instruction, so selecting it changes nothing. Open the mode and either tick
some sub-agents or write an instruction.

## I picked a mode and the answer looks the same

Modes steer, they do not restrict. The instruction names the sub-agents to route
the work to, and the assistant keeps every tool it had. A well-aimed request may
well produce the same answer in every mode, which is fine.

To confirm the mode really was applied, look at **Reports** > **Recent log
messages** and filter to the `ai_agent_modes` channel. Every applied mode is
logged with the mode label, the agent and the sub-agents it steered to. No log
entry means no mode was applied, and then it is worth checking the two cases
below.

## The mode is selected but it is not being applied

Two things quietly cancel a mode:

- **The sub-agents no longer exist on the parent agent.** The names in a mode are
  matched against the parent agent's live sub-agents, which come from the tools
  that agent currently has enabled. Names that no longer match are dropped, and
  if every name is dropped and the mode has no instruction of its own, the mode
  steers nothing. Open the mode: any sub-agent that is still valid appears as a
  ticked checkbox, so a mode showing no ticks while its configuration lists
  names is the symptom.
- **The mode was disabled or deleted.** A selection that points at a mode which
  is gone or switched off is ignored, and the assistant works with everything as
  usual.

## A mode I limited to one surface still shows in the chat

Expected. The **Surfaces** field is applied by the AI Assistant chat form, and by
the selector block or render element when you give them a surface. The two chat
surfaces that build their dropdown in the browser, the Drupal Canvas AI panel and
the chatbot, list every enabled mode for their agent and do not filter by
surface.

Treat **Surfaces** as tidying, not as access control. To take a mode out of
circulation everywhere, uncheck **Enabled**.

## My selection disappeared

A selection is stored per user in the private tempstore, keyed by the agent and
the conversation. It is not a saved setting, so it does not survive forever, and
it never applies to anyone else. Losing it after a long gap or a session change
is normal. Pick the mode again.

## I cannot reach the administration screens

The listing, the add and edit forms, the delete form and the settings form all
require the **Administer AI agent modes** permission. Without it every one of
those paths returns access denied. Choosing a mode in a chat is separate and
needs no permission beyond being logged in.
