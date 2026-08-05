/**
 * @file
 * Adds the AI Agent Modes dropdown to the Drupal Canvas AI chat.
 *
 * The dropdown answers one question for the person using the panel: "what do
 * you want the assistant to work on?". Where it sits is a site setting
 * (ai_agent_modes.settings:canvas_position, editable at
 * /admin/config/ai/agent-modes/settings):
 *
 * - top:          full-width row at the top of the panel, above the chat
 *                 (light DOM, before the <deep-chat> element).
 * - above_input:  its own row directly above the message box (anchored to
 *                 the input section in the shadow DOM).
 * - below_input:  its own row directly under the message box (light DOM,
 *                 after the <deep-chat> element).
 * - toolbar:      compact control in the message box toolbar, between the
 *                 upload and send buttons.
 *
 * The selection is persisted so the orchestrator request is scoped by the
 * ai_agents.request subscriber.
 */

((Drupal, drupalSettings, once) => {
  // Canvas AI drives this orchestrator agent (see canvas_ai CanvasBuilder).
  const AGENT = 'canvas_ai_orchestrator';
  const baseUrl = () =>
    (drupalSettings.path && drupalSettings.path.baseUrl) || '/';

  /**
   * Requests a CSRF token then posts the selection to the server.
   *
   * @param {string} value
   *   The selected value ('' or 'mode:<id>').
   */
  const persist = (value) => {
    const base = baseUrl();
    fetch(`${base}session/token`)
      .then((response) => response.text())
      .then((token) =>
        fetch(`${base}ai-agent-modes/selection`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
          body: JSON.stringify({ agent: AGENT, value, conversation: '' }),
        }),
      )
      .catch(() => {});
  };

  /**
   * Builds the dropdown from the fetched options.
   *
   * @param {object} data
   *   The options payload {value, options:[{value,label,group}]}.
   * @param {boolean} compact
   *   Whether to render the small toolbar variant.
   *
   * @return {HTMLElement}
   *   The wrapper element containing the select.
   */
  const buildSelect = (data, compact) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'ai-agent-modes-canvas';

    const select = document.createElement('select');
    select.className = 'ai-agent-modes-canvas__select';
    select.style.cssText = compact
      ? 'width:100%;height:24px;font-size:12px;line-height:1;padding:0 20px 0 6px;' +
        'border:1px solid rgba(0,0,0,0.15);border-radius:6px;background:#fff;' +
        'color:#333;cursor:pointer;'
      : 'display:block;width:100%;height:32px;font-size:13px;line-height:1.2;' +
        'padding:0 28px 0 10px;border:1px solid rgba(0,0,0,0.15);' +
        'border-radius:8px;background:#fff;color:#333;cursor:pointer;';
    select.setAttribute(
      'aria-label',
      Drupal.t('What should the assistant work on?'),
    );
    select.title = Drupal.t('Choose what you want the assistant to work on.');

    const groups = {};
    data.options.forEach((option) => {
      let parent = select;
      if (option.group) {
        if (!groups[option.group]) {
          groups[option.group] = document.createElement('optgroup');
          groups[option.group].label = option.group;
          select.appendChild(groups[option.group]);
        }
        parent = groups[option.group];
      }
      const el = document.createElement('option');
      el.value = option.value;
      el.textContent = option.label;
      if (option.value === data.value) {
        el.selected = true;
      }
      parent.appendChild(el);
    });

    select.addEventListener('change', (event) => persist(event.target.value));
    wrapper.appendChild(select);
    return wrapper;
  };

  /**
   * Places the dropdown for one chat according to the configured position.
   *
   * @param {HTMLElement} chat
   *   The <deep-chat> element.
   * @param {object} data
   *   The options payload, including the configured position.
   *
   * @return {boolean}
   *   TRUE when placed, FALSE when the target is not available (yet).
   */
  const place = (chat, data) => {
    const shadow = chat.shadowRoot;
    const position = data.position || 'toolbar';

    if (position === 'top' || position === 'below_input') {
      const parent = chat.parentElement;
      if (!parent) {
        return false;
      }
      const wrapper = buildSelect(data, false);
      if (position === 'top') {
        wrapper.style.cssText = 'display:block;margin:0 0 8px;';
        parent.insertBefore(wrapper, chat);
      } else {
        // The message box is the chat's last visual element, so a light-DOM
        // sibling right after <deep-chat> sits directly under it. The
        // negative margin cancels the panel's 16px flex gap plus the chat's
        // ~12px empty strip under the box, leaving a small 8px gap.
        wrapper.style.cssText = 'display:block;margin:-20px 0 0;';
        parent.insertBefore(wrapper, chat.nextSibling);
      }
      return true;
    }

    // The shadow-DOM anatomy this relies on: the chat column (.chat-view) is
    // a CSS *grid*, so nothing may be inserted into it as a child (a new
    // child becomes its own stretched grid row). #input (position:relative)
    // holds the message box #text-input-container (position:relative), whose
    // bottom 40px (its padding-bottom) is the button strip with the upload
    // (inside-start) and send (inside-end) buttons. All placements below are
    // therefore absolutely anchored to one of those two relative containers.
    const input = shadow && shadow.getElementById('input');
    const box = shadow && shadow.getElementById('text-input-container');
    if (position === 'above_input') {
      if (!input) {
        return false;
      }
      const wrapper = buildSelect(data, false);
      wrapper.style.cssText =
        'position:absolute;left:0;right:0;bottom:calc(100% - 4px);z-index:5;';
      input.appendChild(wrapper);
      return true;
    }
    // toolbar: compact, absolutely positioned in the button strip between the
    // upload button (inside-start) and the send button (inside-end, which
    // sits at right: calc(10% + 5px)).
    if (!box) {
      return false;
    }
    const wrapper = buildSelect(data, true);
    wrapper.style.cssText =
      'position:absolute;left:44px;right:calc(10% + 35px);bottom:9px;' +
      'display:none;align-items:center;z-index:5;';
    box.appendChild(wrapper);
    // Only show the compact control while the send button is visible
    // (deep-chat hides/collapses it until there is something to send), and
    // fit it to the live button geometry: the same gap on both sides as the
    // upload-to-control gap, vertically in line with the buttons. Dictation
    // does not depend on that button being on screen: speech.js presses it
    // either way and falls back to Enter.
    const GAP = 8;
    // Declared before the closure that references it, so the interval can be
    // cleared from inside its own callback once the wrapper is detached.
    let syncTimer;
    const syncWithSendButton = () => {
      if (!wrapper.isConnected) {
        window.clearInterval(syncTimer);
        return;
      }
      const send = shadow.querySelector('.inside-end');
      const upload = shadow.querySelector('.inside-start');
      const visible = !!send && send.getBoundingClientRect().width > 0;
      if (!visible) {
        wrapper.style.display = 'none';
        return;
      }
      const boxRect = box.getBoundingClientRect();
      const sendRect = send.getBoundingClientRect();
      const uploadRect = upload ? upload.getBoundingClientRect() : null;
      wrapper.style.left = uploadRect
        ? `${Math.round(uploadRect.right - boxRect.left) + GAP}px`
        : '44px';
      wrapper.style.right = `${Math.round(boxRect.right - sendRect.left) + GAP}px`;
      // Same bottom offset and height as the buttons, so the control lines
      // up exactly with them. The absolute offsets are relative to the box's
      // padding edge while the rects include its border, so subtract it.
      const anchor = uploadRect || sendRect;
      const border = parseFloat(getComputedStyle(box).borderBottomWidth) || 0;
      wrapper.style.bottom = `${boxRect.bottom - anchor.bottom - border}px`;
      wrapper.style.height = `${anchor.height}px`;
      wrapper.style.display = 'flex';
    };
    syncTimer = window.setInterval(syncWithSendButton, 400);
    syncWithSendButton();
    return true;
  };

  /**
   * Adds the dropdown to a deep-chat element if it is not there already.
   *
   * @param {HTMLElement} chat
   *   The <deep-chat> element.
   */
  const inject = (chat) => {
    const already =
      (chat.parentElement &&
        chat.parentElement.querySelector('.ai-agent-modes-canvas')) ||
      (chat.shadowRoot &&
        chat.shadowRoot.querySelector('.ai-agent-modes-canvas'));
    if (already || chat.dataset.aiAgentModesLoading) {
      return;
    }
    chat.dataset.aiAgentModesLoading = '1';
    fetch(`${baseUrl()}ai-agent-modes/options/${AGENT}`, {
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((data) => {
        delete chat.dataset.aiAgentModesLoading;
        // Only inject when there is more than the "anything" option to offer.
        if (!data || !Array.isArray(data.options) || data.options.length <= 1) {
          return;
        }
        place(chat, data);
      })
      .catch(() => {
        delete chat.dataset.aiAgentModesLoading;
      });
  };

  /**
   * Scans the document for Canvas AI chats and adds the dropdown to each.
   */
  const scan = () => {
    document.querySelectorAll('deep-chat').forEach((chat) => inject(chat));
  };

  /**
   * Polls for the Canvas AI panel to mount and injects the dropdown.
   *
   * The panel is a React island mounted after page load, so a low-frequency
   * poll is used. inject() is idempotent, so it also re-injects when the
   * panel is closed and reopened.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.aiAgentModesCanvas = {
    attach(context) {
      once('ai-agent-modes-canvas', 'body', context).forEach(() => {
        window.setInterval(scan, 800);
        scan();
      });
    },
  };
})(Drupal, drupalSettings, once);
