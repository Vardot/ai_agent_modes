<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Offers deep-chat's speech, both directions, as configuration.
 *
 * The deep-chat component draws both chat surfaces this module works with, and
 * it can already speak and listen: `speechToText` shows a microphone and
 * dictates into the message box, `textToSpeech` reads each reply aloud. Both
 * run on the browser's own Web Speech support, so nothing is sent to a service
 * of ours and no key is needed. What the browser does with the audio is the
 * browser's business, and not nothing: Chrome and Edge recognise in the cloud.
 * Neither surface exposes any of it:
 *
 * - The AI Chatbot module's DeepChat block removes any speechToText and
 *   microphone configuration from the settings it renders, after its own
 *   hook_deepchat_settings has run, so a hook cannot put it back.
 * - The Drupal Canvas AI panel is mounted by the Canvas editor's React bundle,
 *   so there is no render array to configure in the first place.
 *
 * Both are reached the way the mode dropdown reaches them: a behaviour rides
 * along with each surface's own library and sets the properties on the mounted
 * element (see js/speech.js). Every option deep-chat documents at
 * https://deepchat.dev/docs/speech is offered, except Azure: that needs a
 * subscription key or a token, which does not belong in configuration, so a
 * site that wants Azure adds it from its own code with
 * hook_ai_agent_modes_speech_alter().
 *
 * The integration is soft throughout: nothing is added unless the surface's
 * module is installed, and nothing is switched on unless the site asks for it.
 */
class SpeechHooks implements ContainerInjectionInterface {

  /**
   * The behaviour that applies the speech settings and places the microphone.
   */
  public const LIBRARY = 'ai_agent_modes/speech';

  /**
   * The drupalSettings key the behaviour reads.
   */
  public const SETTINGS_KEY = 'aiAgentModesSpeech';

  /**
   * The third-party settings key holding an assistant's microphone override.
   */
  public const SPEECH_TO_TEXT_KEY = 'speech_to_text';

  /**
   * The third-party settings key holding an assistant's placement override.
   */
  public const POSITION_KEY = 'speech_to_text_position';

  /**
   * The third-party settings key holding an assistant's read-aloud override.
   */
  public const TEXT_TO_SPEECH_KEY = 'text_to_speech';

  /**
   * The placement used when nothing is configured.
   */
  public const DEFAULT_POSITION = 'input_start';

  /**
   * Constructs a SpeechHooks object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, read for the site-wide settings.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * The placements the microphone may take.
   *
   * "Start" and "end" follow the writing direction, so a right-to-left panel
   * mirrors them without a second set of options.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Option labels keyed by stored value.
   */
  public static function positionOptions(): array {
    return [
      'input_end' => new TranslatableMarkup('Inside the message box, bottom right'),
      'input_start' => new TranslatableMarkup('Inside the message box, bottom left'),
      'outside_end' => new TranslatableMarkup('Outside the message box, after it'),
      'outside_start' => new TranslatableMarkup('Outside the message box, before it'),
    ];
  }

  /**
   * The languages offered for dictation and for reading aloud.
   *
   * Which languages a browser can actually recognise or speak is the browser's
   * business and cannot be read from the server, so this is a working shortlist
   * of widely supported BCP 47 tags rather than an exhaustive one. Two entries
   * are not tags at all: "browser" leaves the choice to the browser, and "site"
   * follows the page language, which is what a multilingual site usually wants.
   * English leads the list, and the rest follow alphabetically by tag.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Language labels keyed by stored value.
   */
  public static function languageOptions(): array {
    return [
      'browser' => new TranslatableMarkup('Let the browser choose'),
      'site' => new TranslatableMarkup('Follow the page language'),
      'en-US' => new TranslatableMarkup('English (United States) - en-US'),
      'en-GB' => new TranslatableMarkup('English (United Kingdom) - en-GB'),
      'ar-AE' => new TranslatableMarkup('Arabic (United Arab Emirates) - ar-AE'),
      'ar-EG' => new TranslatableMarkup('Arabic (Egypt) - ar-EG'),
      'ar-JO' => new TranslatableMarkup('Arabic (Jordan) - ar-JO'),
      'ar-SA' => new TranslatableMarkup('Arabic (Saudi Arabia) - ar-SA'),
      'de-DE' => new TranslatableMarkup('German - de-DE'),
      'es-ES' => new TranslatableMarkup('Spanish (Spain) - es-ES'),
      'fa-IR' => new TranslatableMarkup('Persian - fa-IR'),
      'fr-FR' => new TranslatableMarkup('French - fr-FR'),
      'he-IL' => new TranslatableMarkup('Hebrew - he-IL'),
      'hi-IN' => new TranslatableMarkup('Hindi - hi-IN'),
      'id-ID' => new TranslatableMarkup('Indonesian - id-ID'),
      'it-IT' => new TranslatableMarkup('Italian - it-IT'),
      'ja-JP' => new TranslatableMarkup('Japanese - ja-JP'),
      'ko-KR' => new TranslatableMarkup('Korean - ko-KR'),
      'ms-MY' => new TranslatableMarkup('Malay - ms-MY'),
      'nl-NL' => new TranslatableMarkup('Dutch - nl-NL'),
      'pl-PL' => new TranslatableMarkup('Polish - pl-PL'),
      'pt-BR' => new TranslatableMarkup('Portuguese (Brazil) - pt-BR'),
      'ru-RU' => new TranslatableMarkup('Russian - ru-RU'),
      'sv-SE' => new TranslatableMarkup('Swedish - sv-SE'),
      'tr-TR' => new TranslatableMarkup('Turkish - tr-TR'),
      'ur-PK' => new TranslatableMarkup('Urdu - ur-PK'),
      'zh-CN' => new TranslatableMarkup('Chinese (Simplified) - zh-CN'),
    ];
  }

  /**
   * Implements hook_library_info_alter().
   *
   * Rides along with each surface's own library, which is what puts the
   * behaviour on the Canvas editor page: that page is built by the Canvas React
   * bundle and never attaches anything of ours.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'canvas' && isset($libraries['canvas-ui']) && $this->moduleHandler->moduleExists('canvas_ai')) {
      $libraries['canvas-ui']['dependencies'][] = self::LIBRARY;
      return;
    }
    if ($extension === 'ai_chatbot' && isset($libraries['deepchat'])) {
      $libraries['deepchat']['dependencies'][] = self::LIBRARY;
    }
  }

  /**
   * Implements hook_page_attachments().
   *
   * The settings ride in every page's final JavaScript settings, which
   * hook_js_settings_alter has no cache metadata of its own. Without this tag
   * a page rendered while the microphone was on would keep offering it
   * after the site switched it off, until something else invalidated that page.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#cache']['tags'][] = 'config:ai_agent_modes.settings';
  }

  /**
   * Implements hook_js_settings_alter().
   *
   * The Canvas editor page assembles its own assets, and a page attachment does
   * not reach it, so the settings are added here where every page's final
   * settings pass through. Anything a block already set is kept: that is one
   * assistant's override, which is more specific than the site setting.
   */
  #[Hook('js_settings_alter')]
  public function jsSettingsAlter(array &$settings, mixed $assets): void {
    $site = $this->siteSettings();
    if (!$site['speechToText'] && !$site['textToSpeech'] && !isset($settings[self::SETTINGS_KEY])) {
      // Both halves off site-wide and no assistant asked for either: say
      // nothing at all rather than put an inert entry on every page.
      return;
    }
    $settings[self::SETTINGS_KEY] = ($settings[self::SETTINGS_KEY] ?? []) + $site;
  }

  /**
   * Implements hook_block_view_ai_deepchat_block_alter().
   *
   * Carries one assistant's own choices, which is the only place the assistant
   * is known. Without an override this does nothing and the site settings added
   * in hook_js_settings_alter apply.
   */
  #[Hook('block_view_ai_deepchat_block_alter')]
  public function blockViewAlter(array &$build, BlockPluginInterface $block): void {
    $build['#cache']['tags'][] = 'config:ai_agent_modes.settings';
    if (!$this->moduleHandler->moduleExists('ai_assistant_api')) {
      return;
    }
    $assistant_id = (string) ($block->getConfiguration()['ai_assistant'] ?? '');
    if ($assistant_id === '') {
      return;
    }
    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($assistant_id);
    if (!$assistant instanceof ConfigEntityInterface) {
      return;
    }

    $overrides = $this->assistantOverrides($assistant);
    if ($overrides === []) {
      return;
    }
    $build['#attached']['library'][] = self::LIBRARY;
    $build['#attached']['drupalSettings'][self::SETTINGS_KEY] = $overrides + $this->siteSettings();
    $build['#cache']['tags'] = array_merge($build['#cache']['tags'], $assistant->getCacheTags());
  }

  /**
   * Builds what the behaviour reads, from the site-wide settings.
   *
   * The two payloads are deep-chat's own, ready to be set on the element as
   * they are, so the browser side stays a thin applier and the shape of what
   * deep-chat accepts is decided in one place.
   *
   * @return array
   *   An array with the two switches, the microphone placement, and the
   *   speechToText and textToSpeech payloads.
   */
  protected function siteSettings(): array {
    $config = $this->configFactory->get('ai_agent_modes.settings');
    $stt = (array) ($config->get('speech_to_text') ?? []);
    $tts = (array) ($config->get('text_to_speech') ?? []);
    $position = (string) ($stt['position'] ?? self::DEFAULT_POSITION);

    $settings = [
      'speechToText' => (bool) ($stt['enabled'] ?? FALSE),
      'textToSpeech' => (bool) ($tts['enabled'] ?? FALSE),
      'position' => array_key_exists($position, self::positionOptions()) ? $position : self::DEFAULT_POSITION,
      'speechToTextConfig' => $this->speechToTextConfig($stt),
      'textToSpeechConfig' => $this->textToSpeechConfig($tts),
      // The two languages travel as they were chosen rather than resolved: only
      // the browser knows what "follow the page language" means on this page.
      'speechToTextLanguage' => $this->language($stt),
      'textToSpeechLanguage' => $this->language($tts),
    ];

    // Lets a site add what cannot live in configuration, chiefly Azure with a
    // short-lived token fetched by its own code.
    $this->moduleHandler->alter('ai_agent_modes_speech', $settings);
    return $settings;
  }

  /**
   * Reads one half's language choice, checked against what is offered.
   *
   * @param array $settings
   *   The speech_to_text or text_to_speech settings.
   *
   * @return string
   *   "browser", "site", or a BCP 47 tag.
   */
  protected function language(array $settings): string {
    $language = trim((string) ($settings['language'] ?? ''));
    // A tag that is not on the shortlist is still honoured: the list is a
    // convenience, and a site may have been configured with a tag of its own.
    return $language === '' ? 'browser' : $language;
  }

  /**
   * Builds deep-chat's speechToText value.
   *
   * Only what the site actually set is included: deep-chat has its own defaults
   * for everything else, and sending them again would freeze today's defaults
   * into every site's configuration.
   *
   * @param array $stt
   *   The speech_to_text settings.
   *
   * @return array
   *   The speechToText payload, minus the language, which the behaviour works
   *   out: "follow the page language" is only knowable in the browser.
   */
  protected function speechToTextConfig(array $stt): array {
    $config = [
      'displayInterimResults' => (bool) ($stt['display_interim_results'] ?? TRUE),
      'stopAfterSubmit' => (bool) ($stt['stop_after_submit'] ?? TRUE),
    ];

    // On unless the site turned it off, matching the shipped default.
    if ($stt['submit_after_silence'] ?? TRUE) {
      $milliseconds = (int) ($stt['submit_after_silence_ms'] ?? 0);
      // The behaviour times this pause itself and then presses the panel's own
      // send button, because the two surfaces do not submit the same way (see
      // js/speech.js). TRUE keeps deep-chat's own two seconds as the meaning of
      // "not configured".
      $config['submitAfterSilence'] = $milliseconds > 0 ? $milliseconds : TRUE;
    }

    $colors = array_filter([
      'interim' => trim((string) ($stt['interim_color'] ?? '')),
      'final' => trim((string) ($stt['final_color'] ?? '')),
    ], static fn (string $color): bool => $color !== '');
    if ($colors !== []) {
      $config['textColor'] = $colors;
    }

    $commands = $this->commands((array) ($stt['commands'] ?? []));
    if ($commands !== []) {
      $config['commands'] = $commands;
    }

    $translations = $this->translations((string) ($stt['translations'] ?? ''));
    if ($translations !== []) {
      $config['translations'] = $translations;
    }

    return $config;
  }

  /**
   * Builds deep-chat's commands value from the configured phrases.
   *
   * @param array $commands
   *   The commands settings.
   *
   * @return array
   *   The commands payload, empty when no phrase is set: deep-chat treats a
   *   commands object as "listen for these", so empty strings would have it
   *   listening for nothing at all.
   */
  protected function commands(array $commands): array {
    $map = [
      'stop' => 'stop',
      'pause' => 'pause',
      'resume' => 'resume',
      'remove_all_text' => 'removeAllText',
      'submit' => 'submit',
      'command_mode' => 'commandMode',
    ];
    $payload = [];
    foreach ($map as $key => $property) {
      $phrase = trim((string) ($commands[$key] ?? ''));
      if ($phrase !== '') {
        $payload[$property] = $phrase;
      }
    }
    if ($payload === []) {
      return [];
    }
    $payload['settings'] = [
      'substrings' => (bool) ($commands['substrings'] ?? TRUE),
      'caseSensitive' => (bool) ($commands['case_sensitive'] ?? FALSE),
    ];
    return $payload;
  }

  /**
   * Parses the corrections into deep-chat's translations map.
   *
   * @param string $translations
   *   One "spoken|written" pair per line.
   *
   * @return array<string, string>
   *   Written text keyed by the spoken word, as deep-chat expects.
   */
  protected function translations(string $translations): array {
    $map = [];
    foreach (preg_split('/\R/', $translations) ?: [] as $line) {
      if (!str_contains($line, '|')) {
        continue;
      }
      [$spoken, $written] = explode('|', $line, 2);
      $spoken = trim($spoken);
      if ($spoken !== '') {
        $map[$spoken] = trim($written);
      }
    }
    return $map;
  }

  /**
   * Builds deep-chat's textToSpeech value.
   *
   * @param array $tts
   *   The text_to_speech settings.
   *
   * @return array
   *   The textToSpeech payload, minus the language, as above.
   */
  protected function textToSpeechConfig(array $tts): array {
    $config = [];
    $voice = trim((string) ($tts['voice_name'] ?? ''));
    if ($voice !== '') {
      $config['voiceName'] = $voice;
    }
    // Only a value the site moved off the voice's own is sent, so a default
    // reading voice stays exactly the browser's.
    foreach (['pitch' => 1.0, 'rate' => 1.0, 'volume' => 1.0] as $key => $neutral) {
      $value = (float) ($tts[$key] ?? $neutral);
      if (abs($value - $neutral) > 0.001) {
        $config[$key] = $value;
      }
    }
    return $config;
  }

  /**
   * Reads what one assistant overrides, if anything.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $assistant
   *   The ai_assistant entity.
   *
   * @return array<string, bool|string>
   *   Only the keys this assistant actually overrides, so the site settings
   *   supply the rest.
   */
  protected function assistantOverrides(ConfigEntityInterface $assistant): array {
    $overrides = [];
    $switches = [
      self::SPEECH_TO_TEXT_KEY => 'speechToText',
      self::TEXT_TO_SPEECH_KEY => 'textToSpeech',
    ];
    foreach ($switches as $key => $property) {
      $value = (string) ($assistant->getThirdPartySetting('ai_agent_modes', $key) ?? '');
      if ($value === 'on' || $value === 'off') {
        $overrides[$property] = $value === 'on';
      }
    }
    $position = (string) ($assistant->getThirdPartySetting('ai_agent_modes', self::POSITION_KEY) ?? '');
    if ($position !== '' && array_key_exists($position, self::positionOptions())) {
      $overrides['position'] = $position;
    }
    return $overrides;
  }

}
