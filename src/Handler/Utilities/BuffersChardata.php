<?php declare(strict_types=1);

namespace PN\FeedParse\Handler\Utilities;

trait BuffersChardata
{
  protected $chardata;

  public function handleChardata(string $chunk)
  {
    if ($this->chardata === null) {
      $this->chardata = $chunk;
    } else {
      $this->chardata .= $chunk;
    }
  }

  protected function flushChardata(): ?string
  {
    try {
      return $this->chardata;
    } finally {
      $this->chardata = null;
    }
  }
}
