<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, read for the tool-scope enforcement switch.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiAgentManager $agentManager,
    protected FunctionCallPluginManager $functionCallManager,
    protected ConfigFactoryInterface $configFactory,
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
  public function listModes(?string $parent_agent_id = NULL, ?string $surface = NULL, ?string $assistant_id = NULL): array {
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
      // A mode limited to selected AI Assistants is only offered for those
      // assistants, so it is skipped when the surface has none.
      if (!$mode->appliesToAssistant($assistant_id)) {
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
      // A generic mode names sub-agent IDs that mean nothing for whichever
      // agent is running, so withholding on those names would hide every one
      // of that agent's sub-agents. Such a mode steers only.
      $strength = AiAgentModeInterface::SCOPE_GUIDE;
      if ($mode->withholdsTools()) {
        if ($mode->getAgent() !== '') {
          $strength = AiAgentModeInterface::SCOPE_RESTRICT;
        }
        else {
          $this->logger->warning('Mode "@label" is set to withhold tools but names no parent agent, so it steers only.', [
            '@label' => (string) $mode->label(),
          ]);
        }
      }
      return new ScopePayload(
        $parent,
        $sub_agents,
        $mode->getSystemPromptAddition(),
        (string) $mode->label(),
        $strength,
      );
    }

    $sub_agents = $this->intersectAvailable($parent_agent_id, $selected_sub_agents);
    if ($sub_agents === []) {
      // No valid selection means free-form: no scope.
      return NULL;
    }

    // An ad-hoc pick made in a chat never withholds anything: only a saved mode
    // an administrator wrote can do that.
    return new ScopePayload(
      $parent_agent_id,
      $sub_agents,
      '',
      '',
      AiAgentModeInterface::SCOPE_GUIDE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applyScopeToPrompt(ScopePayload $payload, string $system_prompt): string {
    $directive = $this->buildScopeDirective($payload);
    return $directive === '' ? $system_prompt : trim($directive . "\n\n" . $system_prompt);
  }

  /**
   * {@inheritdoc}
   */
  public function applyScope(ScopePayload $payload, ChatInput $input): bool {
    // Only add guiding text: prepend the directive to the system prompt so the
    // orchestrator routes the work to the selected sub-agent(s). Tools are left
    // untouched here.
    $existing = $input->getSystemPrompt();
    $new = $this->applyScopeToPrompt($payload, $existing);
    if ($new === $existing) {
      return FALSE;
    }
    $input->setSystemPrompt($new);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function restrictedTools(ScopePayload $payload, array $entity_tools): array {
    // Emergency switch: a site can turn every withholding mode back into a
    // steering one without editing any mode. Absent means enabled, so an
    // upgraded site that never got the new setting still gets the feature.
    if ($payload->scopeStrength !== AiAgentModeInterface::SCOPE_RESTRICT) {
      return [];
    }
    $enforce = $this->configFactory->get('ai_agent_modes.settings')->get('tool_scope_enforcement');
    if ($enforce !== NULL && !$enforce) {
      // Say so: without this line an administrator reading the log cannot tell
      // whether the switch or the mode itself was the reason nothing was
      // withheld.
      $this->logger->info('Mode "@label" would withhold sub-agent tools, but tool scope enforcement is switched off site-wide, so it steers only.', [
        '@label' => $payload->label !== '' ? $payload->label : $payload->parentAgent,
      ]);
      return [];
    }
    if ($payload->subAgents === []) {
      $this->logger->warning('Mode "@label" is set to withhold tools but resolved to no available sub-agent, so it steers only.', [
        '@label' => $payload->label !== '' ? $payload->label : $payload->parentAgent,
      ]);
      return [];
    }

    $definitions = $this->functionCallDefinitions();
    $map = [];
    $withheld = 0;
    foreach ($entity_tools as $tool_id => $enabled) {
      // Never re-enable a tool the agent has switched off, and never touch a
      // tool that is not a sub-agent tool: the orchestrator's own tools stay
      // exactly as configured.
      if (empty($enabled) || !is_string($tool_id) || !$this->isAgentToolId($tool_id, $definitions)) {
        $map[$tool_id] = $enabled;
        continue;
      }
      $sub_agent_id = $this->agentIdFromToolId($tool_id, $definitions);
      if ($sub_agent_id !== '' && !in_array($sub_agent_id, $payload->subAgents, TRUE)) {
        $map[$tool_id] = FALSE;
        $withheld++;
        continue;
      }
      $map[$tool_id] = $enabled;
    }

    if ($withheld === 0) {
      // The mode names everything the agent has, so there is nothing to do and
      // no reason to own the agent's override.
      return [];
    }
    if (array_filter($map) === []) {
      $this->logger->warning('Mode "@label" would withhold every tool from agent @agent, so it steers only.', [
        '@label' => $payload->label !== '' ? $payload->label : $payload->parentAgent,
        '@agent' => $payload->parentAgent,
      ]);
      return [];
    }

    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAnyMode(): bool {
    $count = $this->entityTypeManager->getStorage('ai_agent_mode')->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->count()
      ->execute();
    return (int) $count > 0;
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
      $lines[] = self::DIRECTIVE_MARKER . ' For this request, use the following sub-agent(s): ' . $labels . '.';
      $lines[] = 'Route the task to them and prefer them over other sub-agents for this conversation.';
    }
    else {
      $lines[] = self::DIRECTIVE_MARKER . ' Follow this working mode for the conversation.';
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
