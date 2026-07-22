<?php

declare(strict_types=1);

namespace Drupal\ai_agent_modes;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists AI agent mode config entities.
 */
class AiAgentModeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('weight')
      ->sort('label');
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Mode');
    $header['agent'] = $this->t('Parent agent');
    $header['sub_agents'] = $this->t('Sub-agents');
    $header['status'] = $this->t('Enabled');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_agent_modes\AiAgentModeInterface $entity */
    $row['label'] = $entity->label();
    $row['agent'] = $entity->getAgent() !== '' ? $entity->getAgent() : $this->t('Any (generic)');
    $sub_agents = $entity->getSubAgents();
    $row['sub_agents'] = $sub_agents === [] ? $this->t('None') : implode(', ', $sub_agents);
    $row['status'] = $entity->status() ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No AI agent modes yet. Add a mode to expose a curated subset of an agent’s sub-agents.');
    return $build;
  }

}
