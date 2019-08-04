<?php declare(strict_types=1);

namespace PN\FeedParse\Tests;

use PN\FeedParse\{Parser, XmlError};
use PHPUnit\Framework\TestCase;

/**
 * @covers PN\FeedParse\Parser
 * @covers PN\FeedParse\XmlError
 * @covers PN\FeedParse\Handler\Control\DelegationRequest
 * @covers PN\FeedParse\Handler\Control\HandlerFinished
 * @uses PN\FeedParse\HandlerDispatcher
 * @uses PN\FeedParse\Handler\AtomHandler
 * @uses PN\FeedParse\Handler\AtomHandler\ItemSubhandler
 * @uses PN\FeedParse\Handler\AtomHandler\XhtmlBufferSubhandler
 * @uses PN\FeedParse\Handler\RssHandler
 * @uses PN\FeedParse\Handler\RssHandler\ItemSubhandler
 */
class ParserTest extends TestCase
{
  public function feedDataProvider()
  {
    return array_map(function($xmlName) {
      $xml = file_get_contents($xmlName);
      $json = file_get_contents($xmlName . '.json');
      $json = json_decode($json, true);
      if ($json === null) {
        throw new \RuntimeException("JSON parsing of {$xmlName}.json failed: " .
          json_last_error_msg());
      }
      return [$xmlName, $xml, $json];
    }, glob(__DIR__ . '/samples/*.xml'));
  }

  /** @dataProvider feedDataProvider */
  public function testFeedParsing(string $_name, string $xml, array $json)
  {
    $p = new Parser();
    $p->process($xml);
    self::assertEquals($json, $p->getResult());
  }

  public function testXmlFailureThrown()
  {
    $p = new Parser();
    $this->expectException(XmlError::class);
    $p->process('not really xml');
    $p->getResult();
  }
}
