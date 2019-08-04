<?php declare(strict_types=1);

namespace PN\FeedParse\Handler\RssHandler;

use PN\FeedParse\Handler\{AtomHandler, HandlerInterface, RssHandler};
use PN\FeedParse\Handler\Control\HandlerFinished;
use PN\FeedParse\Handler\Utilities\BuffersChardata;

class ItemSubhandler implements HandlerInterface
{
  use BuffersChardata;

  protected $attrs = [ ];
  protected $categoryDomain = null;
  protected $isAtomLinkFound = false;
  protected $hasContentEncoded = false;

  const XMLNS_RSS1_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

  protected const ATOM_LINK = AtomHandler::XMLNS_ATOM . "\tlink";
  protected const CONTENT_ENCODED = self::XMLNS_RSS1_CONTENT . "\tencoded";
  protected const DC_CREATOR = RssHandler::XMLNS_DUBLIN_CORE . "\tcreator";

  public function handleElementStart(string $name, array $attributes)
  {
    $this->flushChardata();

    switch ($name) {
      case static::ATOM_LINK:
        $this->isAtomLinkFound = true;
        $rel = $attributes['rel'] ?? 'alternate';
        if ($rel === 'alternate') {
          $this->attrs['url'] = $attributes['href'];
        } else if ($rel === 'related') {
          $this->attrs['external_url'] = $attributes['href'];
        }
        break;

      case static::CONTENT_ENCODED:
        $this->hasContentEncoded = true;
        break;

      case 'source':
        $this->attrs['_fp_item_source'] = [
          'feed_url' => $attributes['url'],
        ];
        break;

      case 'enclosure':
        if ( ! array_key_exists('attachments', $this->attrs)) {
          $this->attrs['attachments'] = [ ];
        }
        $this->attrs['attachments'][] = [
          'url' => $attributes['url'],
          'size_in_bytes' => intval($attributes['length']),
          'mime_type' => $attributes['type'],
        ];
        break;

      case 'category':
        if (array_key_exists('domain', $attributes)) {
          $this->categoryDomain = $attributes['domain'];
        }
        break;

      case 'guid':
        $this->guidIsPermalink =
          ($attributes['isPermaLink'] ?? 'true') === 'true';
    }
  }

  public function handleElementEnd(string $name)
  {
    switch ($name) {
      case 'title':
        $this->attrs['title'] = $this->flushChardata();
        break;

      case static::CONTENT_ENCODED:
        // If both <description> and <content:encoded> are present,
        // then use <description> for the summary. This is common with, e.g.,
        // some podcasts, where <description> is just the short summary, while
        // <content:encoded> contains the full show notes.
        // This does not hold true for Squarespace, however; the tag is present,
        // but its content might be empty, with the actual show notes contained
        // within <description>.
        $body = $this->flushChardata();
        if (trim($body) === '') {
          $this->hasContentEncoded = false;
          break;
        }
        if (array_key_exists('content_text', $this->attrs)) {
          $this->attrs['summary'] = $this->attrs['content_text'];
          unset($this->attrs['content_text']);
        }
        $this->attrs['content_html'] = $body;
        break;

      case static::DC_CREATOR:
        if ( ! array_key_exists('author', $this->attrs)) {
          $this->attrs['author'] = [ ];
        }
        $this->attrs['author']['name'] = $this->flushChardata();
        break;

      case 'description':
        $description = $this->flushChardata();
        if ($this->hasContentEncoded) {
          $this->attrs['summary'] = $description;
        } else if (preg_match('/<.+>|&#?[A-Za-z0-9]+;/', $description) === 1) {
          $this->attrs['content_html'] = $description;
        } else {
          $this->attrs['content_text'] = $description;
        }
        break;

      case 'link':
        if ( ! $this->isAtomLinkFound) {
          $this->attrs['url'] = $this->flushChardata();
        }
        break;

      case 'source':
        $this->attrs['_fp_item_source']['title'] = $this->flushChardata();
        break;

      case 'category':
        if ( ! array_key_exists('tags', $this->attrs)) {
          $this->attrs['tags'] = [ ];
        }
        $categories = explode('/', $this->flushChardata());
        foreach ($categories as $category) {
          if ($this->categoryDomain !== null) {
            $category = $this->categoryDomain . '/' . $category;
          }
          $this->attrs['tags'][] = $category;
        }
        $this->categoryDomain = null;
        break;

      case 'guid':
        $guid = $this->flushChardata();
        if ($this->guidIsPermalink && ! $this->isAtomLinkFound &&
            ! array_key_exists('url', $this->attrs)) {
          $this->attrs['url'] = $guid;
        }
        $this->attrs['id'] = $guid;
        $this->guidIsPermalink = null;
        break;

      case 'pubDate':
        $date = $this->flushCharData();
        $dt = \DateTimeImmutable::createFromFormat(
          \DateTimeInterface::RFC2822,
          $date);
        if ($dt === false) {
          throw new \RuntimeException("Bad date format: {$date}");
        }
        if ($dt->getOffset() !== 0) {
          $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
        }
        $dt = $dt->format('Y-m-d\\TH:i:s\\Z');
        $this->attrs['date_published'] = $dt;
        break;

      case 'item':
        throw new HandlerFinished($this->attrs);
    }
  }
}
