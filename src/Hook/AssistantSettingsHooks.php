<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets one AI Assistant override this module's presentation settings.
 *
 * Three things can be decided per assistant: where its mode dropdown sits,
 * whether its chat offers a microphone and where that sits, and whether its
 * replies are read aloud (see SpeechHooks). The site-wide values on the
 * module's own settings form are the defaults. An assistant that wants
 * something else carries the choice as a third-party setting on its own config
 * entity, which is the supported way to add a setting to another module's
 * configuration entity, and which travels
 * with that assistant when it is exported.
 *
 * The integration is soft: the hook only fires when the AI Assistant API is
 * installed, because nothing else provides that form.
 */
class AssistantSettingsHooks implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The third-party settings key holding the override.
   */
  public const POSITION_KEY = 'chatbot_position';

  /**
   * Constructs an AssistantSettingsHooks object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, read for the site-wide default.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * The placement options an assistant may choose from.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Option labels keyed by stored value.
   */
  public static function positionOptions(): array {
    return [
      'above_chat' => new TranslatableMarkup('Above the chat, under the panel header'),
      'below_input' => new TranslatableMarkup('Under the message box'),
      'header' => new TranslatableMarkup('In the panel header, beside the assistant name'),
    ];
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for the AI Assistant form.
   */
  #[Hook('form_ai_assistant_form_alter')]
  public function assistantFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $entity = $form_state->getFormObject()->getEntity();
    if (!$entity instanceof EntityInterface || !method_exists($entity, 'getThirdPartySetting')) {
      return;
    }

    $form['ai_agent_modes'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Agent Modes'),
      '#description' => $this->t("What this assistant offers in its own chatbot panel. Each choice falls back to the site setting on the AI Agent Modes settings form. The Drupal Canvas AI panel is not driven by an assistant, so it always follows the site settings."),
      '#open' => FALSE,
      '#weight' => 90,
    ];

    $site_default = (string) ($this->configFactory->get('ai_agent_modes.settings')->get('chatbot_position') ?: 'above_chat');
    $options = self::positionOptions();
    $form['ai_agent_modes']['ai_agent_modes_chatbot_position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Mode dropdown position'),
      '#options' => [
        '' => $this->t('- Use the site setting (@default) -', [
          '@default' => $options[$site_default] ?? $site_default,
        ]),
      ] + $options,
      '#default_value' => (string) ($entity->getThirdPartySetting('ai_agent_modes', self::POSITION_KEY) ?? ''),
      '#description' => $this->t("Where the AI Agent Modes dropdown sits in this assistant's chatbot panel. Leave it on the site setting unless this assistant needs its own placement. The site setting lives on the AI Agent Modes settings form."),
      '#weight' => 10,
    ];

    $speech = (array) ($this->configFactory->get('ai_agent_modes.settings')->get('speech_to_text') ?? []);
    $reading = (array) ($this->configFactory->get('ai_agent_modes.settings')->get('text_to_speech') ?? []);
    $microphone_default = (bool) ($speech['enabled'] ?? FALSE);
    $form['ai_agent_modes']['ai_agent_modes_microphone'] = [
      '#type' => 'radios',
      '#title' => $this->t('Microphone'),
      '#options' => [
        '' => $this->t('- Use the site setting (@default) -', [
          '@default' => $microphone_default ? $this->t('offered') : $this->t('not offered'),
        ]),
        'on' => $this->t('Offer a microphone in this chat'),
        'off' => $this->t('No microphone in this chat'),
      ],
      '#default_value' => (string) ($entity->getThirdPartySetting('ai_agent_modes', SpeechHooks::SPEECH_TO_TEXT_KEY) ?? ''),
      '#description' => $this->t("Dictation goes through the browser's own Web Speech support: this site sends nothing anywhere, but Chrome and Edge recognise in the cloud, so the audio leaves the machine through the browser. Useful on an assistant people use while doing something else, and worth leaving off an assistant used in a shared room."),
      '#weight' => 20,
    ];

    $microphone_options = SpeechHooks::positionOptions();
    $site_position = (string) ($speech['position'] ?? SpeechHooks::DEFAULT_POSITION);
    $form['ai_agent_modes']['ai_agent_modes_microphone_position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Microphone position'),
      '#options' => [
        '' => $this->t('- Use the site setting (@default) -', [
          '@default' => $microphone_options[$site_position] ?? $site_position,
        ]),
      ] + $microphone_options,
      '#default_value' => (string) ($entity->getThirdPartySetting('ai_agent_modes', SpeechHooks::POSITION_KEY) ?? ''),
      '#description' => $this->t('Left and right follow the writing direction, so a right-to-left panel mirrors them.'),
      '#weight' => 30,
    ];

    $form['ai_agent_modes']['ai_agent_modes_text_to_speech'] = [
      '#type' => 'radios',
      '#title' => $this->t('Reading replies aloud'),
      '#options' => [
        '' => $this->t('- Use the site setting (@default) -', [
          '@default' => !empty($reading['enabled']) ? $this->t('read aloud') : $this->t('not read aloud'),
        ]),
        'on' => $this->t("Read this assistant's replies aloud"),
        'off' => $this->t("Do not read this assistant's replies aloud"),
      ],
      '#default_value' => (string) ($entity->getThirdPartySetting('ai_agent_modes', SpeechHooks::TEXT_TO_SPEECH_KEY) ?? ''),
      '#description' => $this->t("Reading is the browser's own speech synthesis. It only speaks while its window is focused, which is the browser's rule, not ours."),
      '#weight' => 40,
    ];

    $form['#entity_builders'][] = [static::class, 'entityBuilder'];
  }

  /**
   * Copies the chosen placement onto the assistant entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The assistant being saved.
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function entityBuilder(string $entity_type_id, EntityInterface $entity, array &$form, FormStateInterface $form_state): void {
    if (!$entity instanceof ThirdPartySettingsInterface) {
      return;
    }
    // Falling back to the site setting means storing nothing at all, so an
    // assistant that overrode nothing keeps clean exported configuration.
    static::store(
      $entity,
      self::POSITION_KEY,
      (string) $form_state->getValue('ai_agent_modes_chatbot_position'),
      array_keys(self::positionOptions()),
    );
    static::store(
      $entity,
      SpeechHooks::SPEECH_TO_TEXT_KEY,
      (string) $form_state->getValue('ai_agent_modes_microphone'),
      ['on', 'off'],
    );
    static::store(
      $entity,
      SpeechHooks::POSITION_KEY,
      (string) $form_state->getValue('ai_agent_modes_microphone_position'),
      array_keys(SpeechHooks::positionOptions()),
    );
    static::store(
      $entity,
      SpeechHooks::TEXT_TO_SPEECH_KEY,
      (string) $form_state->getValue('ai_agent_modes_text_to_speech'),
      ['on', 'off'],
    );
  }

  /**
   * Stores one override, or removes it when the site setting is chosen.
   *
   * @param \Drupal\Core\Config\Entity\ThirdPartySettingsInterface $entity
   *   The assistant being saved.
   * @param string $key
   *   The third-party settings key.
   * @param string $value
   *   The submitted value, empty for "use the site setting".
   * @param array $allowed
   *   The values this key accepts, so a stale submission cannot be stored.
   */
  protected static function store(ThirdPartySettingsInterface $entity, string $key, string $value, array $allowed): void {
    if ($value === '' || !in_array($value, $allowed, TRUE)) {
      $entity->unsetThirdPartySetting('ai_agent_modes', $key);
      return;
    }
    $entity->setThirdPartySetting('ai_agent_modes', $key, $value);
  }

}
