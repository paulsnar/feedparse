<?php declare(strict_types=1);

namespace PN\FeedParse\Handler\Control;

class HandlerFinished extends \Exception
{
  public $result;

  public function __construct($result)
  {
    $this->result = $result;
  }
}
