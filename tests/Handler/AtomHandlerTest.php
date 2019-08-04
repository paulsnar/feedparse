<?php declare(strict_types=1);

namespace PN\FeedParse\Tests\Handler;

use PN\FeedParse\Handler\AtomHandler;
use PN\FeedParse\HandlerDispatcher;
use PHPUnit\Framework\TestCase;

const XMLNS_ATOM = 'http://www.w3.org/2005/Atom';
function atom_el(string $name) {
  if (strpos($name, "\t") === false) {
    return XMLNS_ATOM . "\t" . $name;
  }
  return $name;
}

/**
 * @covers PN\FeedParse\Handler\AtomHandler
 * @covers PN\FeedParse\Handler\AtomHandler\ItemSubhandler
 * @uses PN\FeedParse\HandlerDispatcher
 * @uses PN\FeedParse\Handler\AtomHandler\XhtmlBufferSubhandler
 * @uses PN\FeedParse\Handler\Control\DelegationRequest
 * @uses PN\FeedParse\Handler\Control\HandlerFinished
 * @uses PN\FeedParse\Handler\Utilities\BuffersChardata
 */
class AtomHandlerTest extends TestCase
{
  protected static function feedEvents($handler, $events)
  {
    foreach ($events as $event) {
      $name = array_shift($event);
      $method = 'handle' . $name;
      $handler->{$method}(null, ...$event);
    }
  }

  protected static function generateElement(
    string $name,
    array $attributes,
    array $children = [ ]
  ) {
    yield ['ElementStart', atom_el($name), $attributes];
    foreach ($children as $child) {
      if (is_string($child)) {
        yield ['Chardata', $child];
      } else if ($child !== null) {
        yield from $child;
      }
    }
    yield ['ElementEnd', atom_el($name)];
  }

  protected static function generateFeedContent(array $attributes)
  {
    $body = [];
    foreach ($attributes as $name => $value) {
      if ($name === 'links') {
        foreach ($value as $rel => $href) {
          $body[] = static::generateElement('link',
            ['rel' => $rel, 'href' => $href]);
        }
      } else if ($name === 'author') {
        $author = [ ];
        foreach ($value as $name => $content) {
          $author[] = static::generateElement($name, [], [$content]);
        }
        $body[] = static::generateElement('author', [], $author);
      } else if ($name === 'entries') {
        continue;
      } else {
        $body[] = static::generateElement($name, [], [$value]);
      }
    }
    if (array_key_exists('entries', $attributes)) {
      foreach ($attributes['entries'] as $entry) {
        $body[] = static::generateEntry($entry);
      }
    }
    return $body;
  }

  protected function generateFeed(array $attributes)
  {
    return static::generateElement('feed', ['xmlns' => XMLNS_ATOM],
      static::generateFeedContent($attributes));
  }

  protected static function generateEntry(array $entry)
  {
    $body = [];
    foreach ($entry as $name => $content) {
      if ($name === 'author') {
        $author = [];
        foreach ($content as $name2 => $value) {
          $author[] = static::generateElement($name2, [], [$value]);
        }
        $body[] = static::generateElement('author', [], $author);
      } else if ($name === 'source') {
        $body[] = static::generateElement('source', [],
          static::generateFeedContent($content));
      } else if ($name === 'links') {
        foreach ($content as $rel => $href) {
          if ($rel === 'enclosure') {
            $body[] = static::generateElement('link',
              ['rel' => $rel] + $href);
          } else {
            $body[] = static::generateElement('link',
              ['rel' => $rel, 'href' => $href]);
          }
        }
      } else if ($name === 'categories') {
        foreach ($content as $category) {
          $body[] = static::generateElement('category',
            ['term' => $category]);
        }
      } else if ($name === 'content' || $name === 'content.type') {
        continue;
      } else {
        $body[] = static::generateElement($name, [], [$content]);
      }
    }

    if (array_key_exists('content', $entry)) {
      $attrs = [ ];
      if (array_key_exists('content.type', $entry)) {
        $attrs['type'] = $entry['content.type'];
      }
      $body[] = static::generateElement('content', $attrs, [$entry['content']]);
    }

    yield from static::generateElement('entry', [], $body);
  }

  protected static function assertFeedEqualsJson($events, array $json)
  {
    $hd = new HandlerDispatcher();
    $hd->pushHandler(new AtomHandler());
    static::feedEvents($hd, $events);
    static::assertEquals($json, $hd->getResult());
  }

  public function testCanHandleStart()
  {
    self::assertTrue(AtomHandler::canHandleStart(
      AtomHandler::XMLNS_ATOM . "\tfeed",
      ['xmlns' => AtomHandler::XMLNS_ATOM]));
    self::assertFalse(AtomHandler::canHandleStart('rss', ['version' => '2.0']));
  }

  public function testFeedwideLinkMapping()
  {
    $feed = static::generateFeed([
      'links' => [
        'alternate' => 'https://example.com',
        'self' => 'https://example.com/feed.xml',
        'hub' => 'https://websub.example.com/subscribe',
      ],
      'entries' => [ ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'home_page_url' => 'https://example.com',
      'feed_url' => 'https://example.com/feed.xml',
      'hubs' => [
        [ 'type' => 'WebSub', 'url' => 'https://websub.example.com/subscribe' ],
      ],
      'items' => [ ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testFeedAttributeMapping()
  {
    $feed = static::generateFeed([
      'title' => 'Sample Feed',
      'subtitle' => 'whatever',
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'title' => 'Sample Feed',
      'description' => 'whatever',
      'items' => [ ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testFeedwideAuthorMapping()
  {
    $feed = static::generateFeed([
      'author' => [
        'name' => 'John Appleseed',
        'uri' => 'https://example.com',
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'author' => [
        'name' => 'John Appleseed',
        'url' => 'https://example.com',
      ],
      'items' => [ ],
    ];

    self::assertFeedEqualsJson($feed, $json);

    // Ensure that email does not override explicitly specified URL.
    $feed = static::generateFeed([
      'author' => [
        'name' => 'John Appleseed',
        'email' => 'john@example.com',
        'uri' => 'https://example.com',
      ],
    ]);

    self::assertFeedEqualsJson($feed, $json);

    // But also ensure that if no URL is present, e-mail is set as one.
    $feed = static::generateFeed([
      'author' => [
        'name' => 'John Appleseed',
        'email' => 'john@example.com',
      ],
    ]);

    $json['author']['url'] = 'mailto:john@example.com';

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testBasicItemPropertyMapping()
  {
    $feed = static::generateFeed([
      'entries' => [
        [
          'id' => 'uri:example:post:1',
          'title' => 'Sample post',
          'links' => ['alternate' => 'https://example.com/posts/1'],
          'published' => '2019-08-01T12:00:00Z',
          'updated' => '2019-08-01T12:30:00Z',
          'content' => 'Hello, world!',
          'summary' => 'In which I greet the world in plaintext.',
        ],
        [
          'id' => 'uri:example:post:0',
          'title' => 'Sample zeroth post',
          'links' => ['alternate' => 'https://example.com/posts/0'],
          'published' => '2019-08-01T09:00:00Z',
          'updated' => '2019-08-01T10:30:00Z',
          'content' => '<p>Hello, world!</p>',
          'content.type' => 'html',
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [
          'id' => 'uri:example:post:1',
          'title' => 'Sample post',
          'url' => 'https://example.com/posts/1',
          'date_published' => '2019-08-01T12:00:00Z',
          'date_modified' => '2019-08-01T12:30:00Z',
          'content_text' => 'Hello, world!',
          'summary' => 'In which I greet the world in plaintext.',
        ],
        [
          'id' => 'uri:example:post:0',
          'title' => 'Sample zeroth post',
          'url' => 'https://example.com/posts/0',
          'date_published' => '2019-08-01T09:00:00Z',
          'date_modified' => '2019-08-01T10:30:00Z',
          'content_html' => '<p>Hello, world!</p>',
        ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testItemLinkMapping()
  {
    $feed = static::generateFeed([
      'entries' => [
        [
          'links' => [
            'alternate' => 'https://example.com/alternate',
            'related' => 'https://example.com/related',
          ],
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [
          'url' => 'https://example.com/alternate',
          'external_url' => 'https://example.com/related',
        ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testEnclosureMapping()
  {
    $genEntry = function (
      string $href,
      ?string $type = null,
      ?int $length = null
    ) {
      $attrs = ['href' => $href];
      if ($type !== null) {
        $attrs['type'] = $type;
      }
      if ($length !== null) {
        $attrs['length'] = (string) $length;
      }
      return [
        'links' => ['enclosure' => $attrs],
      ];
    };

    $feed = static::generateFeed([
      'entries' => [
        $genEntry('https://example.com/media.mp3'),
        $genEntry('https://example.com/media.mp3', 'audio/mpeg'),
        $genEntry('https://example.com/media.mp3', null, 12345678),
        $genEntry('https://example.com/media.mp3', 'audio/mpeg', 12345678),
      ],
    ]);

    $genItem = function (
      string $url,
      ?string $mimeType = null,
      ?int $size = null
    ) {
      $attrs = ['url' => $url];
      if ($mimeType !== null) {
        $attrs['mime_type'] = $mimeType;
      }
      if ($size !== null) {
        $attrs['size_in_bytes'] = $size;
      }
      return ['attachments' => [$attrs]];
    };

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        $genItem('https://example.com/media.mp3'),
        $genItem('https://example.com/media.mp3', 'audio/mpeg'),
        $genItem('https://example.com/media.mp3', null, 12345678),
        $genItem('https://example.com/media.mp3', 'audio/mpeg', 12345678),
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testSourcePreservation()
  {
    $feed = static::generateFeed([
      'links' => [
        'alternate' => 'https://aggregator.online',
        'self' => 'https://aggregator.online/feed.xml',
      ],
      'entries' => [
        [
          'content' => 'Blah',
          'links' => [
            'alternate' => 'https://source.example.com/blah',
          ],
          'source' => [
            'title' => 'Source',
            'subtitle' => 'Subtitle',
            'links' => [
              'alternate' => 'https://source.example.com',
              'self' => 'https://source.example.com/feed.xml',
            ],
          ],
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'home_page_url' => 'https://aggregator.online',
      'feed_url' => 'https://aggregator.online/feed.xml',
      'items' => [
        [
          'content_text' => 'Blah',
          'url' => 'https://source.example.com/blah',
          '_fp_item_source' => [
            'title' => 'Source',
            'description' => 'Subtitle',
            'home_page_url' => 'https://source.example.com',
            'feed_url' => 'https://source.example.com/feed.xml',
          ],
        ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testThrowsOnUnknownItemContentType()
  {
    $feed = static::generateFeed([
      'entries' => [
        [
          'content' => 'Blah',
          'content.type' => 'application/octet-stream',
        ],
      ],
    ]);

    $hd = new HandlerDispatcher();
    $hd->pushHandler(new AtomHandler());
    $this->expectException(\RuntimeException::class);
    static::feedEvents($hd, $feed);
  }

  public function testCategoryParsing()
  {
    $feed = static::generateElement('feed', ['xmlns' => XMLNS_ATOM], [
      static::generateElement('entry', [], [
        static::generateElement('category', ['term' => 'cat1']),
        static::generateElement('category', ['term' => 'cat2',
          'scheme' => 'https']),
      ]),
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [
          'tags' => ['cat1', 'https,cat2'],
        ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testContentTypeDependentParsing()
  {
    $feed = static::generateFeed([
      'entries' => [
        [ 'content' => 'Content',
          'content.type' => 'text' ],
        [ 'content' => 'Content',
          'content.type' => 'text/plain' ],
        [ 'content' => 'HTML',
          'content.type' => 'html' ],
        [ 'content' => 'HTML',
          'content.type' => 'text/html' ],
        [ 'content' => static::generateElement(
            "http://www.w3.org/1999/xhtml\tdiv",
            [ 'xmlns' => 'http://www.w3.org/1999/xhtml' ],
            [ 'XHTML' ]),
          'content.type' => 'xhtml' ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [ 'content_text' => 'Content' ],
        [ 'content_text' => 'Content' ],
        [ 'content_html' => 'HTML' ],
        [ 'content_html' => 'HTML' ],
        [ 'content_html' => '<div>XHTML</div>' ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  /** @depends testSourcePreservation */
  public function testAuthorshipPreservation()
  {
    $feed = static::generateFeed([
      'author' => ['name' => 'Feedwide Author'],
      'entries' => [
        [ 'author' => ['name' => 'Item Author'] ],
        [ 'author' => ['name' => 'Have Email',
            'email' => 'me@example.com'] ],
        [ 'author' => ['name' => 'Have URL',
            'uri' => 'https://example.com'] ],
        [ 'author' => ['name' => 'Have Both',
            'email' => 'nobody@localhost',
            'uri' => 'https://root.dev'] ],
        [
          'source' => [
            'author' => ['name' => 'Source Author'],
          ],
        ],
        [
          'author' => ['name' => 'Conflict Author'],
          'source' => [
            'author' => ['name' => 'Source Author'],
          ],
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'author' => ['name' => 'Feedwide Author'],
      'items' => [
        [ 'author' => ['name' => 'Item Author'] ],
        [ 'author' => ['name' => 'Have Email',
            'url' => 'mailto:me@example.com'] ],
        [ 'author' => ['name' => 'Have URL',
            'url' => 'https://example.com'] ],
        [ 'author' => ['name' => 'Have Both',
            'url' => 'https://root.dev'] ],
        [ 'author' => ['name' => 'Source Author'],
          '_fp_item_source' => [
            'author' => ['name' => 'Source Author'],
          ] ],
        [ 'author' => ['name' => 'Conflict Author'],
          '_fp_item_source' => [
            'author' => ['name' => 'Source Author'] ] ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }
}
