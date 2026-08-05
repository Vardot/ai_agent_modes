<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_agent_modes\Hook\SpeechHooks;

/**
 * Settings for how the mode selector is presented.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_agent_modes_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_agent_modes.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'ai_agent_modes/settings_form';

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-dropdown',
    ];
    // Vertical tabs remember the open tab in a hidden field, and a browser
    // restores that field on a plain reload, overriding the tab this form says
    // it opens on. Telling the browser not to restore it is what makes
    // "Mode dropdown" the tab people actually land on.
    $form['#after_build'][] = [static::class, 'stopRestoringTheOpenTab'];

    $form['dropdown'] = [
      '#type' => 'details',
      '#title' => $this->t('Mode dropdown'),
      '#description' => $this->t('Whether the mode dropdown is offered, and where it sits in the Drupal Canvas AI panel. The AI Chatbot panel keeps its own placement, which one AI Assistant sets on its own form.'),
      '#group' => 'tabs',
    ];
    $show_dropdown = $this->config('ai_agent_modes.settings')->get('show_dropdown');
    $form['dropdown']['show_dropdown'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer the mode dropdown in the chat'),
      // An absent value counts as on, so a site upgraded before this setting
      // existed keeps the dropdown it already had.
      '#default_value' => $show_dropdown === NULL ? TRUE : (bool) $show_dropdown,
      '#description' => $this->t('Off leaves every mode exactly as it is and simply stops asking the person to choose one, which suits a site that scopes its assistant by configuration instead.'),
    ];
    $shown_with_dropdown = [
      'visible' => [
        ':input[name="show_dropdown"]' => ['checked' => TRUE],
      ],
    ];

    $form['dropdown']['canvas_position'] = [
      '#type' => 'radios',
      '#states' => $shown_with_dropdown,
      '#attributes' => ['class' => ['ai-agent-modes-cards']],
      '#title' => $this->t('Where should the dropdown appear in the Drupal Canvas AI panel?'),
      '#default_value' => $this->config('ai_agent_modes.settings')->get('canvas_position') ?: 'toolbar',
      '#options' => [
        'toolbar' => $this->optionLabel('toolbar', $this->t('In the toolbar (compact)')),
        'top' => $this->optionLabel('top', $this->t('Top of the panel')),
        'above_input' => $this->optionLabel('above_input', $this->t('Above the message box')),
        'below_input' => $this->optionLabel('below_input', $this->t('Under the message box')),
      ],
      '#description' => $this->t('Applies to everyone using the assistant.'),
    ];

    $stt = (array) ($this->config('ai_agent_modes.settings')->get('speech_to_text') ?? []);
    $tts = (array) ($this->config('ai_agent_modes.settings')->get('text_to_speech') ?? []);
    $commands = (array) ($stt['commands'] ?? []);
    $languages = SpeechHooks::languageOptions();

    // Speech to text: deep-chat's microphone.
    $form['speech_to_text'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#group' => 'tabs',
      '#title' => $this->t('Microphone (speech to text)'),
      '#description' => $this->t("The chat can offer a microphone and dictate what is said into the message box. This is the browser's own Web Speech support: this site sends nothing anywhere and needs no key. The browser may still do the recognition in the cloud, which Chrome and Edge do, so the audio leaves the machine through the browser. A browser without speech support shows the button as unavailable."),
    ];
    $form['speech_to_text']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer a microphone in the chat'),
      '#default_value' => (bool) ($stt['enabled'] ?? FALSE),
      '#description' => $this->t('Applies to both the AI Chatbot panel and the Drupal Canvas AI panel. One AI Assistant may decide otherwise on its own form.'),
    ];
    $shown_with_microphone = [
      'visible' => [
        ':input[name="speech_to_text[enabled]"]' => ['checked' => TRUE],
      ],
    ];
    $form['speech_to_text']['position'] = [
      '#type' => 'radios',
      '#attributes' => ['class' => ['ai-agent-modes-cards']],
      '#title' => $this->t('Where should the microphone sit?'),
      '#default_value' => (string) ($stt['position'] ?? SpeechHooks::DEFAULT_POSITION),
      '#options' => $this->microphoneOptionLabels(),
      '#description' => $this->t('Left and right follow the writing direction, so a right-to-left panel mirrors them.'),
      '#states' => $shown_with_microphone,
    ];
    $form['speech_to_text']['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Dictation language'),
      '#options' => $languages,
      '#default_value' => (string) ($stt['language'] ?? 'browser'),
      '#description' => $this->t('Which language the browser listens for. <em>Follow the page language</em> is usually what a multilingual site wants: the panel then listens in whatever language the page is in.'),
      '#states' => $shown_with_microphone,
    ];
    $form['speech_to_text']['display_interim_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show words as they are recognised'),
      '#default_value' => (bool) ($stt['display_interim_results'] ?? TRUE),
      '#description' => $this->t('Off means nothing appears in the message box until a phrase is finished.'),
      '#states' => $shown_with_microphone,
    ];
    $form['speech_to_text']['stop_after_submit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stop recording once the message is sent'),
      '#default_value' => (bool) ($stt['stop_after_submit'] ?? TRUE),
      '#description' => $this->t('Off keeps the microphone listening for the next message, which suits dictating a long conversation hands-free.'),
      '#states' => $shown_with_microphone,
    ];
    $form['speech_to_text']['submit_after_silence'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the message after a pause in speaking'),
      '#default_value' => (bool) ($stt['submit_after_silence'] ?? TRUE),
      '#description' => $this->t('On, which is the default, means someone can dictate without touching the keyboard. Off fills the message box and leaves sending to the person.'),
      '#states' => $shown_with_microphone,
    ];
    $form['speech_to_text']['submit_after_silence_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Length of that pause'),
      '#field_suffix' => $this->t('milliseconds'),
      '#min' => 200,
      '#max' => 60000,
      '#step' => 100,
      '#default_value' => (int) ($stt['submit_after_silence_ms'] ?? 4000),
      '#description' => $this->t('How long the person may stop speaking before the message is sent.'),
      '#states' => [
        'visible' => [
          ':input[name="speech_to_text[enabled]"]' => ['checked' => TRUE],
          ':input[name="speech_to_text[submit_after_silence]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['speech_to_text']['interim_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Colour of words still being recognised'),
      '#size' => 12,
      '#placeholder' => 'gray',
      '#default_value' => (string) ($stt['interim_color'] ?? ''),
      '#description' => $this->t('Any CSS colour. Empty keeps the chat theme colours.'),
      '#states' => $shown_with_microphone,
    ];
    $form['speech_to_text']['final_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Colour of recognised words'),
      '#size' => 12,
      '#placeholder' => 'black',
      '#default_value' => (string) ($stt['final_color'] ?? ''),
      '#description' => $this->t('Any CSS colour. Empty keeps the chat theme colours.'),
      '#states' => $shown_with_microphone,
    ];
    $form['speech_to_text']['translations'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Corrections'),
      '#rows' => 4,
      '#default_value' => (string) ($stt['translations'] ?? ''),
      '#description' => $this->t('Words the recogniser hears wrongly, one <code>spoken|written</code> pair per line, for example <code>varbase|Varbase</code>. Case matters.'),
      '#states' => $shown_with_microphone,
    ];

    // Voice commands, its own group because a site can ignore all of it.
    $form['speech_to_text']['commands'] = [
      '#type' => 'details',
      '#title' => $this->t('Voice commands'),
      '#description' => $this->t('Phrases that drive the chat instead of being typed into it. Leave a phrase empty and it is not listened for.'),
      // Open only when a phrase is actually set, so a site that ignores voice
      // commands is not asked to read past them.
      '#open' => $this->hasCommands($commands),
      '#states' => $shown_with_microphone,
    ];
    $phrases = [
      'stop' => [$this->t('Stop listening'), 'stop listening'],
      'pause' => [$this->t('Pause transcribing'), 'pause'],
      'resume' => [$this->t('Resume transcribing'), 'resume'],
      'remove_all_text' => [$this->t('Clear the message box'), 'clear'],
      'submit' => [$this->t('Send the message'), 'send it'],
      'command_mode' => [$this->t('Listen for commands only'), 'command mode'],
    ];
    foreach ($phrases as $key => [$title, $example]) {
      $form['speech_to_text']['commands'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => (string) ($commands[$key] ?? ''),
        '#placeholder' => $example,
      ];
    }
    $form['speech_to_text']['commands']['substrings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Recognise a phrase inside a longer sentence'),
      '#default_value' => (bool) ($commands['substrings'] ?? TRUE),
      '#description' => $this->t('Off means the phrase has to be said on its own.'),
    ];
    $form['speech_to_text']['commands']['case_sensitive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Match the case of a phrase'),
      '#default_value' => (bool) ($commands['case_sensitive'] ?? FALSE),
    ];

    // Text to speech: reading the reply back.
    $form['text_to_speech'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#group' => 'tabs',
      '#title' => $this->t('Reading replies aloud (text to speech)'),
      '#description' => $this->t("The chat can read each reply aloud with the browser's own speech synthesis. As with the microphone, this site sends nothing anywhere and needs no key, though some browsers fetch their voices from the network. The browser only speaks while its window is focused, which is the browser's rule, not ours."),
    ];
    $form['text_to_speech']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Read replies aloud'),
      '#default_value' => (bool) ($tts['enabled'] ?? FALSE),
      '#description' => $this->t('One AI Assistant may decide otherwise on its own form.'),
    ];
    $shown_with_reading = [
      'visible' => [
        ':input[name="text_to_speech[enabled]"]' => ['checked' => TRUE],
      ],
    ];
    $form['text_to_speech']['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Reading language'),
      '#options' => $languages,
      '#default_value' => (string) ($tts['language'] ?? 'browser'),
      '#description' => $this->t('Which language the reply is read in.'),
      '#states' => $shown_with_reading,
    ];
    $voice = (string) ($tts['voice_name'] ?? '');
    $form['text_to_speech']['voice_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Voice'),
      // The voices belong to the browser, not the server, so the list is filled
      // in by js/settings-form.js from what this browser actually has. The
      // stored value is offered here so it survives being looked at from a
      // machine without that voice, and #validated lets the browser's own
      // additions through the submitted-value check.
      '#options' => $voice === ''
        ? ['' => $this->t('Default voice')]
        : ['' => $this->t('Default voice'), $voice => $voice],
      '#default_value' => $voice,
      '#validated' => TRUE,
      '#attributes' => ['data-ai-agent-modes-voice' => $voice],
      '#description' => $this->t('The voices are the ones installed for this browser and operating system, so the list differs from machine to machine. A voice that turns out not to be installed falls back to the default voice.'),
      '#states' => $shown_with_reading,
    ];
    $form['text_to_speech']['pitch'] = [
      '#type' => 'number',
      '#title' => $this->t('Pitch'),
      '#min' => 0,
      '#max' => 2,
      '#step' => 0.1,
      '#default_value' => (float) ($tts['pitch'] ?? 1),
      '#description' => $this->t("1 is the voice's own pitch."),
      '#states' => $shown_with_reading,
    ];
    $form['text_to_speech']['rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Speed'),
      '#min' => 0.1,
      '#max' => 10,
      '#step' => 0.1,
      '#default_value' => (float) ($tts['rate'] ?? 1),
      '#description' => $this->t("1 is the voice's own speed."),
      '#states' => $shown_with_reading,
    ];
    $form['text_to_speech']['volume'] = [
      '#type' => 'number',
      '#title' => $this->t('Volume'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#default_value' => (float) ($tts['volume'] ?? 1),
      '#description' => $this->t('1 is full volume.'),
      '#states' => $shown_with_reading,
    ];

    $enforcement = $this->config('ai_agent_modes.settings')->get('tool_scope_enforcement');
    $form['scope'] = [
      '#type' => 'details',
      '#title' => $this->t('Tool scope'),
      '#description' => $this->t('How strictly a mode that withholds tools is enforced.'),
      '#group' => 'tabs',
    ];
    $form['scope']['tool_scope_enforcement'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enforce tool scope'),
      // An absent value counts as enabled, matching how the mode manager reads
      // it, so a site upgraded before this setting existed keeps the behaviour.
      '#default_value' => $enforcement === NULL ? TRUE : (bool) $enforcement,
      '#description' => $this->t('When this is off, every mode set to <em>Steer and withhold</em> behaves as <em>Steer only</em>: the assistant is still pointed at the right sub-agents, but nothing is withheld from it. Switch it off to rule the withholding out as the cause of a problem, without editing any mode.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Stops the browser restoring which tab was last open.
   *
   * @param array $form
   *   The built form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  public static function stopRestoringTheOpenTab(array $form, FormStateInterface $form_state): array {
    if (isset($form['tabs']['tabs__active_tab'])) {
      $form['tabs']['tabs__active_tab']['#attributes']['autocomplete'] = 'off';
    }
    return $form;
  }

  /**
   * Builds a radio label with a small diagram of the position.
   *
   * Each diagram is the assistant panel in miniature: the outer box is the
   * panel, the small box at its foot is the message box, and the blue bar is
   * where the dropdown will sit.
   *
   * @param string $variant
   *   The position machine name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   The option text.
   *
   * @return \Drupal\Core\Render\MarkupInterface
   *   The label markup.
   */
  protected function optionLabel(string $variant, TranslatableMarkup $text): MarkupInterface {
    // The message box outline sits higher in the below_input diagram so the
    // bar under it still fits inside the panel outline.
    $box_y = $variant === 'below_input' ? 15.5 : 19.5;
    $bar = match ($variant) {
      'top' => '<rect x="5" y="4" width="34" height="3.5" rx="1.5" fill="#0d6efd"/>',
      'above_input' => '<rect x="5" y="15" width="34" height="3.5" rx="1.5" fill="#0d6efd"/>',
      'below_input' => '<rect x="5" y="28" width="34" height="3.5" rx="1.5" fill="#0d6efd"/>',
      default => '<circle cx="9" cy="27" r="1.6" fill="#999"/><circle cx="35" cy="27" r="1.6" fill="#999"/>'
        . '<rect x="13" y="25.5" width="18" height="3" rx="1.5" fill="#0d6efd"/>',
    };
    $svg = '<svg width="80" height="62" viewBox="0 0 44 34" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"'
      . ' style="vertical-align:middle;margin-right:10px;">'
      . '<rect x="1" y="1" width="42" height="32" rx="3" fill="#fff" stroke="#c2c8d0"/>'
      . '<rect x="5" y="' . $box_y . '" width="34" height="11" rx="2" fill="none" stroke="#c2c8d0"/>'
      . $bar
      . '</svg>';
    return Markup::create($svg . '<span style="vertical-align:middle;">' . $text . '</span>');
  }

  /**
   * Builds the microphone placement labels, each with its own small diagram.
   *
   * The diagram is the message box in miniature, with the microphone as a dot
   * where it will sit, so the four placements can be told apart at a glance.
   *
   * @return array<string, \Drupal\Core\Render\MarkupInterface>
   *   Label markup keyed by stored value.
   */
  protected function microphoneOptionLabels(): array {
    $labels = [];
    foreach (SpeechHooks::positionOptions() as $value => $text) {
      // The message box outline, then the dot: inside the box for the input_*
      // placements, beside it for the outside_* ones.
      $dot = match ($value) {
        'input_end' => '<circle cx="32" cy="24" r="2" fill="#0d6efd"/>',
        'input_start' => '<circle cx="10" cy="24" r="2" fill="#0d6efd"/>',
        'outside_end' => '<circle cx="41" cy="24" r="2" fill="#0d6efd"/>',
        default => '<circle cx="3" cy="24" r="2" fill="#0d6efd"/>',
      };
      // Inside placements share the box's corner with the send button, drawn
      // grey here so the gap between the two reads as deliberate.
      $send = str_starts_with($value, 'input')
        ? '<circle cx="' . ($value === 'input_end' ? 37 : 15) . '" cy="24" r="2" fill="#c2c8d0"/>'
        : '<circle cx="37" cy="24" r="2" fill="#c2c8d0"/>';
      $box_x = $value === 'outside_start' ? 8 : 1;
      $svg = '<svg width="80" height="46" viewBox="0 0 44 30" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"'
        . ' style="vertical-align:middle;margin-right:10px;">'
        . '<rect x="' . $box_x . '" y="14" width="35" height="15" rx="2" fill="#fff" stroke="#c2c8d0"/>'
        . '<rect x="' . ($box_x + 4) . '" y="18" width="14" height="2.5" rx="1.25" fill="#e3e3ea"/>'
        . $send . $dot
        . '</svg>';
      $labels[$value] = Markup::create($svg . '<span style="vertical-align:middle;">' . $text . '</span>');
    }
    return $labels;
  }

  /**
   * Whether any voice command phrase is configured.
   *
   * @param array $commands
   *   The commands settings.
   *
   * @return bool
   *   TRUE when at least one phrase is set.
   */
  protected function hasCommands(array $commands): bool {
    $phrases = [
      'stop',
      'pause',
      'resume',
      'remove_all_text',
      'submit',
      'command_mode',
    ];
    foreach ($phrases as $phrase) {
      if (trim((string) ($commands[$phrase] ?? '')) !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Collects the speech-to-text values in the shape the schema declares.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The speech_to_text settings.
   */
  protected function speechToTextValues(FormStateInterface $form_state): array {
    $values = (array) $form_state->getValue('speech_to_text');
    $commands = (array) ($values['commands'] ?? []);
    return [
      'enabled' => (bool) ($values['enabled'] ?? FALSE),
      'position' => (string) ($values['position'] ?? SpeechHooks::DEFAULT_POSITION),
      'language' => (string) ($values['language'] ?? 'browser'),
      'display_interim_results' => (bool) ($values['display_interim_results'] ?? TRUE),
      'stop_after_submit' => (bool) ($values['stop_after_submit'] ?? TRUE),
      'submit_after_silence' => (bool) ($values['submit_after_silence'] ?? TRUE),
      'submit_after_silence_ms' => (int) ($values['submit_after_silence_ms'] ?? 4000),
      'interim_color' => trim((string) ($values['interim_color'] ?? '')),
      'final_color' => trim((string) ($values['final_color'] ?? '')),
      'commands' => [
        'stop' => trim((string) ($commands['stop'] ?? '')),
        'pause' => trim((string) ($commands['pause'] ?? '')),
        'resume' => trim((string) ($commands['resume'] ?? '')),
        'remove_all_text' => trim((string) ($commands['remove_all_text'] ?? '')),
        'submit' => trim((string) ($commands['submit'] ?? '')),
        'command_mode' => trim((string) ($commands['command_mode'] ?? '')),
        'substrings' => (bool) ($commands['substrings'] ?? TRUE),
        'case_sensitive' => (bool) ($commands['case_sensitive'] ?? FALSE),
      ],
      'translations' => trim((string) ($values['translations'] ?? '')),
    ];
  }

  /**
   * Collects the text-to-speech values in the shape the schema declares.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The text_to_speech settings.
   */
  protected function textToSpeechValues(FormStateInterface $form_state): array {
    $values = (array) $form_state->getValue('text_to_speech');
    return [
      'enabled' => (bool) ($values['enabled'] ?? FALSE),
      'language' => (string) ($values['language'] ?? 'browser'),
      'voice_name' => trim((string) ($values['voice_name'] ?? '')),
      'pitch' => (float) ($values['pitch'] ?? 1),
      'rate' => (float) ($values['rate'] ?? 1),
      'volume' => (float) ($values['volume'] ?? 1),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $speech = (array) $form_state->getValue('speech_to_text');
    $reading = (array) $form_state->getValue('text_to_speech');

    // The numbers the browser is handed have to be numbers in the range the Web
    // Speech API accepts. The number fields carry min and max, but those are
    // the browser's guard rails and a submitted value can arrive without them.
    $ranges = [
      ['text_to_speech', 'pitch', $reading['pitch'] ?? 1, 0, 2, $this->t('Pitch')],
      ['text_to_speech', 'rate', $reading['rate'] ?? 1, 0.1, 10, $this->t('Speed')],
      ['text_to_speech', 'volume', $reading['volume'] ?? 1, 0, 1, $this->t('Volume')],
    ];
    foreach ($ranges as [$tree, $key, $value, $min, $max, $label]) {
      if ($value === '' || $value === NULL) {
        continue;
      }
      if (!is_numeric($value)) {
        $form_state->setErrorByName($tree . '][' . $key, $this->t('@label has to be a number.', ['@label' => $label]));
        continue;
      }
      if ((float) $value < $min || (float) $value > $max) {
        $form_state->setErrorByName($tree . '][' . $key, $this->t('@label has to be between @min and @max.', [
          '@label' => $label,
          '@min' => $min,
          '@max' => $max,
        ]));
      }
    }

    if (!empty($speech['submit_after_silence'])) {
      $pause = $speech['submit_after_silence_ms'] ?? 0;
      if (!is_numeric($pause) || (int) $pause < 200 || (int) $pause > 60000) {
        $form_state->setErrorByName('speech_to_text][submit_after_silence_ms', $this->t('The pause before sending has to be between 200 and 60000 milliseconds.'));
      }
    }

    // The two colours are handed to deep-chat and end up as CSS colours, so
    // only something that is actually a colour is accepted: anything else
    // would be pushed into a style declaration on this form's word alone.
    $colours = [
      'interim_color' => $this->t('Colour of words still being recognised'),
      'final_color' => $this->t('Colour of recognised words'),
    ];
    foreach ($colours as $key => $label) {
      $colour = trim((string) ($speech[$key] ?? ''));
      if ($colour !== '' && !$this->isColour($colour)) {
        $form_state->setErrorByName('speech_to_text][' . $key, $this->t('@label has to be a CSS colour: a name such as <em>gray</em>, a hex value such as <em>#55565b</em>, or an rgb(), rgba(), hsl() or hsla() value.', ['@label' => $label]));
      }
    }

    // A voice name is a name, not a sentence, and never markup.
    $voice = trim((string) ($reading['voice_name'] ?? ''));
    if ($voice !== '' && (mb_strlen($voice) > 128 || preg_match('/[<>"\\x00-\\x1f]/', $voice) === 1)) {
      $form_state->setErrorByName('text_to_speech][voice_name', $this->t('The voice name is too long, or contains characters a voice name never has.'));
    }
  }

  /**
   * Whether a value is a CSS colour this form is willing to pass on.
   *
   * Deliberately narrow: a keyword, a hex value, or one of the four functional
   * notations, with nothing else allowed through.
   *
   * @param string $colour
   *   The submitted value.
   *
   * @return bool
   *   TRUE when it is a colour.
   */
  protected function isColour(string $colour): bool {
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $colour) === 1) {
      return TRUE;
    }
    if (preg_match('/^(rgb|rgba|hsl|hsla)\\(\\s*[0-9.%,\\s\\/deg]+\\)$/i', $colour) === 1) {
      return TRUE;
    }
    // A keyword: letters only, which covers every named colour plus transparent
    // and currentColor.
    return preg_match('/^[a-z]{3,24}$/i', $colour) === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_agent_modes.settings')
      ->set('show_dropdown', (bool) $form_state->getValue('show_dropdown'))
      ->set('canvas_position', $form_state->getValue('canvas_position'))
      ->set('speech_to_text', $this->speechToTextValues($form_state))
      ->set('text_to_speech', $this->textToSpeechValues($form_state))
      ->set('tool_scope_enforcement', (bool) $form_state->getValue('tool_scope_enforcement'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
