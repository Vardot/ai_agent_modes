/**
 * @file
 * Persists the AI Agent Modes selection made in a chat interface.
 */

((Drupal, drupalSettings, once) => {
  /**
   * Sends the selected mode to the server so the next agent run is scoped.
   *
   * @param {string} agent
   *   The parent agent plugin ID.
   * @param {string} value
   *   The selected value ('', 'mode:<id>' or 'agent:<id>').
   * @param {string} conversation
   *   The conversation/thread ID, or an empty string.
   */
  const persist = (agent, value, conversation) => {
    fetch(`${drupalSettings.path.baseUrl}session/token`)
      .then((response) => response.text())
      .then((token) =>
        fetch(`${drupalSettings.path.baseUrl}ai-agent-modes/selection`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
          body: JSON.stringify({ agent, value, conversation }),
        }),
      )
      .catch(() => {
        // Selection is a best-effort UI preference; ignore transport errors.
      });
  };

  /**
   * Attaches the mode-selector change handler to the chat form.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.aiAgentModesChat = {
    attach(context) {
      const settings = drupalSettings.aiAgentModes || {};
      const elements = once(
        'ai-agent-modes-select',
        'select.ai-agent-modes-mode',
        context,
      );
      elements.forEach((element) => {
        element.addEventListener('change', (event) => {
          persist(
            settings.agent || '',
            event.target.value,
            settings.conversation || '',
          );
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
