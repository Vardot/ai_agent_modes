/**
 * @file
 * Helps the AI Agent Modes settings form with what only the browser knows.
 *
 * Two things on that form cannot be built on the server:
 *
 * - The list of voices for reading replies aloud. Which voices exist depends on
 *   the operating system and the browser, so the select is filled here from
 *   speechSynthesis.getVoices(). The stored value is kept whether or not this
 *   browser has that voice, so opening the form in a browser without it does not
 *   quietly change the site's setting.
 * - The voices arrive asynchronously in some browsers, which is why the
 *   voiceschanged event is listened for as well as read once.
 */

((Drupal, once) => {
  /**
   * Fills one select with the browser's voices, keeping the stored value.
   *
   * @param {HTMLSelectElement} select
   *   The voice select.
   */
  const fillVoices = (select) => {
    if (!('speechSynthesis' in window)) {
      return;
    }
    const voices = window.speechSynthesis.getVoices();
    if (!voices.length) {
      return;
    }
    const stored = select.dataset.aiAgentModesVoice || '';
    const chosen = select.value || stored;
    select.textContent = '';

    const none = document.createElement('option');
    none.value = '';
    none.textContent = Drupal.t('Default voice');
    select.appendChild(none);

    // The stored voice first when this browser does not have it, so the setting
    // survives being looked at from another machine.
    if (chosen !== '' && !voices.some((voice) => voice.name === chosen)) {
      const missing = document.createElement('option');
      missing.value = chosen;
      missing.textContent = Drupal.t('@voice (not installed in this browser)', {
        '@voice': chosen,
      });
      select.appendChild(missing);
    }

    voices.forEach((voice) => {
      const option = document.createElement('option');
      option.value = voice.name;
      // A voice the browser fetches rather than ships is synthesised on the
      // vendor's servers, so the reply text is sent there. The two are
      // indistinguishable by name, which is why it is said here.
      option.textContent = voice.localService
        ? Drupal.t('@voice (@lang, on this device)', {
            '@voice': voice.name,
            '@lang': voice.lang,
          })
        : Drupal.t('@voice (@lang, online voice)', {
            '@voice': voice.name,
            '@lang': voice.lang,
          });
      select.appendChild(option);
    });
    select.value = chosen;
  };

  /**
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.aiAgentModesSettingsForm = {
    attach(context) {
      once(
        'ai-agent-modes-voices',
        'select[data-ai-agent-modes-voice]',
        context,
      ).forEach((select) => {
        fillVoices(select);
        if ('speechSynthesis' in window) {
          window.speechSynthesis.addEventListener('voiceschanged', () =>
            fillVoices(select),
          );
        }
      });
    },
  };
})(Drupal, once);
