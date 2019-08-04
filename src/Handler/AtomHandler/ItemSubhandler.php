<?php declare(strict_types=1);

namespace PN\FeedParse\Handler\AtomHandler;

use PN\FeedParse\Handler\HandlerInterface;
use PN\FeedParse\Handler\Control\{DelegationRequest, HandlerFinished,
  PreviousHandlerResultAware};
use PN\FeedParse\Handler\Utilities\BuffersChardata;

class ItemSubhandler implements HandlerInterface, PreviousHandlerResultAware
{
  use BuffersChardata;

  protected $attrs = [ ];
  protected $author = null, $amParsingAuthor = false;
  protected $currentContentType = null;
  protected $source = null, $amParsingSource = false;

  protected function handleLink(array $attributes)
  {
    $rel = $attributes['rel'] ?? 'alternate';
    $href = $attributes['href'];
    if ($rel === 'alternate') {
      $this->attrs['url'] = $href;
    } else if ($rel === 'related') {
      $this->attrs['external_url'] = $href;
    } else if ($rel === 'enclosure') {
      if ( ! array_key_exists('attachments', $this->attrs)) {
        $this->attrs['attachments'] = [ ];
      }
      $attachment = ['url' => $attributes['href']];
      if (array_key_exists('length', $attributes)) {
        $attachment['size_in_bytes'] = intval($attributes['length']);
      }
      if (array_key_exists('type', $attributes)) {
        $attachment['mime_type'] = $attributes['type'];
      }
      $this->attrs['attachments'][] = $attachment;
    }
  }

  protected function handleSourceLink(array $attributes)
  {
    $rel = $attributes['rel'] ?? 'alternate';
    $href = $attributes['href'];
    if ($rel === 'alternate') {
      $this->source['home_page_url'] = $href;
    } else if ($rel === 'self') {
      $this->source['feed_url'] = $href;
    }
  }

  public function handleElementStart(string $name, array $attributes)
  {
    $this->flushChardata(); // drop insignificant whitespace, if any

    $name = substr($name, 28); // 28 == strlen(XMLNS_ATOM . "\t")

    if ($this->amParsingSource && $name === 'link') {
      $this->handleSourceLink($attributes);
      return;
    }

    switch ($name) {
      case 'content':
        $type = $attributes['type'] ?? 'text';

        if ($type === 'text/html') {
          $type = 'html';
        } else if ($type === 'text/plain') {
          $type = 'text';
        }

        if ($type === 'text' || $type === 'html') {
          $this->currentContentType = $type;
        } else if ($type === 'xhtml') {
          $this->currentContentType = 'html';
          throw new DelegationRequest(new XhtmlBufferSubhandler());
        } else {
          // TODO
          throw new \RuntimeException("Unrecognized content type: {$type}");
        }
        break;

      case 'author':
        $this->author = [ ];
        $this->amParsingAuthor = true;
        break;

      case 'link':
        $this->handleLink($attributes);
        break;

      case 'category':
        if ( ! array_key_exists('tags', $this->attrs)) {
          $this->attrs['tags'] = [ ];
        }
        $term = $attributes['term'];
        if (array_key_exists('scheme', $attributes)) {
          $term = $attributes['scheme'] . ',' . $term;
        }
        $this->attrs['tags'][] = $term;
        break;

      case 'source':
        $this->source = [ ];
        $this->amParsingSource = true;
        break;
    }
  }

  public function handleElementEnd(string $name)
  {
    $name = substr($name, 28); // 28 == strlen(XMLNS_ATOM . "\t")

    if ($this->amParsingAuthor) {
      switch ($name) {
        case 'name':
          $this->author['name'] = $this->flushChardata();
          break;

        case 'uri':
          $this->author['url'] = $this->flushChardata();
          break;

        case 'email':
          if ( ! array_key_exists('url', $this->author)) {
            $this->author['url'] = 'mailto:' . $this->flushChardata();
          }
          break;

        case 'author':
          $this->amParsingAuthor = false;
          if ($this->amParsingSource) {
            $this->source['author'] = $this->author;
            if ( ! array_key_exists('author', $this->attrs)) {
              $this->attrs['author'] = $this->author;
            }
          } else {
            $this->attrs['author'] = $this->author;
          }
          break;
      }
      return;
    }

    if ($this->amParsingSource) {
      switch ($name) {
        case 'title':
          $this->source['title'] = $this->flushChardata();
          break;

        case 'subtitle':
          $this->source['description'] = $this->flushChardata();
          break;

        case 'source':
          $this->amParsingSource = false;
          $this->attrs['_fp_item_source'] = $this->source;
          break;
      }
      return;
    }

    switch ($name) {
      case 'id':
        $this->attrs['id'] = $this->flushChardata();
        break;

      case 'title':
        $title = $this->flushChardata();
        if (trim($title) !== '') {
          $this->attrs['title'] = $title;
        }
        break;

      case 'summary':
        // TODO: if HTML, strip tags?
        $this->attrs['summary'] = $this->flushChardata();
        break;

      case 'content':
        if ($this->currentContentType === 'html') {
          $this->attrs['content_html'] = $this->flushChardata();
        } else if ($this->currentContentType === 'text') {
          $this->attrs['content_text'] = $this->flushChardata();
        } else {
          // @codeCoverageIgnoreStart
          throw new \RuntimeException("Logic error: " .
            "unknown internal content type {$this->currentContentType}");
          // @codeCoverageIgnoreEnd
        }
        $this->currentContentType = null;
        break;

      case 'updated':
        $this->attrs['date_modified'] = $this->flushChardata();
        break;

      case 'published':
        $this->attrs['date_published'] = $this->flushChardata();
        break;

      case 'entry':
        throw new HandlerFinished($this->attrs);
    }
  }

  public function handlePreviousHandlerResult(
    HandlerInterface $previousHandler,
    $result
  ) {
    if ($previousHandler instanceof XhtmlBufferSubhandler) {
      $this->chardata = $result;
    }
  }
}
