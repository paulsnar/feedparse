<?php declare(strict_types=1);

namespace PN\FeedParse\Tests\Handler;

use PN\FeedParse\HandlerDispatcher;
use PN\FeedParse\Handler\{AtomHandler, RssHandler};
use PN\FeedParse\Handler\Control\HandlerFinished;
use PHPUnit\Framework\TestCase;

/**
 * @covers PN\FeedParse\Handler\RssHandler
 * @covers PN\FeedParse\Handler\RssHandler\ItemSubhandler
 * @uses PN\FeedParse\HandlerDispatcher
 * @uses PN\FeedParse\Handler\Control\DelegationRequest
 * @uses PN\FeedParse\Handler\Control\HandlerFinished
 * @uses PN\FeedParse\Handler\Utilities\BuffersChardata
 */
class RssHandlerTest extends TestCase
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
    yield ['ElementStart', $name, $attributes];
    foreach ($children as $child) {
      if (is_string($child)) {
        yield ['Chardata', $child];
      } else if ($child !== null) {
        yield from $child;
      }
    }
    yield ['ElementEnd', $name];
  }

  protected static function generateFeed(array $attributes)
  {
    $body = [ ];
    foreach ($attributes as $name => $value) {
      if ($name === 'atom-links') {
        foreach ($value as $rel => $href) {
          $body[] = static::generateElement(AtomHandler::XMLNS_ATOM . "\tlink",
            ['rel' => $rel, 'href' => $href]);
        }
      } else if ($name === 'cloud') {
        $body[] = static::generateElement('cloud', $value);
      } else if ($name === 'dc:creator') {
        $body[] = static::generateElement(
          "http://purl.org/dc/elements/1.1/\tcreator",
          ['xmlns' => 'http://purl.org/dc/elements/1.1/'],
          [$value]);
      } else if ($name === 'items') {
        continue;
      } else {
        $body[] = static::generateElement($name, [], [$value]);
      }
    }
    foreach ($attributes['items'] as $item) {
      $body[] = static::generateItem($item);
    }
    return static::generateElement('rss', ['version' => '2.0'], [
      static::generateElement('channel', [], $body),
    ]);
  }

  protected static function generateItem(array $attributes)
  {
    $body = [ ];
    foreach ($attributes as $name => $value) {
      if ($name === 'atom-links') {
        foreach ($value as $rel => $href) {
          $body[] = static::generateElement(AtomHandler::XMLNS_ATOM . "\tlink",
            ['rel' => $rel, 'href' => $href]);
        }
      } else if ($name === 'guid') {
        $attrs = [ ];
        if (array_key_exists('guid.isPermaLink', $attributes)) {
          $attrs['isPermaLink'] = $attributes['guid.isPermaLink'];
        }
        $body[] = static::generateElement('guid', $attrs, [$value]);
      } else if ($name === 'source') {
        $url = $attributes['source.url'];
        $body[] = static::generateElement('source',
          ['url' => $url], [$value]);
      } else if ($name === 'categories') {
        foreach ($value as $category) {
          if (is_array($category)) {
            [$domain, $category] = $category;
            $body[] = static::generateElement('category',
              ['domain' => $domain], [$category]);
          } else {
            $body[] = static::generateElement('category', [], [$category]);
          }
        }
      } else if ($name === 'enclosure') {
        $body[] = static::generateElement('enclosure', $value);
      } else if ($name === 'content:encoded') {
        $body[] = static::generateElement(
          "http://purl.org/rss/1.0/modules/content/\tencoded",
          ['xmlns' => 'http://purl.org/rss/1.0/modules/content/'],
          [$value]);
      } else if ($name === 'dc:creator') {
        $body[] = static::generateElement(
          "http://purl.org/dc/elements/1.1/\tcreator",
          ['xmlns' => 'http://purl.org/dc/elements/1.1/'],
          [$value]);
      } else if ($name === 'source.url' || $name === 'guid.isPermaLink') {
        continue;
      } else {
        $body[] = static::generateElement($name, [], [$value]);
      }
    }
    return static::generateElement('item', [], $body);
  }

  protected static function assertFeedEqualsJson($events, array $json)
  {
    $hd = new HandlerDispatcher();
    $hd->pushHandler(new RssHandler());
    static::feedEvents($hd, $events);
    self::assertEquals($json, $hd->getResult());
  }


  public function testCanHandleStart()
  {
    self::assertTrue(RssHandler::canHandleStart('rss', ['version' => '2.0']));
    self::assertFalse(RssHandler::canHandleStart('rss',
      ['version' => '0.91']));
    self::assertFalse(RssHandler::canHandleStart(
      AtomHandler::XMLNS_ATOM . "\tfeed",
      ['xmlns' => AtomHandler::XMLNS_ATOM]));
  }

  public function testRssCloudHubPropagation()
  {
    $feed = static::generateFeed([
      'cloud' => [
        'domain' => 'cloud.example.com',
        'port' => '80',
        'path' => '/rpc',
        'registerProcedure' => 'rssCloud.register',
        'protocol' => 'xml-rpc',
      ],
      'items' => [ ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'hubs' => [
        [ 'type' => 'rssCloud',
          'url' => 'http://cloud.example.com:80/rpc',
          '_rsscloud' => [ 'protocol' => 'xml-rpc',
            'procedure' => 'rssCloud.register' ] ],
      ],
      'items' => [ ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testBasicFeedwideAttributeMapping()
  {
    $feed = static::generateFeed([
      'title' => 'Liftoff News',
      'link' => 'http://liftoff.msfc.nasa.gov/',
      'description' => 'Liftoff to Space Exploration.',
      'items' => [ ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'title' => 'Liftoff News',
      'description' => 'Liftoff to Space Exploration.',
      'home_page_url' => 'http://liftoff.msfc.nasa.gov/',
      'items' => [ ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  /**
   * Since RSS doesn't specify what <description> contains, we have to make an
   * educated guess -- if it has stuff that looks like HTML (<...> tags or
   * &...; escapes), it's treated as HTML; otherwise it's assumed to be plain
   * text.
   */
  public function testHtmlRecognitionInDescription()
  {
    $feed = static::generateFeed([
      'items' => [
        [ 'description' => 'This is plain text.' ],
        [ 'description' => 'This is an ampersand: &, but this is not HTML.' ],
        [ 'description' => '<b>This</b> is HTML.' ],
        [ 'description' => 'This&hellip; is HTML.' ],
        [ 'description' => '1 < 2, 3 < 4.' ], // in homage to Mark Pilgrim
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [ 'content_text' => 'This is plain text.' ],
        [ 'content_text' => 'This is an ampersand: &, but this is not HTML.' ],
        [ 'content_html' => '<b>This</b> is HTML.' ],
        [ 'content_html' => 'This&hellip; is HTML.' ],
        [ 'content_text' => '1 < 2, 3 < 4.' ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  /**
   * Basically, the URL should come from an Atom link; if that's not present, a
   * <link> element; if that's not present, the <guid> if isPermaLink is true
   * or absent.
   */
  public function testAtomLinkAndGuidConflict()
  {
    $feed = static::generateFeed([
      'link' => 'http://localhost/link',
      'atom-links' => [
        'self' => 'http://localhost/atom/self',
        'alternate' => 'http://localhost/atom/alternate',
      ],
      'items' => [
        [
          'guid' => 'http://localhost/0/guid', // isPermaLink not present
          'link' => 'http://localhost/0/link',
        ],
        [
          'guid' => 'http://localhost/1/guid',
          'guid.isPermaLink' => 'true',
          'link' => 'http://localhost/1/link',
        ],
        [
          'guid' => 'http://localhost/2/guid',
          'atom-links' => [
            'alternate' => 'http://localhost/2/atom/alternate',
            'related' => 'http://localhost/2/atom/related',
          ],
        ],
        [
          'guid' => 'http://localhost/3/guid',
          'atom-links' => [
            'related' => 'http://localhost/3/atom/related',
          ],
        ],
        [
          'guid' => 'http://localhost/4/guid',
          'link' => 'http://localhost/4/link',
          'atom-links' => [
            'alternate' => 'http://localhost/4/atom/alternate',
          ],
        ],
        [
          'link' => 'http://localhost/5/link',
          'atom-links' => [
            'related' => 'http://localhost/5/atom/related',
          ],
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'feed_url' => 'http://localhost/atom/self',
      'home_page_url' => 'http://localhost/atom/alternate',
      'items' => [
        [ 'id' => 'http://localhost/0/guid',
          'url' => 'http://localhost/0/link' ],
        [ 'id' => 'http://localhost/1/guid',
          'url' => 'http://localhost/1/link' ],
        [ 'id' => 'http://localhost/2/guid',
          'url' => 'http://localhost/2/atom/alternate',
          'external_url' => 'http://localhost/2/atom/related' ],
        [ 'id' => 'http://localhost/3/guid',
          'url' => 'http://localhost/3/guid',
          'external_url' => 'http://localhost/3/atom/related' ],
        [ 'id' => 'http://localhost/4/guid',
          'url' => 'http://localhost/4/atom/alternate' ],
        [ 'url' => 'http://localhost/5/link',
          'external_url' => 'http://localhost/5/atom/related' ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testSourcePreservation()
  {
    $feed = static::generateFeed([
      'items' => [
        [
          'source' => 'Example Feed',
          'source.url' => 'https://example.com/feed.xml',
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [
          '_fp_item_source' => [
            'title' => 'Example Feed',
            'feed_url' => 'https://example.com/feed.xml',
          ],
        ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testCategoryConversion()
  {
    $feed = static::generateFeed([
      'items' => [
        [
          'categories' => [
            'cat1',
            ['https://example.com', 'cat2'],
            'cat3/cat4',
            ['https://example.com', 'cat5/cat6'],
          ],
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [
          'tags' => ['cat1', 'https://example.com/cat2', 'cat3', 'cat4',
            'https://example.com/cat5', 'https://example.com/cat6'],
        ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  /**
   * Some feeds use a mix of <description> and <content:encoded> for
   * representing item summaries and/or content; this tests that the heuristic
   * used for mapping that onto JSON Feed is sane.
   */
  public function testUsesContentEncodedIfPresent()
  {
    $feed = static::generateFeed([
      'items' => [
        /* Default style: content:encoded is absent, description is content */
        [ 'description' => '<p>Content.</p>' ],

        /* Podcast (relay.fm) style: description is summary, content:encoded
         * is content (ordered both ways) */
        [ 'description' => 'Summary.', 'content:encoded' => '<p>Content.</p>' ],
        [ 'content:encoded' => '<p>Content.</p>', 'description' => 'Summary.' ],

        /* Squarespace 6 style: description is content, content:encoded is
         * present, but empty */
        [ 'description' => '<p>Content.</p>', 'content:encoded' => '' ],

        /* Currently unseen, but legal: description is absent, content:encoded
         * is content */
        [ 'content:encoded' => '<p>Content.</p>' ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [ 'content_html' => '<p>Content.</p>' ],
        [ 'content_html' => '<p>Content.</p>',
          'summary' => 'Summary.' ],
        [ 'content_html' => '<p>Content.</p>',
          'summary' => 'Summary.' ],
        [ 'content_html' => '<p>Content.</p>' ],
        [ 'content_html' => '<p>Content.</p>' ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testReparsesPubdate()
  {
    $feed = static::generateFeed([
      'items' => [
        [ 'pubDate' => 'Mon, 1 Jul 2019 12:00:00 GMT' ],
        [ 'pubDate' => 'Mon, 1 Jul 2019 09:00:00 -03:00' ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [ 'date_published' => '2019-07-01T12:00:00Z' ],
        [ 'date_published' => '2019-07-01T12:00:00Z' ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  public function testRejectsBadPubdateFormat()
  {
    $feed = static::generateFeed([
      'items' => [
        [ 'pubDate' => 'some nonsense' ],
      ],
    ]);

    self::expectException(\RuntimeException::class);
    $hd = new HandlerDispatcher();
    $hd->pushHandler(new RssHandler());
    self::feedEvents($hd, $feed);
  }

  public function testMapsEnclosureToAttachment()
  {
    $feed = static::generateFeed([
      'items' => [
        [
          'enclosure' => [
            'url' => 'https://example.com/podcast.mp3',
            'length' => '12345678',
            'type' => 'audio/mpeg',
          ],
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'items' => [
        [
          'attachments' => [
            [
              'url' => 'https://example.com/podcast.mp3',
              'mime_type' => 'audio/mpeg',
              'size_in_bytes' => 12345678
            ],
          ],
        ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }

  /**
   * RSS has an ill-defined and not really useful <author> element, so if we
   * find a <dc:creator> one instead, we set JF item's author to that. This is
   * used by, e.g., Squarespace.
   */
  public function testMapsDcCreatorToAuthor()
  {
    $feed = static::generateFeed([
      'dc:creator' => 'Feedwide Author',
      'items' => [
        [ 'title' => 'Item' ],
        [
          'title' => 'Authored Item',
          'dc:creator' => 'Item Author',
        ],
      ],
    ]);

    $json = [
      'version' => 'https://jsonfeed.org/version/1',
      'author' => [ 'name' => 'Feedwide Author' ],
      'items' => [
        [ 'title' => 'Item' ],
        [ 'title' => 'Authored Item',
          'author' => [ 'name' => 'Item Author' ] ],
      ],
    ];

    self::assertFeedEqualsJson($feed, $json);
  }
}
