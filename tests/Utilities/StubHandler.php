<?php declare(strict_types=1);

namespace PN\FeedParse\Tests\Utilities;

use PN\FeedParse\Handler\HandlerInterface;

class StubHandler implements HandlerInterface
{
  public function handleElementStart(string $name, array $attributes)
  {
  }

  public function handleElementEnd(string $name)
  {
  }

  public function handleChardata(string $chunk)
  {
  }
}
