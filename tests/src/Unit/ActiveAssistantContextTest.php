<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_agent_modes\ActiveAssistantContext;

/**
 * Tests the request-scoped assistant identity carrier.
 *
 * @group ai_agent_modes
 * @coversDefaultClass \Drupal\ai_agent_modes\ActiveAssistantContext
 */
class ActiveAssistantContextTest extends UnitTestCase {

  /**
   * A noted assistant is readable by its thread.
   *
   * @covers ::note
   * @covers ::assistantFor
   */
  public function testNoteAndRead(): void {
    $context = new ActiveAssistantContext();
    $context->note('thread-1', 'assistant_a');
    $context->note('thread-2', 'assistant_b');

    $this->assertSame('assistant_a', $context->assistantFor('thread-1'));
    $this->assertSame('assistant_b', $context->assistantFor('thread-2'));
  }

  /**
   * An unknown thread reads as NULL, which means unknown and never "none".
   *
   * @covers ::assistantFor
   */
  public function testUnknownThreadIsNull(): void {
    $context = new ActiveAssistantContext();
    $this->assertNull($context->assistantFor('never-noted'));
    $this->assertNull($context->assistantFor(NULL));
    $this->assertNull($context->assistantFor(''));
  }

  /**
   * Empty identifiers are ignored rather than stored.
   *
   * @covers ::note
   */
  public function testEmptyValuesAreIgnored(): void {
    $context = new ActiveAssistantContext();
    $context->note('', 'assistant_a');
    $context->note('thread-1', '');

    $this->assertNull($context->assistantFor(''));
    $this->assertNull($context->assistantFor('thread-1'));
  }

  /**
   * A later note for the same thread wins.
   *
   * @covers ::note
   */
  public function testLastNoteWins(): void {
    $context = new ActiveAssistantContext();
    $context->note('thread-1', 'assistant_a');
    $context->note('thread-1', 'assistant_b');
    $this->assertSame('assistant_b', $context->assistantFor('thread-1'));
  }

}
