<?php declare(strict_types=1);

namespace PN\FeedParse\Tests;

use PN\FeedParse\HandlerDispatcher;
use PN\FeedParse\Handler\HandlerInterface;
use PN\FeedParse\Handler\Control\{DelegationRequest, HandlerFinished,
  PreviousHandlerResultAware};
use PN\FeedParse\Tests\Utilities\{PassthroughHandler, StubHandler};
use PHPUnit\Framework\TestCase;

/**
 * @covers PN\FeedParse\HandlerDispatcher
 * @uses PN\FeedParse\Handler\Control\DelegationRequest
 * @uses PN\FeedParse\Handler\Control\HandlerFinished
 */
class HandlerDispatcherTest extends TestCase
{
  public function testCallsOnlyTopHandler()
  {
    $first = new class extends StubHandler
    {
      public $isCalled = false;
      public function handleElementStart(string $name, array $attrs)
      {
        $this->isCalled = true;
      }
    };
    $second = clone $first;

    $hd = new HandlerDispatcher();
    $hd->pushHandler($first);
    $hd->pushHandler($second);

    $hd->handleElementStart(null, 'element', []);

    self::assertTrue($second->isCalled);
    self::assertFalse($first->isCalled);
  }

  public function testHandlesHandlerFinishingWithResult()
  {
    $handler = new class extends StubHandler {
      public function handleElementStart(string $name, array $attrs) {
        throw new HandlerFinished('the result');
      }
    };

    $hd = new HandlerDispatcher();
    $hd->pushHandler($handler);

    $hd->handleElementStart(null, 'element', []);

    self::assertEquals('the result', $hd->getResult());
  }

  /** @depends testHandlesHandlerFinishingWithResult */
  public function testHandlesDelegation()
  {
    $handler = new class extends StubHandler
        implements PreviousHandlerResultAware {
      public $result;
      public function handleElementStart(string $name, array $attrs) {
        throw new DelegationRequest(new PassthroughHandler());
      }
      public function handleElementEnd(string $name) {
        throw new HandlerFinished($this->result);
      }
      public function handlePreviousHandlerResult(
        HandlerInterface $prev,
        $result
      ) {
        $this->result = $result;
      }
    };

    $hd = new HandlerDispatcher();
    $hd->pushHandler($handler);

    $hd->handleElementStart(null, 'top', []);
    $hd->handleElementStart(null, 'bottom', []);
    $hd->handleChardata(null, 'Hello, world!');
    $hd->handleElementEnd(null, 'bottom');
    $hd->handleElementEnd(null, 'top');

    self::assertEquals([
      ['element-start', 'bottom', []],
      ['chardata', 'Hello, world!'],
      ['element-end', 'bottom'],
    ], $hd->getResult());
  }

  public function testHandlerAutodetection()
  {
    $atom = new class extends StubHandler {
      public static function canHandleStart(string $name, array $attrs): bool {
        return $name === 'atom';
      }
      public function handleElementEnd(string $name) {
        throw new HandlerFinished('atom');
      }
    };

    $rss = new class extends StubHandler {
      public static function canHandleStart(string $name, array $attrs): bool {
        return $name === 'rss';
      }
      public function handleElementEnd(string $name) {
        throw new HandlerFinished('rss');
      }
    };

    $tryHandlers = [get_class($atom), get_class($rss)];

    $hd = new HandlerDispatcher($tryHandlers);
    $hd->handleElementStart(null, 'atom', []);
    $hd->handleElementEnd(null, 'atom');
    self::assertEquals('atom', $hd->getResult());

    $hd = new HandlerDispatcher($tryHandlers);
    $hd->handleElementStart(null, 'rss', []);
    $hd->handleElementEnd(null, 'rss');
    self::assertEquals('rss', $hd->getResult());

    $hd = new HandlerDispatcher($tryHandlers);
    $this->expectException(\RuntimeException::class);
    $hd->handleElementStart(null, 'other', []);
  }

  public function testHandlerDesyncing()
  {
    $handler = new class extends StubHandler {
      public function handleElementStart(string $name, array $attrs) {
        throw new HandlerFinished('stub');
      }
    };

    $hd = new HandlerDispatcher();
    $hd->pushHandler($handler);
    $hd->handleElementStart(null, 'hello', []);
    $this->expectException(\RuntimeException::class);
    $hd->handleElementEnd(null, 'hello');
  }
}
