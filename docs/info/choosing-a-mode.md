# Choosing a mode

This page is for anyone who uses an AI assistant on the site: content editors,
site builders, marketers, testers. You do not need any administrative access to
pick a mode.

## What the dropdown does

The **Mode** dropdown is the answer to one question: what do you want the
assistant to work on? Pick the mode that matches the job, then type your request
as usual.

The first option is always the default:

- **All, let the assistant decide.** The assistant behaves exactly as it does
  without this module. Nothing is narrowed.

Below it are the modes your site offers, in the order an administrator gave
them.

Choosing a mode does not remove any of the assistant's abilities. It adds a
short line to the request telling the assistant which of its sub-agents to route
the work to, so it spends less effort deciding and stays closer to the task.

## Where to find it

In the **Drupal Canvas AI** panel the dropdown sits wherever the site is
configured to put it: at the top of the panel, above the message box, under the
message box, or tucked into the message box toolbar next to the send button. The
toolbar placement is the default. An administrator sets this once for the whole
site, so it is in the same place for everyone.

In the **AI Assistant chatbot** the dropdown is added just above the chat.

Elsewhere on the site an administrator can place the **AI Agent Mode selector**
block next to any AI chat. In the block version you choose a mode and then press
**Apply mode**, and the site confirms with a message.

## How long the choice lasts

Your choice is remembered for you, for the conversation you are in. It is not a
site setting and it does not change what anyone else sees. Set it once and keep
working.

In the Canvas AI panel and the chatbot the choice is saved the moment you pick
it. There is no save button and no page reload.

Going back to **All, let the assistant decide** clears the choice, and the
assistant returns to working with everything it has.

## An example set of modes

Modes are named by whoever creates them, so no two sites need to look alike.
The set below was created on a Drupal CMS site with Drupal Canvas, to split
page-building work from content-structure work.

| Mode | What it is for |
| --- | --- |
| Page Builder Only | Building and adjusting pages |
| Component Builder | Working on a single component |
| Template Builder | Working on templates |
| Content Types & Fields | Content structure work rather than page layout |
| Views & Listings | Listings and overview pages |

![The Mode list open in the Drupal Canvas AI panel, showing every mode the site offers](https://www.drupal.org/files/issues/2026-07-27/a2-1-modes-list-open.png)

![The Modes listing, showing five modes created for Drupal Canvas work, each with its parent agent and the sub-agents it names](https://www.drupal.org/files/issues/2026-07-27/3b-ai-agent-modes-listing.png)

*The Modes listing at Configuration > AI > AI Agent Modes.*

Ask whoever administers your site what your own modes cover, or read the
description column on each mode's edit form.

## If there is no dropdown

The dropdown hides itself when there is nothing to choose. Both the Canvas AI
panel and the chatbot leave it out entirely when the only option would be
"All, let the assistant decide", which happens when the site has no modes saved
for that assistant yet.

In the toolbar placement there is one more thing to know: the compact control
only appears while the send button is visible, and the send button stays hidden
until there is something to send. So on a fresh, empty chat the toolbar looks
bare. Type a few characters and the dropdown appears next to the send button.
This is deliberate, not a fault.

See [Troubleshooting](../help/troubleshooting.md) if the dropdown is still missing.

## Watch it

<video controls preload="metadata" width="100%" style="max-width:960px"
       poster="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-using-the-mode-selector-poster.jpg">
  <source src="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-using-the-mode-selector.mp4" type="video/mp4">
  Your browser cannot play this video.
  <a href="https://www.drupal.org/files/issues/2026-07-27/ai-agent-modes-using-the-mode-selector.mp4">Download it instead</a>.
</video>

Using the mode selector, 43 seconds: opening the assistant panel, picking a mode, and the assistant scoped to that job.

![The compact Mode selector in the Drupal Canvas AI message box toolbar](https://www.drupal.org/files/issues/2026-07-27/a2-2-mode-selector-toolbar.png)

The same selector in its compact placement, tucked into the message box toolbar.
