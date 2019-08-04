<?php declare(strict_types=1);

namespace PN\FeedParse\Tests\Handler;

use PN\FeedParse\Handler\AtomHandler\XhtmlBufferSubhandler;
use PN\FeedParse\Handler\Control\HandlerFinished;
use PHPUnit\Framework\TestCase;

/**
 * @covers PN\FeedParse\Handler\AtomHandler\XhtmlBufferSubhandler
 * @uses PN\FeedParse\Handler\Control\HandlerFinished
 */
class XhtmlBufferSubhandlerTest extends TestCase
{
  protected static function feedEvents($handler, $events)
  {
    foreach ($events as $event) {
      $type = array_shift($event);
      $method = 'handle' . $type;
      $handler->{$method}(...$event);
    }
  }

  protected function generateElement(
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

  protected function assertBufferingResult($tree, string $data)
  {
    $buf = new XhtmlBufferSubhandler();
    try {
      static::feedEvents($buf, $tree);
    } catch (HandlerFinished $fin) {
      self::assertEquals($data, $fin->result);
    }
  }

  public function testBuffering()
  {
    $tree = static::generateElement(
      "http://www.w3.org/1999/xhtml\tdiv",
      ['xmlns' => 'http://www.w3.org/1999/xhtml'], [
        static::generateElement('h1', [], ['Hello, world!']),
        'This is an XHTML sample.',
        static::generateElement('span', ['class' => 'bold'],
          ['It Works!']),
      ]);

    $data = '<div><h1>Hello, world!</h1>This is an XHTML sample.' .
      '<span class="bold">It Works!</span></div>';

    $this->assertBufferingResult($tree, $data);
  }

  public function testSelfClosingElements()
  {
    $tree = static::generateElement('div', [], [
      static::generateElement('br', []),
      static::generateElement('hr', ['class' => 'rule']),
      static::generateElement('img', []),
      static::generateElement('link', ['rel' => 'stylesheet',
        'href' => 'http"quarters&more']),
      static::generateElement('meta', ['name' => 'viewport',
        'content' => 'abc<>def']),
      static::generateElement('span', []),
    ]);

    $data = '<div><br><hr class="rule"><img>' .
      '<link rel="stylesheet" href="http&quot;quarters&amp;more">' .
      '<meta name="viewport" content="abc&lt;&gt;def">' .
      '<span></span></div>';

    $this->assertBufferingResult($tree, $data);
  }
}
