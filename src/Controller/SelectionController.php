<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\SelectionStoreInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves and persists the active mode selection for chat interfaces.
 */
class SelectionController extends ControllerBase {

  /**
   * Constructs a SelectionController.
   *
   * @param \Drupal\ai_agent_modes\SelectionStoreInterface $selectionStore
   *   The selection store.
   * @param \Drupal\ai_agent_modes\ModeManagerInterface $modeManager
   *   The mode manager.
   */
  public function __construct(
    protected SelectionStoreInterface $selectionStore,
    protected ModeManagerInterface $modeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_agent_modes.selection_store'),
      $container->get('ai_agent_modes.manager'),
    );
  }

  /**
   * Returns the selector options for an agent as JSON.
   *
   * Used by chat surfaces that render the dropdown client-side (e.g. the
   * Drupal Canvas AI panel, whose input lives in a web-component shadow DOM).
   *
   * An `assistant` query parameter names the AI Assistant the surface is
   * backed by, when there is one. Modes limited to selected assistants are
   * offered for the named assistant only, and are left out when the parameter
   * is absent (as it is for the Drupal Canvas AI panel, which is driven by an
   * agent rather than by an assistant).
   *
   * @param string $agent
   *   The parent agent plugin ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The options and the currently active value.
   */
  public function options(string $agent, Request $request): JsonResponse {
    $assistant = (string) $request->query->get('assistant', '');
    // This list is read by site builders and clients in the chat, so it speaks
    // in tasks, not in agent machinery. The raw sub-agents are deliberately
    // NOT offered here: they duplicate what the modes cover, and their names
    // ("Drupal Canvas Metadata Generation Agent") mean nothing to a person
    // building a page. Developers still see them in the admin UI.
    $options = [
      ['value' => '', 'label' => (string) $this->t('All, let the assistant decide'), 'group' => ''],
    ];
    foreach ($this->modeManager->listModes($agent, NULL, $assistant !== '' ? $assistant : NULL) as $mode) {
      $options[] = [
        'value' => 'mode:' . $mode->id(),
        'label' => (string) $mode->label(),
        'group' => '',
      ];
    }

    $current = $this->selectionStore->get($agent);
    $value = '';
    if ($current !== NULL) {
      if (!empty($current['mode_id'])) {
        $value = 'mode:' . $current['mode_id'];
      }
      elseif (!empty($current['sub_agents'])) {
        $value = 'agent:' . reset($current['sub_agents']);
      }
    }

    return new JsonResponse([
      'agent' => $agent,
      'assistant' => $assistant,
      'value' => $value,
      'options' => $options,
      // Where the Canvas AI panel should place the dropdown - riding on this
      // response keeps the placement uncacheable-config-free client side (the
      // JS already fetches it before injecting).
      'position' => $this->config('ai_agent_modes.settings')->get('canvas_position') ?: 'toolbar',
    ]);
  }

  /**
   * Stores the mode selection sent from the chat dropdown.
   *
   * Expects a JSON body: {"agent": "...", "value": "", "conversation": "..."}
   * where value is '', 'mode:<id>' or 'agent:<id>'.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response describing the stored selection.
   */
  public function set(Request $request): JsonResponse {
    $data = json_decode($request->getContent() ?: '{}', TRUE);
    $agent = is_array($data) ? (string) ($data['agent'] ?? '') : '';
    $value = is_array($data) ? (string) ($data['value'] ?? '') : '';
    $conversation = is_array($data) && !empty($data['conversation']) ? (string) $data['conversation'] : NULL;

    if ($agent === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Missing agent.'], 400);
    }

    if ($value === '') {
      $this->selectionStore->clear($agent, $conversation);
      return new JsonResponse(['status' => 'cleared', 'agent' => $agent]);
    }
    if (str_starts_with($value, 'mode:')) {
      $this->selectionStore->set($agent, [], substr($value, 5), $conversation);
    }
    elseif (str_starts_with($value, 'agent:')) {
      $this->selectionStore->set($agent, [substr($value, 6)], NULL, $conversation);
    }
    else {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid value.'], 400);
    }

    return new JsonResponse(['status' => 'applied', 'agent' => $agent, 'value' => $value]);
  }

}
