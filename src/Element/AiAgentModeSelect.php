<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Element;

use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\Select;

/**
 * Provides a "Mode" select populated from a parent agent's live sub-agents.
 *
 * Value format:
 * - '': free-form (no scope; the full set of sub-agents).
 * - 'mode:<mode_id>': a saved curated mode.
 * - 'agent:<sub_agent_id>': a single ad-hoc sub-agent.
 *
 * Properties:
 * - '#parent_agent': (string) the parent agent plugin ID. Required.
 * - '#surface': (string) an optional surface ID used to filter saved modes.
 *
 * Usage example:
 * @code
 * $form['mode'] = [
 *   '#type' => 'ai_agent_mode_select',
 *   '#parent_agent' => 'canvas_ai_orchestrator',
 *   '#surface' => 'canvas',
 * ];
 * @endcode
 */
#[FormElement('ai_agent_mode_select')]
class AiAgentModeSelect extends Select {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    $info = parent::getInfo();
    $info['#parent_agent'] = '';
    $info['#surface'] = '';
    $info['#title'] = $this->t('Mode');
    array_unshift($info['#process'], [static::class, 'processAgentModeOptions']);
    return $info;
  }

  /**
   * Builds the option list from the parent agent's live sub-agents and modes.
   *
   * @param array $element
   *   The element being processed.
   *
   * @return array
   *   The processed element.
   */
  public static function processAgentModeOptions(array &$element): array {
    $parent_agent = (string) ($element['#parent_agent'] ?? '');
    $surface = ($element['#surface'] ?? '') !== '' ? (string) $element['#surface'] : NULL;

    $options = ['' => (string) t('Free-form (all sub-agents)')];

    if ($parent_agent !== '') {
      /** @var \Drupal\ai_agent_modes\ModeManagerInterface $manager */
      $manager = \Drupal::service('ai_agent_modes.manager');

      $mode_options = [];
      foreach ($manager->listModes($parent_agent, $surface) as $mode) {
        $mode_options['mode:' . $mode->id()] = (string) $mode->label();
      }
      if ($mode_options !== []) {
        $options[(string) t('Modes')] = $mode_options;
      }

      $agent_options = [];
      foreach ($manager->listSubAgents($parent_agent) as $id => $info) {
        $agent_options['agent:' . $id] = $info['label'];
      }
      if ($agent_options !== []) {
        $options[(string) t('Sub-agents')] = $agent_options;
      }
    }

    $element['#options'] = $options;
    $element['#cache']['tags'][] = 'config:ai_agent_mode_list';
    $element['#cache']['tags'][] = 'config:ai_agent_list';
    return $element;
  }

}
