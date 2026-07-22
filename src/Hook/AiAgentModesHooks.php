<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for the AI Agent Modes module.
 */
class AiAgentModesHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name === 'help.page.ai_agent_modes' || $route_name === 'entity.ai_agent_mode.collection') {
      $output = '<p>' . $this->t('AI Agent Modes adds a "Mode" selector in front of any AI agent chat. Selecting a mode restricts the orchestrator to a curated subset of its sub-agents, with a tight scoped context, so the model stops enumerating every sub-agent and stops hallucinating component props.') . '</p>';
      $output .= '<p>' . $this->t('A mode is a config entity: a saved subset of one agent’s sub-agents. You can also select a single sub-agent ad hoc from the dropdown. The selection persists for the conversation.') . '</p>';
      return $output;
    }
    return '';
  }

}
