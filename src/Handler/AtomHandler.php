<?php declare(strict_types=1);

namespace PN\FeedParse\Handler;

use PN\FeedParse\Parser;
use PN\FeedParse\Handler\AtomHandler\ItemSubhandler;
use PN\FeedParse\Handler\Control\{DelegationRequest, HandlerFinished,
  PreviousHandlerResultAware};

class AtomHandler implements HandlerInterface, PreviousHandlerResultAware
{
  use Utilities\BuffersChardata;

  public const XMLNS_ATOM = 'http://www.w3.org/2005/Atom';

  protected const START_NAME = self::XMLNS_ATOM . "\tfeed";

  public static function canHandleStart(string $name, array $attributes)
  {
    return $name === static::START_NAME;
  }

  protected $attrs = [ 'version' => Parser::JSONFEED_1, 'items' => [ ] ];
  protected $amParsingAuthor = false;

  protected function handleLink(array $attributes)
  {
    $rel = $attributes['rel'] ?? 'alternate';
    $href = $attributes['href'];
    if ($rel === 'alternate') {
      $this->attrs['home_page_url'] = $href;
    } else if ($rel === 'self') {
      $this->attrs['feed_url'] = $href;
    } else if ($rel === 'hub') {
      if ( ! array_key_exists('hubs', $this->attrs)) {
        $this->attrs['hubs'] = [ ];
      }
      $this->attrs['hubs'][] = ['type' => 'WebSub', 'url' => $href];
    }
  }

  public function handleElementStart(string $name, array $attributes)
  {
    $this->flushChardata(); // drop insignificant whitespace, if any

    $name = substr($name, 28); // 28 == strlen(XMLNS_ATOM . "\t")
    switch ($name) {
      case 'link':
        $this->handleLink($attributes);
        break;

      case 'author':
        if ( ! array_key_exists('author', $this->attrs)) {
          $this->attrs['author'] = [ ];
        }
        $this->amParsingAuthor = true;
        break;

      case 'entry':
        throw new DelegationRequest(new ItemSubhandler());
    }
  }

  public function handleElementEnd(string $name)
  {
    $name = substr($name, 28);

    if ($this->amParsingAuthor) {
      switch ($name) {
        case 'name':
          $this->attrs['author']['name'] = $this->flushChardata();
          break;

        case 'uri':
          $this->attrs['author']['url'] = $this->flushChardata();
          break;

        case 'email':
          if ( ! array_key_exists('url', $this->attrs['author'])) {
            $this->attrs['author']['url'] = 'mailto:' . $this->flushChardata();
          }
          break;

        case 'author':
          $this->amParsingAuthor = false;
          break;
      }

      return;
    }

    switch ($name) {
      case 'title':
        $this->attrs['title'] = $this->flushChardata();
        break;

      case 'subtitle':
        $this->attrs['description'] = $this->flushChardata();
        break;

      case 'feed':
        throw new HandlerFinished($this->attrs);
    }
  }

  public function handlePreviousHandlerResult(
    HandlerInterface $previousHandler,
    $result
  ) {
    if ($previousHandler instanceof ItemSubhandler) {
      $this->attrs['items'][] = $result;
    }
  }
}
