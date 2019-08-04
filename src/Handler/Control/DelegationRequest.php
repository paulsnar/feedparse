<?php declare(strict_types=1);

namespace PN\FeedParse\Handler\Control;

use PN\FeedParse\Handler\HandlerInterface;

class DelegationRequest extends \Exception
{
  /** @var HandlerInterface */
  public $handler;

  public function __construct(HandlerInterface $handler)
  {
    $this->handler = $handler;
  }
}
