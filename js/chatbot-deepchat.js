/**
 * @file
 * Adds the AI Agent Modes dropdown to the AI Chatbot's DeepChat block.
 *
 * The DeepChat block (`ai_deepchat_block`) renders its `<deep-chat>` element
 * straight into the page, outside the Form API, so it cannot be reached with
 * hook_form_alter(). The dropdown is injected into the panel's own light DOM,
 * and the selection is persisted through the same endpoint the Canvas AI panel
 * uses.
 *
 * Where it lands is a site setting (ai_agent_modes.settings:chatbot_position,
 * on /admin/config/ai/agent-modes/settings), passed in through drupalSettings:
 *
 * - above_chat:  under the panel header and above the conversation (default).
 * - below_input: its own row under the message box.
 * - header:      inside the panel header row, beside the assistant name.
 *
 * The panel's markup is `.ai-deepchat > .ai-deepchat--header + .chat-element`,
 * and `.chat-element` can arrive late: a privacy gate (Klaro) may hold the chat
 * back until the visitor accepts it. So placement retries for a short while
 * rather than falling back to appending at the end of the panel, which is what
 * used to drop the dropdown under the message box whatever the site asked for.
 */

((Drupal, drupalSettings, once) => {
  const baseUrl = () =>
    (drupalSettings.path && drupalSettings.path.baseUrl) || '/';

  /**
   * Requests a CSRF token then posts the selection to the server.
   *
   * @param {string} agent
   *   The parent agent plugin ID.
   * @param {string} value
   *   The selected value ('' or 'mode:<id>').
   */
  const persist = (agent, value) => {
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
          body: JSON.stringify({ agent, value, conversation: '' }),
        }),
      )
      .catch(() => {
        // Selection is a best-effort UI preference; ignore transport errors.
      });
  };

  /**
   * Builds the dropdown from the fetched options.
   *
   * @param {object} data
   *   The options payload {value, options:[{value,label,group}]}.
   * @param {string} agent
   *   The parent agent plugin ID.
   *
   * @return {HTMLElement}
   *   The wrapper element containing the select.
   */
  const buildSelect = (data, agent) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'ai-agent-modes-chatbot';
    wrapper.style.cssText = 'padding:4px 12px;';

    const select = document.createElement('select');
    select.className = 'ai-agent-modes-chatbot__select';
    select.style.cssText =
      'width:100%;height:28px;font-size:12px;line-height:1;' +
      'padding:0 24px 0 8px;border:1px solid rgba(0,0,0,0.15);' +
      'border-radius:6px;background:#fff;color:#333;cursor:pointer;';
    select.setAttribute(
      'aria-label',
      Drupal.t('What should the assistant work on?'),
    );
    select.title = Drupal.t('Choose what you want the assistant to work on.');

    data.options.forEach((option) => {
      const el = document.createElement('option');
      el.value = option.value;
      el.textContent = option.label;
      if (option.value === data.value) {
        el.selected = true;
      }
      select.appendChild(el);
    });

    select.addEventListener('change', (event) =>
      persist(agent, event.target.value),
    );
    wrapper.appendChild(select);
    return wrapper;
  };

  /**
   * Places the built dropdown inside the panel, as the site asked.
   *
   * @param {HTMLElement} container
   *   The `.ai-deepchat` wrapper.
   * @param {HTMLElement} wrapper
   *   The element holding the select.
   * @param {string} position
   *   above_chat, below_input or header.
   *
   * @return {boolean}
   *   TRUE when it was placed, FALSE while the anchor is still missing.
   */
  const place = (container, wrapper, position) => {
    const header = container.querySelector('.ai-deepchat--header');
    const chat = container.querySelector('.chat-element');

    if (position === 'header') {
      if (!header) {
        return false;
      }
      wrapper.style.cssText = 'padding:0 8px;margin-left:auto;max-width:60%;';
      header.appendChild(wrapper);
      return true;
    }
    if (position === 'below_input') {
      if (!chat) {
        return false;
      }
      container.insertBefore(wrapper, chat.nextSibling);
      return true;
    }
    // above_chat, the default: between the header and the conversation.
    if (!chat) {
      return false;
    }
    container.insertBefore(wrapper, chat);
    return true;
  };

  /**
   * Adds the dropdown to one DeepChat panel if it is not there already.
   *
   * @param {HTMLElement} container
   *   The `.ai-deepchat` wrapper.
   * @param {string} agent
   *   The parent agent plugin ID.
   * @param {string} assistant
   *   The AI Assistant ID this chat is backed by, or an empty string. Modes
   *   limited to selected assistants are offered for this assistant only.
   * @param {string} position
   *   Where the site wants the dropdown inside the panel.
   */
  const inject = (container, agent, assistant, position) => {
    if (container.querySelector('.ai-agent-modes-chatbot')) {
      return;
    }
    const query = assistant
      ? `?assistant=${encodeURIComponent(assistant)}`
      : '';
    fetch(`${baseUrl()}ai-agent-modes/options/${agent}${query}`, {
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((data) => {
        // Only inject when there is more than the "anything" option.
        if (!data || !Array.isArray(data.options) || data.options.length <= 1) {
          return;
        }
        const wrapper = buildSelect(data, agent);
        if (place(container, wrapper, position)) {
          return;
        }
        // The chat can be held back by a privacy gate, so wait for it rather
        // than dropping the dropdown somewhere the site did not ask for.
        let attempts = 0;
        const timer = window.setInterval(() => {
          attempts += 1;
          if (place(container, wrapper, position) || attempts > 40) {
            window.clearInterval(timer);
          }
        }, 250);
      })
      .catch(() => {});
  };

  /**
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.aiAgentModesChatbotDeepChat = {
    attach(context) {
      const settings = drupalSettings.aiAgentModesChatbot || {};
      if (!settings.agent) {
        return;
      }
      once('ai-agent-modes-chatbot', '.ai-deepchat', context).forEach(
        (container) =>
          inject(
            container,
            settings.agent,
            settings.assistant || '',
            settings.position || 'above_chat',
          ),
      );
    },
  };
})(Drupal, drupalSettings, once);
