<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Integrates the mode selector into the AI Chatbot's DeepChat block.
 *
 * The `ai_chatbot` module ships two ways to place an assistant on a page: a
 * classic Drupal form (`ChatFormBlock`, handled by ChatFormHooks via
 * hook_form_ai_foundation_chat_alter) and a client-rendered `<deep-chat>` web
 * component (`DeepChatFormBlock`, plugin ID `ai_deepchat_block`). Drupal
 * CMS's own `drupal_cms_ai` recipe places the DeepChat variant by default,
 * which never runs through the Form API, so ChatFormHooks never fires for
 * it. This class adds the same soft, client-side injection approach already
 * used for the Canvas AI panel (see CanvasHooks).
 */
class ChatbotHooks implements ContainerInjectionInterface {

  /**
   * The DeepChat block plugin ID this integration targets.
   */
  protected const BLOCK_PLUGIN_ID = 'ai_deepchat_block';

  /**
   * Constructs a ChatbotHooks object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\ai_agent_modes\ModeManagerInterface $modeManager
   *   The mode manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, read for the dropdown placement.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModeManagerInterface $modeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('ai_agent_modes.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Implements hook_library_info_alter().
   *
   * Adds the selector behaviour to the DeepChat library so it loads
   * whenever the block's assets are attached.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension !== 'ai_chatbot' || !isset($libraries['deepchat'])) {
      return;
    }
    $libraries['deepchat']['dependencies'][] = 'ai_agent_modes/chatbot_deepchat';
  }

  /**
   * Implements hook_block_view_ai_deepchat_block_alter().
   *
   * Resolves the assistant's parent agent and passes it to the client-side
   * script. Does nothing unless ai_assistant_api is present, the block is
   * backed by an ai_agent, and that agent actually has something to scope.
   */
  #[Hook('block_view_ai_deepchat_block_alter')]
  public function blockViewAlter(array &$build, BlockPluginInterface $block): void {
    if (!$this->moduleHandler->moduleExists('ai_assistant_api')) {
      return;
    }
    $assistant_id = (string) ($block->getConfiguration()['ai_assistant'] ?? '');
    if ($assistant_id === '') {
      return;
    }
    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($assistant_id);
    if ($assistant === NULL) {
      return;
    }
    $agent = (string) ($assistant->get('ai_agent') ?? '');
    if ($agent === '') {
      // Legacy assistants without an agent cannot be scoped.
      return;
    }

    $has_modes = $this->modeManager->listModes($agent, ModeManagerInterface::SURFACE_ASSISTANT, $assistant_id) !== [];
    $has_sub_agents = $this->modeManager->listSubAgents($agent) !== [];
    if (!$has_modes && !$has_sub_agents) {
      return;
    }

    $build['#attached']['library'][] = 'ai_agent_modes/chatbot_deepchat';
    $build['#attached']['drupalSettings']['aiAgentModesChatbot'] = [
      'agent' => $agent,
      // The script passes this to the options endpoint so modes limited to
      // selected assistants are offered for this assistant only.
      'assistant' => $assistant_id,
      // Where the dropdown sits inside this panel: the assistant's own choice
      // when it has one, otherwise the site setting.
      'position' => $this->resolvePosition($assistant),
    ];
    $build['#cache']['contexts'][] = 'user';
    $build['#cache']['tags'][] = 'config:ai_agent_modes.settings';
  }

  /**
   * Resolves where the dropdown sits for one assistant's panel.
   *
   * @param object $assistant
   *   The ai_assistant entity.
   *
   * @return string
   *   above_chat, below_input or header.
   */
  protected function resolvePosition(object $assistant): string {
    if (method_exists($assistant, 'getThirdPartySetting')) {
      $override = (string) ($assistant->getThirdPartySetting('ai_agent_modes', AssistantSettingsHooks::POSITION_KEY) ?? '');
      if ($override !== '' && array_key_exists($override, AssistantSettingsHooks::positionOptions())) {
        return $override;
      }
    }
    return (string) ($this->configFactory->get('ai_agent_modes.settings')->get('chatbot_position') ?: 'above_chat');
  }

}
