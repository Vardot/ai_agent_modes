<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_agent_modes\Form\AiAgentModeSelectorForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the AI agent mode selector as a placeable block.
 *
 * The block is decoupled from any specific chat surface: point it at an AI
 * Assistant (the agent is read from the assistant, the same way the chatbot
 * block is configured) or at a parent agent directly.
 */
#[Block(
  id: 'ai_agent_mode_selector',
  admin_label: new TranslatableMarkup('AI Agent Mode selector'),
  category: new TranslatableMarkup('AI'),
)]
class AiAgentModeSelectorBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The form builder.
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->formBuilder = $container->get('form_builder');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'ai_assistant' => '',
      'parent_agent' => '',
      'surface' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['ai_assistant'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Assistant'),
      '#description' => $this->t('Read the parent agent from this AI Assistant. Takes precedence over the parent agent field below.'),
      '#options' => ['' => $this->t('- None -')] + $this->assistantOptions(),
      '#default_value' => $this->configuration['ai_assistant'],
    ];
    $form['parent_agent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent agent'),
      '#description' => $this->t('Used when no AI Assistant is selected. The agent plugin ID, e.g. canvas_ai_orchestrator.'),
      '#default_value' => $this->configuration['parent_agent'],
    ];
    $form['surface'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Surface'),
      '#description' => $this->t('Optional surface ID used to filter which modes are shown.'),
      '#default_value' => $this->configuration['surface'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['ai_assistant'] = (string) $form_state->getValue('ai_assistant');
    $this->configuration['parent_agent'] = (string) $form_state->getValue('parent_agent');
    $this->configuration['surface'] = (string) $form_state->getValue('surface');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $agent = $this->resolveAgent();
    return $this->formBuilder->getForm(
      AiAgentModeSelectorForm::class,
      $agent,
      $this->configuration['surface'],
    );
  }

  /**
   * Resolves the parent agent from the assistant, or the direct setting.
   *
   * @return string
   *   The parent agent plugin ID, or an empty string.
   */
  protected function resolveAgent(): string {
    $assistant_id = $this->configuration['ai_assistant'];
    if ($assistant_id !== '' && $this->entityTypeManager->hasDefinition('ai_assistant')) {
      $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($assistant_id);
      if ($assistant !== NULL && (string) $assistant->get('ai_agent') !== '') {
        return (string) $assistant->get('ai_agent');
      }
    }
    return (string) $this->configuration['parent_agent'];
  }

  /**
   * Builds the AI Assistant select options.
   *
   * @return array<string, string>
   *   Assistant labels keyed by ID, or an empty list when none exist.
   */
  protected function assistantOptions(): array {
    if (!$this->entityTypeManager->hasDefinition('ai_assistant')) {
      return [];
    }
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_assistant')->loadMultiple() as $id => $assistant) {
      $options[$id] = (string) $assistant->label();
    }
    asort($options);
    return $options;
  }

}
