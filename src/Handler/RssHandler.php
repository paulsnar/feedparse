<?php declare(strict_types=1);

namespace PN\FeedParse\Handler;

use PN\FeedParse\Parser;
use PN\FeedParse\Handler\RssHandler\ItemSubhandler;
use PN\FeedParse\Handler\Control\{DelegationRequest, HandlerFinished,
  PreviousHandlerResultAware};

class RssHandler implements HandlerInterface, PreviousHandlerResultAware
{
  use Utilities\BuffersChardata;

  const XMLNS_DUBLIN_CORE = 'http://purl.org/dc/elements/1.1/';
  protected const DC_CREATOR = self::XMLNS_DUBLIN_CORE . "\tcreator";

  public static function canHandleStart(string $name, array $attributes)
  {
    return $name === 'rss' &&
      ($attributes['version'] ?? null) === '2.0';
  }

  protected $attrs = [ 'version' => Parser::JSONFEED_1, 'items' => [ ] ];
  protected $isAtomLinkFound = false;
  protected const ATOM_LINK = AtomHandler::XMLNS_ATOM . "\tlink";

  public function handleElementStart(string $name, array $attributes)
  {
    $this->flushChardata();

    if ($name === static::ATOM_LINK) {
      $this->isAtomLinkFound = true;
      $rel = $attributes['rel'] ?? 'alternate';
      if ($rel === 'alternate') {
        $this->attrs['home_page_url'] = $attributes['href'];
      } else if ($rel === 'self') {
        $this->attrs['feed_url'] = $attributes['href'];
      }
    } else if ($name === 'item') {
      throw new DelegationRequest(new ItemSubhandler());
    } else if ($name === 'cloud') {
      if ( ! array_key_exists('hubs', $this->attrs)) {
        $this->attrs['hubs'] = [ ];
      }

      $host = $attributes['domain'];
      $port = intval($attributes['port'] ?? '80');
      $path = $attributes['path'];
      $scheme = $port === 443 ? 'https' : 'http';

      $this->attrs['hubs'][] = [
        'type' => 'rssCloud',
        'url' => "{$scheme}://{$host}:{$port}{$path}",
        '_rsscloud' => [
          'protocol' => $attributes['protocol'],
          'procedure' => $attributes['registerProcedure'],
        ],
      ];
    }
  }

  public function handleElementEnd(string $name)
  {
    switch ($name) {
      case 'title':
        $this->attrs['title'] = $this->flushChardata();
        break;

      case 'description':
        $this->attrs['description'] = $this->flushChardata();
        break;

      case 'link':
        if ( ! $this->isAtomLinkFound ||
             ! array_key_exists('home_page_url', $this->attrs)) {
          $this->attrs['home_page_url'] = $this->flushChardata();
        }
        break;

      case static::DC_CREATOR:
        $this->attrs['author'] = ['name' => $this->flushChardata()];
        break;

      case 'rss':
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
