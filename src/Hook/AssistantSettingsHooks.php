<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets one AI Assistant override where its mode dropdown sits.
 *
 * The site-wide placement on the module's own settings form is the default. An
 * assistant that wants its dropdown somewhere else carries the choice as a
 * third-party setting on its own config entity, which is the supported way to
 * add a setting to another module's configuration entity, and which travels
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

    $site_default = (string) ($this->configFactory->get('ai_agent_modes.settings')->get('chatbot_position') ?: 'above_chat');
    $options = self::positionOptions();
    $form['ai_agent_modes_chatbot_position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Mode dropdown position'),
      '#options' => [
        '' => $this->t('- Use the site setting (@default) -', [
          '@default' => $options[$site_default] ?? $site_default,
        ]),
      ] + $options,
      '#default_value' => (string) ($entity->getThirdPartySetting('ai_agent_modes', self::POSITION_KEY) ?? ''),
      '#description' => $this->t("Where the AI Agent Modes dropdown sits in this assistant's chatbot panel. Leave it on the site setting unless this assistant needs its own placement. The site setting lives on the AI Agent Modes settings form."),
      '#weight' => 90,
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
    if (!method_exists($entity, 'setThirdPartySetting')) {
      return;
    }
    $value = (string) $form_state->getValue('ai_agent_modes_chatbot_position');
    if ($value === '' || !array_key_exists($value, self::positionOptions())) {
      // Falling back to the site setting means storing nothing at all, so an
      // assistant that never overrode the placement keeps clean exported
      // configuration.
      $entity->unsetThirdPartySetting('ai_agent_modes', self::POSITION_KEY);
      return;
    }
    $entity->setThirdPartySetting('ai_agent_modes', self::POSITION_KEY, $value);
  }

}
