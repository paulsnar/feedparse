<?php declare(strict_types=1);

namespace PN\FeedParse;

use PN\FeedParse\Handler\Control\{DelegationRequest, HandlerFinished,
  PreviousHandlerResultAware};
use PN\FeedParse\Handler\HandlerInterface;

class HandlerDispatcher
{
  protected $result, $stack, $tryHandlers;

  protected const DEFAULT_TRY_HANDLERS = [
    Handler\AtomHandler::class,
    Handler\RssHandler::class,
  ];

  public function __construct(?array $tryHandlers = null)
  {
    $this->tryHandlers = $tryHandlers ?: static::DEFAULT_TRY_HANDLERS;
  }

  public function pushHandler(HandlerInterface $handler)
  {
    if ($this->stack === null) {
      $this->stack = [$handler];
    } else {
      array_unshift($this->stack, $handler);
    }
  }

  protected function handleEvent(string $event, ...$args)
  {
    if (count($this->stack) === 0) {
      throw new \RuntimeException(
        "Document is not finished but no handler is present");
    }

    $callback = 'handle' . $event;

    /** @var HandlerInterface $handler */
    $handler = $this->stack[0];
    try {
      $handler->{$callback}(...$args);
    } catch (DelegationRequest $dr) {
      $this->pushHandler($dr->handler);
    } catch (HandlerFinished $fin) {
      $this->result = $fin->result;
      $previous = array_shift($this->stack);
      if (count($this->stack) > 0) {
        $top = $this->stack[0];
        if ($top instanceof PreviousHandlerResultAware) {
          $top->handlePreviousHandlerResult($previous, $fin->result);
        }
      }
    }
  }

  protected function tryAutodetectHandler(string $name, array $attributes)
  {
    $handler = null;
    foreach ($this->tryHandlers as $handlerClass) {
      if ($handlerClass::canHandleStart($name, $attributes)) {
        $handler = new $handlerClass();
        break;
      }
    }
    if ($handler === null) {
      throw new \RuntimeException("Unrecognized start element: {$name}");
    }
    $this->stack = [$handler];
  }

  public function handleElementStart($p, string $name, array $attributes)
  {
    if ($this->stack === null) {
      $this->tryAutodetectHandler($name, $attributes);
    }

    $this->handleEvent('ElementStart', $name, $attributes);
  }

  public function handleElementEnd($p, string $name)
  {
    $this->handleEvent('ElementEnd', $name);
  }

  public function handleChardata($p, string $chunk)
  {
    $this->handleEvent('Chardata', $chunk);
  }

  public function getResult()
  {
    return $this->result;
  }
}
