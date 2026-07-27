<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai_agents\PluginManager\AiAgentManager;

/**
 * Default implementation of the mode manager.
 */
class ModeManager implements ModeManagerInterface {

  /**
   * The prefix ai_agents uses for the sub-agent tool plugin IDs.
   */
  protected const AGENT_TOOL_PREFIX = 'ai_agents::ai_agent::';

  /**
   * Constructs a ModeManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $agentManager
   *   The AI agents plugin manager.
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   The function call plugin manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list, used to locate prompt files.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiAgentManager $agentManager,
    protected FunctionCallPluginManager $functionCallManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function listSubAgents(string $parent_agent_id): array {
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    $agent = $storage->load($parent_agent_id);
    if ($agent === NULL) {
      return [];
    }

    $tools = $agent->get('tools');
    if (!is_array($tools)) {
      return [];
    }

    $definitions = $this->functionCallDefinitions();
    $sub_agents = [];
    foreach ($tools as $tool_id => $enabled) {
      if (empty($enabled) || !is_string($tool_id)) {
        continue;
      }
      if (!$this->isAgentToolId($tool_id, $definitions)) {
        continue;
      }
      $sub_agent_id = $this->agentIdFromToolId($tool_id, $definitions);
      if ($sub_agent_id === '') {
        continue;
      }
      $definition = $definitions[$tool_id] ?? [];
      $child = $storage->load($sub_agent_id);
      $label = (string) ($definition['name'] ?? ($child?->label() ?? $sub_agent_id));
      $description = (string) ($definition['description'] ?? ($child?->get('description') ?? ''));
      $sub_agents[$sub_agent_id] = [
        'id' => $sub_agent_id,
        'label' => $label,
        'description' => $description,
        'tool_id' => $tool_id,
      ];
    }

    return $sub_agents;
  }

  /**
   * {@inheritdoc}
   */
  public function listModes(?string $parent_agent_id = NULL, ?string $surface = NULL): array {
    $storage = $this->entityTypeManager->getStorage('ai_agent_mode');
    /** @var \Drupal\ai_agent_modes\AiAgentModeInterface[] $modes */
    $modes = $storage->loadByProperties(['status' => TRUE]);

    $matches = [];
    foreach ($modes as $mode) {
      $mode_agent = $mode->getAgent();
      if ($parent_agent_id !== NULL && $mode_agent !== '' && $mode_agent !== $parent_agent_id) {
        continue;
      }
      if ($surface !== NULL && !$mode->appliesToSurface($surface)) {
        continue;
      }
      $matches[$mode->id()] = $mode;
    }

    uasort($matches, static function ($a, $b): int {
      return [$a->get('weight'), (string) $a->label()] <=> [$b->get('weight'), (string) $b->label()];
    });

    return array_values($matches);
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(string $parent_agent_id, array $selected_sub_agents = [], ?string $mode_id = NULL): ?ScopePayload {
    // A saved mode wins over an ad-hoc selection.
    if ($mode_id !== NULL && $mode_id !== '') {
      /** @var \Drupal\ai_agent_modes\AiAgentModeInterface|null $mode */
      $mode = $this->entityTypeManager->getStorage('ai_agent_mode')->load($mode_id);
      if ($mode === NULL || !$mode->status()) {
        return NULL;
      }
      $parent = $mode->getAgent() !== '' ? $mode->getAgent() : $parent_agent_id;
      $sub_agents = $this->intersectAvailable($parent, $mode->getSubAgents());
      // A mode may steer through its prompt directive alone, for example
      // toward the orchestrator's own tools (Figma tools) rather than a
      // sub-agent.
      if ($sub_agents === [] && trim($mode->getSystemPromptAddition()) === '') {
        return NULL;
      }
      return new ScopePayload(
        $parent,
        $sub_agents,
        $mode->getSystemPromptAddition(),
        (string) $mode->label(),
      );
    }

    $sub_agents = $this->intersectAvailable($parent_agent_id, $selected_sub_agents);
    if ($sub_agents === []) {
      // No valid selection means free-form: no scope.
      return NULL;
    }

    return new ScopePayload($parent_agent_id, $sub_agents);
  }

  /**
   * {@inheritdoc}
   */
  public function applyScope(ScopePayload $payload, ChatInput $input): bool {
    $directive = $this->buildScopeDirective($payload);
    if ($directive === '') {
      return FALSE;
    }
    // Only add guiding text: prepend the directive to the system prompt so the
    // orchestrator routes the work to the selected sub-agent(s). Tools are left
    // untouched.
    $existing = $input->getSystemPrompt() ?? '';
    $input->setSystemPrompt(trim($directive . "\n\n" . $existing));
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildScopeDirective(ScopePayload $payload): string {
    if (!$payload->isRestrictive()) {
      return '';
    }
    $lines = [];
    if ($payload->subAgents !== []) {
      $labels = implode(', ', $payload->subAgents);
      $lines[] = 'MODE (AI Agent Modes): For this request, use the following sub-agent(s): ' . $labels . '.';
      $lines[] = 'Route the task to them and prefer them over other sub-agents for this conversation.';
    }
    else {
      $lines[] = 'MODE (AI Agent Modes): Follow this working mode for the conversation.';
    }
    if ($payload->systemPromptAddition !== '') {
      $lines[] = trim($payload->systemPromptAddition);
    }
    return implode("\n", $lines);
  }

  /**
   * Intersects a requested set of sub-agents with the agent's live sub-agents.
   *
   * @param string $parent_agent_id
   *   The parent agent plugin ID.
   * @param string[] $requested
   *   The requested sub-agent plugin IDs.
   *
   * @return string[]
   *   The requested sub-agents that are actually available, in stable order.
   */
  protected function intersectAvailable(string $parent_agent_id, array $requested): array {
    if ($requested === []) {
      return [];
    }
    $available = $this->listSubAgents($parent_agent_id);
    $result = [];
    foreach ($requested as $id) {
      if (is_string($id) && isset($available[$id])) {
        $result[] = $id;
      }
    }
    return array_values(array_unique($result));
  }

  /**
   * Loads the function-call plugin definitions, tolerating a broken plugin.
   *
   * @return array<string, array<string, mixed>>
   *   Definitions keyed by plugin ID.
   */
  protected function functionCallDefinitions(): array {
    try {
      return $this->functionCallManager->getDefinitions();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not load function call definitions: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Determines whether a tool ID is a sub-agent tool.
   *
   * @param string $tool_id
   *   The tool plugin ID.
   * @param array<string, array<string, mixed>> $definitions
   *   The function-call definitions.
   *
   * @return bool
   *   TRUE when the tool wraps an agent.
   */
  protected function isAgentToolId(string $tool_id, array $definitions): bool {
    if (isset($definitions[$tool_id]['group']) && $definitions[$tool_id]['group'] === self::AGENT_TOOL_GROUP) {
      return TRUE;
    }
    // Fall back to the known ID convention when the definition is unavailable.
    return str_starts_with($tool_id, self::AGENT_TOOL_PREFIX);
  }

  /**
   * Resolves the child agent ID a sub-agent tool wraps.
   *
   * @param string $tool_id
   *   The tool plugin ID.
   * @param array<string, array<string, mixed>> $definitions
   *   The function-call definitions.
   *
   * @return string
   *   The child agent plugin ID, or an empty string.
   */
  protected function agentIdFromToolId(string $tool_id, array $definitions): string {
    if (!empty($definitions[$tool_id]['function_name'])) {
      return (string) $definitions[$tool_id]['function_name'];
    }
    $position = strrpos($tool_id, '::');
    return $position === FALSE ? '' : substr($tool_id, $position + 2);
  }

}
