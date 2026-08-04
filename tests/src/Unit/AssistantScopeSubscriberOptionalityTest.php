<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_agent_modes\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_agent_modes\ActiveAssistantContext;
use Drupal\ai_agent_modes\EventSubscriber\AssistantScopeSubscriber;
use Drupal\ai_agent_modes\ModeManagerInterface;
use Drupal\ai_agent_modes\SelectionStoreInterface;

/**
 * Guards the optionality of the AI Assistant API integration.
 *
 * The AI Assistant API is not a dependency of this module, and
 * getSubscribedEvents() runs while the container is compiled. A class constant
 * fetch on an absent class there would autoload it and fatal on every cache
 * rebuild, so this test is the mechanical guard against that being "tidied up"
 * into a ::EVENT_NAME reference later.
 *
 * @group ai_agent_modes
 * @coversDefaultClass \Drupal\ai_agent_modes\EventSubscriber\AssistantScopeSubscriber
 */
class AssistantScopeSubscriberOptionalityTest extends UnitTestCase {

  /**
   * The subscriber subscribes by literal event name only.
   *
   * @covers ::getSubscribedEvents
   */
  public function testSubscribesByLiteralEventName(): void {
    $events = AssistantScopeSubscriber::getSubscribedEvents();

    $this->assertCount(2, $events, 'The event list has not silently grown.');
    $this->assertSame(
      ['ai_assistant.pass_context_to_agent', 'ai_assistant.change_assistant_message'],
      array_keys($events),
    );
  }

  /**
   * The class never names anything from the optional module.
   *
   * @covers ::getSubscribedEvents
   */
  public function testSourceHasNoAssistantApiClassReference(): void {
    $file = (new \ReflectionClass(AssistantScopeSubscriber::class))->getFileName();
    $source = file_get_contents($file);

    $this->assertStringNotContainsString('Drupal\\ai_assistant_api', $source);
    $this->assertStringNotContainsString('ai_assistant_api\\', $source);
  }

  /**
   * Both handlers do nothing at all when the runner is absent.
   *
   * @covers ::onPassContextToAgent
   * @covers ::onAssistantSystemRole
   */
  public function testHandlersAreInertWithoutTheRunner(): void {
    $mode_manager = $this->createMock(ModeManagerInterface::class);
    $mode_manager->expects($this->never())->method('listModes');
    $selection_store = $this->createMock(SelectionStoreInterface::class);
    $selection_store->expects($this->never())->method('get');

    $subscriber = new AssistantScopeSubscriber(
      NULL,
      $mode_manager,
      $selection_store,
      new ActiveAssistantContext(),
      $this->createMock(LoggerChannelInterface::class),
    );

    // A stub event that would otherwise be mutated.
    $event = new class() {

      /**
       * The system prompt the subscriber would change.
       */
      public string $prompt = 'UNTOUCHED';

      /**
       * Stubs the getter the real event exposes.
       */
      public function getSystemPrompt(): string {
        return $this->prompt;
      }

      /**
       * Stubs the setter the real event exposes.
       */
      public function setSystemPrompt(string $prompt): void {
        $this->prompt = $prompt;
      }

    };

    $subscriber->onPassContextToAgent($event);
    $subscriber->onAssistantSystemRole($event);
    $this->assertSame('UNTOUCHED', $event->prompt);
  }

}
