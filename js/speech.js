/**
 * @file
 * Applies the configured deep-chat speech settings to the assistant chat.
 *
 * Both chat surfaces this module works with (the AI Chatbot DeepChat block and
 * the Drupal Canvas AI panel) draw their chat as a deep-chat web component. The
 * AI Chatbot module removes the speech configuration before the element is
 * rendered, and the Canvas panel is built by the Canvas editor's React bundle
 * where there is no render array to configure at all, so in both cases the
 * properties are set here, client-side, on the mounted element:
 *
 * - speechToText  - the microphone, dictating into the message box.
 * - textToSpeech  - reading each reply aloud.
 *
 * The payloads are built in PHP (see SpeechHooks) and applied as they are, with
 * one exception: a language of "site" can only be resolved here, from the page
 * the chat is actually on.
 *
 * Nothing is sent to a service of ours: both directions use the browser's own Web
 * Speech support, and a browser without it shows a struck-through button that
 * deep-chat marks as unsupported. The browser itself may well use the network,
 * which is why the settings form says so rather than promising otherwise.
 *
 * Where the microphone sits is a site setting
 * (ai_agent_modes.settings:speech_to_text.position), which one AI Assistant may
 * override:
 *
 * - input_end:    inside the message box, at the end of its bottom row, one gap
 *                 before the send button.
 * - input_start:  inside the message box, at the start of its bottom row, which
 *                 is the default.
 * - outside_end:  deep-chat's own row, after the message box.
 * - outside_start: deep-chat's own row, before the message box.
 *
 * "start" and "end" follow the writing direction, so a right-to-left panel mirrors.
 */

((Drupal, drupalSettings) => {
  /**
   * How far the microphone stays clear of the send button, in pixels.
   */
  const GAP = 6;

  /**
   * How narrow this module's own dropdown may be squeezed, in pixels.
   *
   * Below this a mode name cannot be read in it, and the microphone is better
   * off outside the message box than beside a useless control.
   */
  const MIN_DROPDOWN_WIDTH = 90;

  /**
   * How far in from the box's own edge the microphone sits, in pixels.
   */
  const PAD = 9;

  /**
   * How tall the box's bottom row is taken to be, in pixels.
   *
   * Anything whose foot is inside this strip is in the microphone's way; anything
   * higher up in the box is not.
   */
  const ROW_HEIGHT = 48;

  /**
   * Resolves a configured language into what Web Speech expects.
   *
   * "browser" means say nothing and let the browser decide. "site" means follow
   * the page, which is why this cannot be resolved in PHP for the Canvas editor:
   * the same built page is served whatever the content language turns out to be.
   * Anything else is already a BCP 47 tag.
   *
   * @param {string} language
   *   The configured value.
   *
   * @return {string}
   *   A BCP 47 tag, or an empty string to leave the choice to the browser.
   */
  const resolveLanguage = (language) => {
    if (!language || language === 'browser') {
      return '';
    }
    if (language !== 'site') {
      return language;
    }
    return (
      document.documentElement.lang ||
      (drupalSettings.path && drupalSettings.path.currentLanguage) ||
      ''
    );
  };

  /**
   * Cancels a pending send for one chat.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element.
   */
  const cancelSend = (chat) => {
    if (chat.aiAgentModesSendTimer) {
      window.clearTimeout(chat.aiAgentModesSendTimer);
      chat.aiAgentModesSendTimer = null;
    }
  };

  /**
   * Finds the button that sends the message in one shadow root.
   *
   * The class differs per deep-chat build and per surface: the AI Chatbot panel
   * carries a submit-button class, while the Drupal Canvas AI panel names the
   * same control .inside-end and gives it no submit-button class at all. Looking
   * for one name only is what left dictation in Canvas typing the words and
   * never sending them.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   *
   * @return {HTMLElement|null}
   *   The send button, or NULL when this chat has none.
   */
  const findSend = (root) =>
    root.querySelector('.submit-button, [class*="submit-button"]') ||
    root.querySelector('.input-button.inside-end') ||
    root.querySelector('.inside-end');

  /**
   * Sends whatever has been dictated into one chat, straight away.
   *
   * deep-chat can send after a pause itself, but only for a chat that submits
   * through deep-chat: the Drupal Canvas AI panel wires its own handler to the
   * send button, so deep-chat's internal submit never reaches the agent and
   * nothing is sent. Pressing the button the surface itself owns works on both
   * surfaces, which is why the pause is timed here and deep-chat's own
   * submitAfterSilence is left off.
   *
   * The button is pressed even when it measures zero: deep-chat keeps the send
   * button collapsed and disabled-looking until it registers input of its own,
   * and dictation does not always count, so waiting for a visible button meant
   * waiting forever. An input event is fired first to give deep-chat the chance
   * to catch up, and Enter is dispatched afterwards as a fallback for a chat
   * that submits through deep-chat itself rather than through the button.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element.
   */
  const sendNow = (chat) => {
    const root = chat.shadowRoot;
    if (!root) {
      return;
    }
    const field = root.querySelector('#text-input');
    // Nothing was dictated after all, so there is nothing to send.
    if (!field || (field.textContent || '').trim() === '') {
      return;
    }
    // Let deep-chat see the dictated text as input, so it enables its own
    // send button before that button is pressed.
    field.dispatchEvent(new Event('input', { bubbles: true }));
    const submit = findSend(root);
    if (submit) {
      submit.click();
    }
    // The button either sent the message and deep-chat cleared the box, or
    // nothing happened and the words are still sitting there. Only in the
    // second case is Enter worth trying.
    window.setTimeout(() => {
      if ((field.textContent || '').trim() === '') {
        return;
      }
      ['keydown', 'keypress', 'keyup'].forEach((type) => {
        field.dispatchEvent(
          new KeyboardEvent(type, {
            key: 'Enter',
            code: 'Enter',
            keyCode: 13,
            which: 13,
            bubbles: true,
            cancelable: true,
            composed: true,
          }),
        );
      });
    }, 300);
  };

  /**
   * Sends the dictated message once the speaking has stopped for long enough.
   *
   * Every recognised fragment restarts the wait, so this is a pause in speaking
   * rather than a deadline from the first word.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element.
   * @param {number} pause
   *   How long to wait after the last recognised words, in milliseconds.
   */
  const scheduleSend = (chat, pause) => {
    cancelSend(chat);
    chat.aiAgentModesSendTimer = window.setTimeout(() => {
      chat.aiAgentModesSendTimer = null;
      sendNow(chat);
    }, pause);
  };

  /**
   * Builds the speechToText value.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element the value is for.
   * @param {object} settings
   *   The aiAgentModesSpeech drupalSettings entry.
   *
   * @return {object}
   *   The value to hand to deep-chat.
   */
  const speechToTextValue = (chat, settings) => {
    const config = { ...(settings.speechToTextConfig || {}) };
    const pause = config.submitAfterSilence;
    // Timed here instead, so the message is sent on both surfaces.
    delete config.submitAfterSilence;
    const language = resolveLanguage(settings.speechToTextLanguage);
    config.webSpeech = language === '' ? true : { language };

    if (pause) {
      const milliseconds = pause === true ? 2000 : pause;
      config.events = {
        // Every recognised fragment, interim ones included, restarts the wait:
        // that is what makes it a pause in speaking rather than a deadline.
        onResult: () => scheduleSend(chat, milliseconds),
        // Chrome ends recognition on its own after a couple of seconds of
        // quiet, which is sooner than a longer configured pause. Cancelling
        // here meant the wait never finished and the dictated words simply sat
        // in the box, so a stop with a send already pending sends it instead.
        onStop: () => {
          if (!chat.aiAgentModesSendTimer) {
            return;
          }
          cancelSend(chat);
          sendNow(chat);
        },
      };
    }
    return config;
  };

  /**
   * Builds the textToSpeech value.
   *
   * @param {object} settings
   *   The aiAgentModesSpeech drupalSettings entry.
   *
   * @return {object}
   *   The value to hand to deep-chat.
   */
  const textToSpeechValue = (settings) => {
    const config = { ...(settings.textToSpeechConfig || {}) };
    const language = resolveLanguage(settings.textToSpeechLanguage);
    if (language !== '') {
      config.lang = language;
    }
    return config;
  };

  /**
   * Sets the speech properties on one chat element, once.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element.
   * @param {object} settings
   *   The aiAgentModesSpeech drupalSettings entry.
   */
  const enable = (chat, settings) => {
    if (chat.dataset.aiAgentModesSpeech) {
      return;
    }
    chat.dataset.aiAgentModesSpeech = '1';
    // Properties, not attributes: deep-chat reads objects from properties, and
    // the element is already mounted by the time this runs.
    if (settings.speechToText) {
      chat.speechToText = speechToTextValue(chat, settings);
    }
    if (settings.textToSpeech) {
      chat.textToSpeech = textToSpeechValue(settings);
    }
  };

  /**
   * Adds the microphone's styles to one chat's shadow root, once.
   *
   * A stylesheet rather than inline styles, so the hover and the recording state
   * can be styled too. It holds only what is the same in every deep-chat style:
   * the size, the corner radius and the offsets are measured from the panel's own
   * buttons instead (see matchNeighbour and freeSlot), so the microphone
   * looks like it belongs beside the attach and send buttons wherever it lands.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   */
  const addStyles = (root) => {
    if (root.querySelector('style[data-ai-agent-modes-microphone]')) {
      return;
    }
    const style = document.createElement('style');
    style.setAttribute('data-ai-agent-modes-microphone', '1');
    style.textContent = `
      .ai-agent-modes-microphone {
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        background-color: transparent;
        cursor: pointer;
        transition: background-color 0.15s ease;
      }
      .ai-agent-modes-microphone:hover {
        background-color: #e3e3ea;
      }
      /* Inside the message box the button is pinned to one bottom corner. The
         offsets themselves are written inline, and marked important there so they
         beat deep-chat's own placement classes: a stylesheet cannot hold them,
         because an !important rule here would in turn beat the inline offsets and
         the alignment could never move the button. */
      .ai-agent-modes-microphone--inside {
        position: absolute !important;
        z-index: 5;
        margin: 0 !important;
        padding: 0 !important;
      }
      /* Outside the box the button is a plain item in deep-chat's own row, so it
         is aligned by that row rather than pinned. */
      .ai-agent-modes-microphone--outside {
        position: static !important;
        inset: auto !important;
        margin: 0 4px !important;
        flex: 0 0 auto;
        align-self: center;
      }
      .ai-agent-modes-microphone .default-microphone-icon path {
        fill: #55565b;
      }
      /* deep-chat swaps in this icon while it is listening. */
      .ai-agent-modes-microphone .active-microphone-icon path {
        fill: #d92d20;
      }
      .ai-agent-modes-microphone .unsupported-microphone-icon path {
        fill: #b0b0b8;
      }
    `;
    root.appendChild(style);
  };

  /**
   * Finds the microphone button in one shadow root.
   *
   * The icon is the reliable landmark: its id is stable across deep-chat styles,
   * while the button wrapper's class is not, so the button is reached from the
   * icon outwards.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   *
   * @return {HTMLElement|null}
   *   The button, or NULL when this chat has no microphone.
   */
  /**
   * Names the microphone so a screen reader can announce it.
   *
   * The button exists only because this module asked deep-chat for dictation,
   * and deep-chat draws it as an icon in a div with role="button" and no
   * accessible name, so naming it belongs here. It is deliberately separate
   * from placing it: the AI Chatbot panel never reports a message box wide
   * enough to place against, and a button that cannot be placed still has to be
   * read out. A name the chat set itself is left alone.
   *
   * @param {HTMLElement} button
   *   The microphone button.
   *
   * @return {boolean}
   *   TRUE when something was written.
   */
  const nameButton = (button) => {
    let wrote = false;
    if (
      !button.getAttribute('aria-label') &&
      !button.getAttribute('aria-labelledby')
    ) {
      button.setAttribute('aria-label', Drupal.t('Dictate your message'));
      wrote = true;
    }
    if (!button.title) {
      button.title = Drupal.t('Dictate your message instead of typing it.');
      wrote = true;
    }
    return wrote;
  };

  const findButton = (root) => {
    const icon = root.querySelector(
      '#microphone-icon, .default-microphone-icon, [id*="microphone"], [class*="microphone"]',
    );
    if (!icon) {
      return null;
    }
    return (
      icon.closest('.input-button') ||
      icon.closest('button') ||
      icon.parentElement
    );
  };

  /**
   * Finds the message box in one shadow root.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   *
   * @return {HTMLElement|null}
   *   The message box container, or NULL when the style does not have one.
   */
  const findInput = (root) =>
    root.querySelector('#text-input-container') ||
    root.querySelector('.text-input-container') ||
    root.querySelector('[class*="text-input"]');

  /**
   * Sizes the microphone like the button next to it.
   *
   * deep-chat's styles do not agree on how big an input button is: the AI Chatbot
   * panel draws 24px buttons, the Canvas AI panel 34px ones, and a custom style
   * may draw something else again. Rather than pick a number, the microphone is
   * measured against the button beside it, so the row reads as one set of
   * controls in whichever style it lands in.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   * @param {HTMLElement} button
   *   The microphone button.
   *
   * @return {boolean}
   *   TRUE when a size was written.
   */
  const matchNeighbour = (root, button) => {
    // A button, not the row or container that holds one: deep-chat's class names
    // are near enough alike that a loose match picks up a 232px wide container
    // and the microphone is sized to that. So a candidate has to be square and
    // the size of a control, and must not contain another one.
    const isControl = (candidate) => {
      if (candidate === button || candidate.contains(button)) {
        return false;
      }
      if (candidate.querySelector('.input-button, button')) {
        return false;
      }
      const box = candidate.getBoundingClientRect();
      return (
        box.height >= 12 &&
        box.height <= 48 &&
        Math.abs(box.width - box.height) <= 6
      );
    };
    // The send button first: it is the one the microphone lines up with, so
    // matching its size is what makes the pair read as a set.
    const submit = root.querySelector(
      '.submit-button, [class*="submit-button"]',
    );
    const neighbour =
      submit && isControl(submit)
        ? submit
        : [...root.querySelectorAll('.input-button, button')].find(isControl);
    if (!neighbour) {
      return false;
    }
    const box = neighbour.getBoundingClientRect();
    const size = `${Math.round(box.height)}px`;
    if (box.height === 0 || button.style.getPropertyValue('height') === size) {
      return false;
    }
    button.style.setProperty('width', size, 'important');
    button.style.setProperty('height', size, 'important');
    const radius = getComputedStyle(neighbour).borderRadius;
    if (radius && radius !== '0px') {
      button.style.setProperty('border-radius', radius, 'important');
    }
    // The icon is scaled with the button rather than left at one size, or a 34px
    // button ends up with a 16px icon rattling around inside it.
    const svg = button.querySelector('svg');
    if (svg) {
      const icon = `${Math.min(20, Math.round(box.height * 0.6))}px`;
      svg.style.setProperty('width', icon, 'important');
      svg.style.setProperty('height', icon, 'important');
    }
    return true;
  };

  /**
   * Whether an element in the row is this module's own dropdown.
   *
   * @param {HTMLElement} element
   *   The element.
   *
   * @return {boolean}
   *   TRUE when it is the mode dropdown.
   */
  const isOurs = (element) =>
    element.classList.contains('ai-agent-modes-canvas') ||
    element.classList.contains('ai-agent-modes-chatbot');

  /**
   * Lists what already sits in the message box's bottom row.
   *
   * Positions are read as offsets inside the message box rather than as screen
   * rectangles: deep-chat positions its own buttons absolutely inside that box,
   * so their offsetLeft and offsetTop are already in the very coordinate space
   * the microphone is placed in. Measuring screen rectangles and nudging towards
   * them, which this used to do, could not converge - each nudge moved the target
   * - and walked the button off the panel.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   * @param {HTMLElement} button
   *   The microphone button, which is never its own neighbour.
   * @param {HTMLElement} input
   *   The message box.
   *
   * @return {Array<{left: number, right: number, top: number, height: number}>}
   *   The occupants, left to right, in the box's own coordinates.
   */
  const rowOccupants = (root, button, input) => {
    // The mode dropdown is not a button, so it is named: it is the one thing in
    // this row that this module puts there itself.
    const selector =
      '.input-button, button, .ai-agent-modes-canvas, .ai-agent-modes-chatbot';
    const base = input.getBoundingClientRect();
    // The origin of the space an absolutely positioned child is placed in: the
    // box's padding edge. Everything below is converted into that space, rather
    // than trusting offsetParent, which is not the message box for deep-chat's
    // own buttons.
    const originX = base.left + input.clientLeft;
    const originY = base.top + input.clientTop;
    const occupants = [];
    root.querySelectorAll(selector).forEach((candidate) => {
      if (candidate === button || candidate.contains(button)) {
        return;
      }
      const rect = candidate.getBoundingClientRect();
      if (rect.width === 0 || rect.height === 0) {
        return;
      }
      // Inside the box, and in its bottom strip rather than anywhere above it.
      if (rect.left < base.left - 2 || rect.right > base.right + 2) {
        return;
      }
      if (rect.bottom < base.bottom - ROW_HEIGHT) {
        return;
      }
      occupants.push({
        element: candidate,
        left: rect.left - originX,
        right: rect.right - originX,
        top: rect.top - originY,
        height: rect.height,
      });
    });
    return occupants.sort((a, b) => a.left - b.left);
  };

  /**
   * Works out where in the row the microphone can sit.
   *
   * Walks in from the chosen side past everything in the way. The mode dropdown
   * is wide, so stopping at the first thing found is not enough, and each item
   * passed can reveal another behind it.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   * @param {HTMLElement} button
   *   The microphone button.
   * @param {HTMLElement} input
   *   The message box.
   * @param {boolean} fromRight
   *   Whether to walk in from the right-hand side of the row.
   * @param {boolean} ignoreOurs
   *   Whether to ignore this module's own dropdown while walking, because it is
   *   moved aside instead.
   *
   * @return {?{left: number, top: number}}
   *   Where to put the button, in the box's own coordinates, or NULL when the
   *   row has no room for it.
   */
  const freeSlot = (root, button, input, fromRight, ignoreOurs) => {
    const width = button.offsetWidth || 24;
    const height = button.offsetHeight || 24;
    const inner = input.clientWidth;
    const all = rowOccupants(root, button, input);
    // A microphone asked for one side stays on that side: our own dropdown is
    // asked to move over instead of being walked around, which would carry the
    // button across the row the moment a dropdown or a send button appeared.
    const occupants = ignoreOurs ? all.filter((o) => !isOurs(o.element)) : all;
    // The row to line up with is the one the other controls sit on.
    const anchor = fromRight ? occupants[occupants.length - 1] : occupants[0];
    const top = anchor
      ? anchor.top + Math.round((anchor.height - height) / 2)
      : input.clientHeight - PAD - height;

    let left;
    if (fromRight) {
      left = inner - PAD - width;
      [...occupants].reverse().forEach((occupant) => {
        if (left < occupant.right + GAP) {
          left = occupant.left - GAP - width;
        }
      });
    } else {
      left = PAD;
      occupants.forEach((occupant) => {
        if (left + width + GAP > occupant.left) {
          left = occupant.right + GAP;
        }
      });
    }
    return left < PAD || left + width > inner - PAD ? null : { left, top };
  };

  /**
   * Moves this module's own dropdown out of the microphone's slot.
   *
   * The microphone keeps the side it was asked for, so the dropdown is what
   * gives way. Which way it gives depends on the side: at the start of the row
   * the dropdown has to begin after the button, and at the end it has to stop
   * before it. Computing one and using it for both is what put the button behind
   * the dropdown, out of sight and out of reach.
   *
   * @param {ShadowRoot} root
   *   The chat's shadow root.
   * @param {HTMLElement} button
   *   The microphone button.
   * @param {HTMLElement} input
   *   The message box.
   * @param {{left: number}} slot
   *   Where the microphone is going, in the box's own coordinates.
   * @param {boolean} fromRight
   *   Whether the microphone sits at the right-hand end of the row.
   *
   * @return {boolean}
   *   TRUE when the dropdown was moved or narrowed.
   */
  const clearOurDropdown = (root, button, input, slot, fromRight) => {
    const dropdown = rowOccupants(root, button, input).find((occupant) =>
      isOurs(occupant.element),
    );
    if (!dropdown) {
      return false;
    }
    const width = button.offsetWidth || 24;
    const micLeft = slot.left;
    const micRight = slot.left + width;
    // Already clear of the slot, with a gap either side.
    if (dropdown.right + GAP <= micLeft || dropdown.left >= micRight + GAP) {
      return false;
    }

    const element = dropdown.element;
    const current = element.offsetWidth;
    if (fromRight) {
      // Give up width from the dropdown's own end, so it stops before the
      // button instead of running underneath it.
      const target = Math.round(current - (dropdown.right - (micLeft - GAP)));
      if (target < MIN_DROPDOWN_WIDTH) {
        return false;
      }
      if (element.style.getPropertyValue('max-width') === `${target}px`) {
        return false;
      }
      element.style.setProperty('max-width', `${target}px`, 'important');
      return true;
    }

    // Start of the row: the dropdown begins after the button, and gives up
    // exactly the width it moved so the row still fits.
    const shift = Math.round(micRight + GAP - dropdown.left);
    const margin = Math.round(
      (parseFloat(element.style.marginInlineStart) || 0) + shift,
    );
    const target = Math.round(current - shift);
    if (target < MIN_DROPDOWN_WIDTH) {
      return false;
    }
    const settled =
      element.style.getPropertyValue('margin-inline-start') === `${margin}px` &&
      element.style.getPropertyValue('max-width') === `${target}px`;
    if (settled) {
      return false;
    }
    element.style.setProperty(
      'margin-inline-start',
      `${margin}px`,
      'important',
    );
    element.style.setProperty('max-width', `${target}px`, 'important');
    return true;
  };

  /**
   * Puts the button at one exact place inside the message box.
   *
   * @param {HTMLElement} button
   *   The microphone button.
   * @param {{left: number, top: number}} slot
   *   Where it goes, in the box's own coordinates.
   *
   * @return {boolean}
   *   TRUE when anything changed.
   */
  const placeAt = (button, slot) => {
    const left = `${Math.round(slot.left)}px`;
    const top = `${Math.round(slot.top)}px`;
    if (
      button.style.getPropertyValue('left') === left &&
      button.style.getPropertyValue('top') === top
    ) {
      return false;
    }
    // deep-chat places its own buttons with the opposite edges, so those are
    // cleared or the two pull the button apart.
    button.style.setProperty('right', 'auto', 'important');
    button.style.setProperty('bottom', 'auto', 'important');
    button.style.setProperty('left', left, 'important');
    button.style.setProperty('top', top, 'important');
    return true;
  };

  /**
   * Moves and pins the microphone according to the configured position.
   *
   * deep-chat renders the button in its own row at the end, where the narrow
   * Canvas AI panel clips it. Its speechToText.button configuration only accepts
   * styles in this version and ignores a container position, so the button is
   * moved in the shadow DOM instead. Moving a node keeps its event listeners, so
   * the button still records.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element.
   * @param {ShadowRoot} root
   *   Its shadow root.
   * @param {string} position
   *   input_end, input_start, outside_end or outside_start.
   *
   * @return {boolean}
   *   TRUE when something was actually changed. A pass that finds everything
   *   already in place reports FALSE, which is what keeps the observer from
   *   feeding itself.
   */
  const applyPlacement = (chat, root, position) => {
    const button = findButton(root);
    const input = findInput(root);
    // A collapsed panel has no layout to measure: the chatbot folds itself away
    // after sending, and deciding anything from a zero-width box would move the
    // button out of a box it is about to be welcome in again.
    if (input && input.clientWidth < 60) {
      return false;
    }
    if (!button || !input) {
      // Either the chat has not drawn its microphone yet, or this style has no
      // message box to pin to. Nothing to do, and nothing to count: deep-chat's
      // own placement is left alone rather than moved somewhere worse.
      return false;
    }
    const rtl = getComputedStyle(chat).direction === 'rtl';
    const atEnd = position === 'input_end' || position === 'outside_end';
    // Which physical side that is: in a right-to-left panel the end is the left.
    const fromRight = atEnd !== rtl;
    let inside = position === 'input_end' || position === 'input_start';
    let wroteRoom = false;
    // The side that was chosen is the side the button keeps: our own dropdown is
    // moved aside rather than the button being walked around it.
    const keepSide = true;
    // Where in the row the microphone can go without covering anything. A row
    // that is already full leaves no honest slot: the mode dropdown then gives
    // up the width of one button, and only if it cannot does the microphone go
    // outside the box rather than sit on top of something.
    let slot = null;
    if (inside) {
      // The button has to be in the box before its place in it can be worked
      // out, because the offsets are read inside that box.
      if (button.parentElement !== input) {
        input.appendChild(button);
        wroteRoom = true;
      }
      if (getComputedStyle(input).position === 'static') {
        input.style.position = 'relative';
        wroteRoom = true;
      }
      slot = freeSlot(root, button, input, fromRight, keepSide);
      if (
        slot !== null &&
        clearOurDropdown(root, button, input, slot, fromRight)
      ) {
        wroteRoom = true;
      }
      if (slot === null) {
        inside = false;
      }
    }
    let wrote = wroteRoom;

    if (!button.classList.contains('ai-agent-modes-microphone')) {
      button.classList.add('ai-agent-modes-microphone');
      wrote = true;
    }
    wrote = nameButton(button) || wrote;
    const state = inside
      ? 'ai-agent-modes-microphone--inside'
      : 'ai-agent-modes-microphone--outside';
    if (!button.classList.contains(state)) {
      button.classList.remove(
        'ai-agent-modes-microphone--inside',
        'ai-agent-modes-microphone--outside',
      );
      button.classList.add(state);
      wrote = true;
    }
    addStyles(root);
    wrote = matchNeighbour(root, button) || wrote;

    if (inside) {
      return placeAt(button, slot) || wrote;
    }

    // Outside: a plain sibling of the message box in deep-chat's own row, which
    // is already a flex row, so the row aligns it.
    const row = input.parentElement;
    if (!row) {
      return wrote;
    }
    // Anything pinned while the button was inside has to go, or the offsets fight
    // the row it is now a plain item of.
    ['left', 'right', 'top', 'bottom'].forEach((property) => {
      if (button.style.getPropertyValue(property) !== '') {
        button.style.removeProperty(property);
        wrote = true;
      }
    });
    // Asking whether the button already sits where it belongs, rather than
    // comparing against a target that can be the button itself: that comparison
    // is never satisfied, so the button was re-inserted on every pass and only
    // the burst valve stopped it.
    const settled = atEnd
      ? input.nextSibling === button
      : button.nextSibling === input;
    if (!settled) {
      row.insertBefore(button, atEnd ? input.nextSibling : input);
      wrote = true;
    }
    return wrote;
  };

  /**
   * Applies the placement for one chat, guarded against its own writes.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element.
   * @param {string} position
   *   The configured position.
   */
  /**
   * Names one chat's microphone, wherever it sits and whatever it measures.
   *
   * @param {HTMLElement} chat
   *   The deep-chat element.
   */
  const nameMicrophone = (chat) => {
    const root = chat.shadowRoot;
    if (!root) {
      return;
    }
    const button = findButton(root);
    if (button) {
      nameButton(button);
    }
  };

  const place = (chat, position) => {
    const root = chat.shadowRoot;
    if (!root) {
      return;
    }
    // Our own style writes are attribute mutations, and attributes are what the
    // observer watches, so without this guard each pass schedules another.
    if (chat.aiAgentModesSpeechApplying) {
      return;
    }
    // A MutationObserver callback is asynchronous, so the re-entry flag alone
    // cannot stop a write-observe-write cycle. This valve caps the work: a burst
    // of writes is fine while the panel settles, but it can never spin. Only
    // passes that changed something are counted, so the many no-op passes while
    // deep-chat is still drawing cannot use the budget up.
    const now = performance.now();
    const burst = chat.aiAgentModesSpeechBurst;
    if (!burst || now - burst.since > 2000) {
      chat.aiAgentModesSpeechBurst = { since: now, count: 0 };
    }
    if (chat.aiAgentModesSpeechBurst.count > 20) {
      return;
    }
    chat.aiAgentModesSpeechApplying = true;
    try {
      if (applyPlacement(chat, root, position)) {
        chat.aiAgentModesSpeechBurst.count += 1;
      }
    } finally {
      chat.aiAgentModesSpeechApplying = false;
    }
  };

  /**
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.aiAgentModesSpeech = {
    attach() {
      const settings = drupalSettings.aiAgentModesSpeech || {};
      if (!settings.speechToText && !settings.textToSpeech) {
        return;
      }
      const position = settings.position || 'input_start';
      // The Canvas AI panel is a React island mounted after page load, thrown
      // away and rebuilt when closed and reopened, so keep watching rather than
      // looking once.
      const scan = () =>
        document.querySelectorAll('deep-chat').forEach((chat) => {
          enable(chat, settings);
          if (!settings.speechToText) {
            // Nothing to place: reading replies aloud draws no button.
            return;
          }
          // Naming comes first and does not depend on the panel's geometry: the
          // AI Chatbot panel reports no measurable message box, so placement
          // declines there, and the microphone would otherwise be left with no
          // name for a screen reader to read.
          nameMicrophone(chat);
          place(chat, position);
          // A MutationObserver on the document cannot see inside a shadow root,
          // and the microphone button is created in there, so each chat's own
          // root gets its own observer. deep-chat also re-renders its input row,
          // which is why the placement is re-applied rather than done once.
          if (chat.shadowRoot && !chat.dataset.aiAgentModesSpeechWatched) {
            chat.dataset.aiAgentModesSpeechWatched = '1';
            new MutationObserver(() => {
              nameMicrophone(chat);
              place(chat, position);
            }).observe(chat.shadowRoot, {
              childList: true,
              subtree: true,
              // The send button appears by style change once there is text to
              // send, so attributes have to be watched too, or the microphone
              // stays where it was and the two overlap.
              attributes: true,
              attributeFilter: ['style', 'class'],
            });
          }
        });
      scan();
      if (!window.aiAgentModesSpeechObserver) {
        window.aiAgentModesSpeechObserver = new MutationObserver(scan);
        window.aiAgentModesSpeechObserver.observe(document.body, {
          childList: true,
          subtree: true,
          // The AI Chatbot panel starts collapsed to a narrow rail, where there
          // is no room to place the microphone, and opening it only changes
          // classes on the panel: watching new nodes alone meant the scan never
          // ran again, leaving the microphone where deep-chat drew it.
          attributes: true,
          attributeFilter: ['class', 'style'],
        });
      }
    },
  };
})(Drupal, drupalSettings);
