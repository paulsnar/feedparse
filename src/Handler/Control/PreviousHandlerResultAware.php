<?php declare(strict_types=1);

namespace PN\FeedParse\Handler\Control;

use PN\FeedParse\Handler\HandlerInterface;

interface PreviousHandlerResultAware
{
  /** @param any $result */
  public function handlePreviousHandlerResult(
    HandlerInterface $previousHandler,
    $result
  );
}
