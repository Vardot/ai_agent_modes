# Speaking and listening

This page is for site administrators. Everything here lives at **Configuration**
> **AI** > **AI Agent Modes** > **Settings**
(`/admin/config/ai/agent-modes/settings`) and needs the **Administer AI agent
modes** permission.

The assistant chat can do two things besides typing:

- **Speech to text.** A microphone in the message box. What is said is dictated
  into the box, and the person sends it as usual.
- **Text to speech.** Each reply is read aloud.

Both are switched **off** until the site asks for them, and both run through the
browser's own Web Speech support. This module sends nothing anywhere, stores no
audio and needs no API key.

Be aware of what the browser itself does, though: **Chrome and Edge do the
recognition in the cloud**, so the audio leaves the machine on its way to the
browser vendor's speech service. Firefox and Safari handle it differently, and
support varies by version and platform. If audio must not leave the machine at
all, do not switch the microphone on, and say so in your own privacy notice.

## Why this lives here

Both chat surfaces this module works with draw their chat with the same
[deep-chat](https://deepchat.dev) component, and deep-chat has had speech built
in all along. Neither surface exposes it:

- The **AI Chatbot** module's DeepChat block removes the speech configuration
  from the settings it renders, after its own `hook_deepchat_settings` has run,
  so another module cannot put it back through that hook.
- The **Drupal Canvas AI** panel is drawn by the Canvas editor's own React
  bundle, so there is no render array to configure at all.

So the settings are carried to the mounted chat element in the browser, the same
way the mode dropdown reaches those two panels. Nothing is patched.

## The Microphone tab

**Offer a microphone in the chat** is the switch. One AI Assistant can decide
otherwise for itself, on its own form (see *Per assistant* below).

**Where should the microphone sit?** Four placements, each with a small diagram:

| Placement | Where the button goes |
| --- | --- |
| Inside the message box, bottom right | In the box, one gap before the send button, on its baseline |
| Inside the message box, bottom left (the default) | In the box, clear of the attach button and of the mode dropdown |
| Outside the message box, after it | In deep-chat's own row, after the box |
| Outside the message box, before it | In that row, before the box |

Left and right follow the writing direction, so a right-to-left panel mirrors them
without a second setting. The two inside placements line the microphone up with
whichever control already holds that corner, and the send button only appears
once there is something to send, so the microphone is measured against it each
time rather than pinned to a guessed offset.

**Dictation language** is a list, and two entries are not languages:

- **Let the browser choose** says nothing, and the browser uses its own default.
- **Follow the page language** listens in whatever language the page is in, which
  is usually what a multilingual site wants.

Anything else is a BCP 47 tag such as `en-US` or `en-GB`. Which languages a
browser can actually recognise is the browser's business, so the list is a
working shortlist rather than a promise.

The rest map one to one onto deep-chat's
[speechToText](https://deepchat.dev/docs/speech#speechToText) options:

| Setting | What it does |
| --- | --- |
| Show words as they are recognised | Words appear while being spoken, instead of only when a phrase is finished |
| Stop recording once the message is sent | Off keeps listening for the next message, for hands-free dictation |
| Send the message after a pause in speaking | On by default, after four seconds: the message sends itself, so someone dictating never has to reach for the keyboard |
| Colour of words still being recognised | Any CSS colour; empty keeps the chat theme's own |
| Colour of recognised words | As above |
| Corrections | One `spoken|written` pair per line, for names the recogniser mishears |

The pause is timed by this module, which then presses the panel's own send
button. deep-chat can time it itself, but only for a chat that submits through
deep-chat: the Drupal Canvas AI panel wires its own handler to that button, so
deep-chat's internal submit never reaches the agent and nothing would be sent.
Pressing the button the surface owns works the same way on both panels.

Three details of that button are worth knowing, because each one silently
swallowed a dictated message until it was handled:

- The button's class differs per panel. The AI Chatbot panel gives it a
  `submit-button` class; the Canvas AI panel calls it `.inside-end` and gives it
  no `submit-button` class at all, so both names are looked for.
- The Canvas AI panel keeps the button hidden until there is something to send,
  so it is pressed whether or not it measures anything on screen, and Enter is
  dispatched afterwards for a chat that submits through deep-chat itself. The
  send button and the mode dropdown stay exactly as they behave without the
  microphone: hidden while the message box is empty, shown once it is not.
- Chromium ends recognition on its own after a couple of seconds of quiet, which
  can arrive before a longer configured pause. A stop with a send already
  pending therefore sends the message rather than discarding it.

### Voice commands

Inside the same tab. Each phrase is a spoken instruction rather than dictation:
stop listening, pause, resume, clear the message box, send the message, and
listen for commands only. Leave a phrase empty and it is not listened for. Two
switches decide how a phrase is matched: whether it counts inside a longer
sentence, and whether case has to match.

## The Reading replies aloud tab

**Read replies aloud** is the switch, and it too can be decided per assistant.
The rest map onto deep-chat's
[textToSpeech](https://deepchat.dev/docs/speech#textToSpeech) options: the
reading language (the same list as above), the **voice**, and **pitch**, **speed**
and **volume**. A value left at 1 is not sent at all, so the browser's own
neutral voice is used.

The voice list is filled in by the browser you are looking at the form with,
because the installed voices differ by machine, so it shows what that browser
has. Each entry says whether the voice speaks **on this device** or is an
**online voice**, which matters: an online voice is synthesised on the browser
vendor's servers, so the reply text is sent there. A voice that turns out not to
be installed elsewhere falls back to the default voice, and the browser only
speaks while its window is focused, which is the browser's rule, not this
module's.

## Per assistant

An AI Assistant's own form (**Configuration** > **AI** > **AI Assistants**)
carries three overrides: the microphone, where it sits, and reading replies
aloud.

These apply to **that assistant's own chatbot panel only**. The Drupal Canvas AI
panel is driven by the `canvas_ai_orchestrator` agent and has no AI Assistant
behind it, so it always follows the site settings: an assistant that switches its
microphone off does not switch it off in Canvas. Each is set to *use the site setting* by default, and choosing that stores
nothing at all, so an assistant that never overrode anything stays clean when its
configuration is exported.

This is how you offer a microphone on an assistant people use while doing
something else, and leave it off an assistant used in a shared room.

## What is deliberately not offered

Three parts of deep-chat's speech API are not on this form:

- **Azure Cognitive Services** for recognition, because it needs a subscription
  key or a token, and a key does not belong in configuration.
- **The microphone button's own styles** (`speechToText.button`), because this
  module sizes and places the button itself so it matches whichever chat it lands
  in.
- **The speech events** (`onStart`, `onResult` and the rest), because they are
  JavaScript callbacks and there is nothing useful to store for them in
  configuration.

All three are reachable from your own code through the same alter hook:

```php
/**
 * Implements hook_ai_agent_modes_speech_alter().
 */
function mymodule_ai_agent_modes_speech_alter(array &$settings): void {
  // Fetch a short-lived token from your own code, never a key in config.
  $settings['speechToTextConfig']['azure'] = [
    'region' => getenv('AZURE_SPEECH_REGION'),
    'token' => mymodule_speech_token(),
  ];
}
```

Bear in mind that anything added here reaches the browser, so use a short-lived
token rather than a subscription key.

## If nothing happens

- **No microphone button.** The switch is off, or the assistant overrode it, or
  the browser has no Web Speech support. deep-chat shows an unavailable button in
  the last case.
- **The button is there but nothing is transcribed.** The browser asks for
  permission the first time. If it was denied, it has to be re-allowed in the
  browser's own site settings.
- **Nothing is read aloud.** The window has to be focused, and some browsers only
  speak after the person has interacted with the page.
- **A reply is read in the wrong voice or language.** The chosen voice is not
  installed, so the default voice answered instead.
