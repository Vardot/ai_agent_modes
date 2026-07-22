<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Integrates the mode selector into the Drupal Canvas AI chat panel.
 *
 * The Canvas AI panel renders its chat (with the upload-image button) as a
 * deep-chat web component inside the Canvas React app, whose HTML shell does
 * not run the normal page-attachment pipeline. So instead of attaching to the
 * page, the selector JS is added as a dependency of the Canvas editor bundle
 * (canvas/canvas-ui) and injects the dropdown client-side. The integration is
 * soft: it does nothing unless the Canvas AI module is present, and the JS
 * only injects when the orchestrator actually exposes sub-agents.
 */
class CanvasHooks implements ContainerInjectionInterface {

  /**
   * The Canvas editor bundle library the selector attaches to.
   */
  protected const CANVAS_UI_LIBRARY = 'canvas-ui';

  /**
   * Constructs a CanvasHooks object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler'),
    );
  }

  /**
   * Implements hook_library_info_alter().
   *
   * Adds the selector behaviour to the Canvas editor bundle so it loads
   * whenever the Canvas AI panel is available.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension !== 'canvas' || !isset($libraries[self::CANVAS_UI_LIBRARY])) {
      return;
    }
    if (!$this->moduleHandler->moduleExists('canvas_ai')) {
      return;
    }
    $libraries[self::CANVAS_UI_LIBRARY]['dependencies'][] = 'ai_agent_modes/canvas_ai';
  }

}
