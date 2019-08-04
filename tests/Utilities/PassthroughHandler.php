<?php declare(strict_types=1);

namespace PN\FeedParse\Tests\Utilities;

use PN\FeedParse\Handler\HandlerInterface;
use PN\FeedParse\Handler\Control\HandlerFinished;

class PassthroughHandler implements HandlerInterface
{
  protected $events = [ ], $nesting = 0;

  public function handleElementStart(string $name, array $attributes)
  {
    $this->events[] = ['element-start', $name, $attributes];
    $this->nesting += 1;
  }

  public function handleElementEnd(string $name)
  {
    $this->events[] = ['element-end', $name];
    $this->nesting -= 1;
    if ($this->nesting === 0) {
      throw new HandlerFinished($this->events);
    }
  }

  public function handleChardata(string $chunk)
  {
    $this->events[] = ['chardata', $chunk];
  }
}
