/**
 * @file
 * Adds the AI Agent Modes dropdown to the AI Chatbot's DeepChat block.
 *
 * The DeepChat block (`ai_deepchat_block`) renders its `<deep-chat>` element
 * straight into the page, outside the Form API, so it cannot be reached with
 * hook_form_alter(). The dropdown is injected as a light-DOM sibling right
 * above the `<deep-chat>` element, inside the block's own header, and the
 * selection is persisted through the same endpoint the Canvas AI panel uses.
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
   * Adds the dropdown above one DeepChat element if it is not there already.
   *
   * @param {HTMLElement} container
   *   The `.ai-deepchat` wrapper.
   * @param {string} agent
   *   The parent agent plugin ID.
   */
  const inject = (container, agent) => {
    if (container.querySelector('.ai-agent-modes-chatbot')) {
      return;
    }
    fetch(`${baseUrl()}ai-agent-modes/options/${agent}`, {
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json())
      .then((data) => {
        // Only inject when there is more than the "anything" option.
        if (!data || !Array.isArray(data.options) || data.options.length <= 1) {
          return;
        }
        const chatElement = container.querySelector('.chat-element');
        const wrapper = buildSelect(data, agent);
        if (chatElement) {
          container.insertBefore(wrapper, chatElement);
        } else {
          container.appendChild(wrapper);
        }
      })
      .catch(() => {});
  };

  /**
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.aiAgentModesChatbotDeepChat = {
    attach(context) {
      const settings = (drupalSettings.aiAgentModesChatbot || {}).agent;
      if (!settings) {
        return;
      }
      once('ai-agent-modes-chatbot', '.ai-deepchat', context).forEach(
        (container) => inject(container, settings),
      );
    },
  };
})(Drupal, drupalSettings, once);
