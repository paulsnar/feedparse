<?php declare(strict_types=1);

namespace PN\FeedParse\Handler;

interface HandlerInterface
{
  public function handleElementStart(string $name, array $attributes);
  public function handleElementEnd(string $name);
  public function handleChardata(string $chunk);
}
