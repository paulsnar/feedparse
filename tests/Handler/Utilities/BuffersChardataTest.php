<?php declare(strict_types=1);

namespace PN\FeedParse\Tests\Handler\Utilities;

use PN\FeedParse\Handler\Utilities\BuffersChardata;
use PHPUnit\Framework\TestCase;

/**
 * @covers PN\FeedParse\Handler\Utilities\BuffersChardata
 */
class BuffersChardataTest extends TestCase
{
  public function testFlushesChardata()
  {
    $a = new class {
      use BuffersChardata;
      public function get() {
        return $this->flushChardata();
      }
    };
    self::assertNull($a->get());
    $a->handleChardata('abc');
    self::assertEquals('abc', $a->get());
    self::assertNull($a->get());
  }

  public function testConcatenatesChunks()
  {
    $a = new class {
      use BuffersChardata;
      public function get(){
        return $this->flushChardata();
      }
    };

    $a->handleChardata('abc');
    $a->handleChardata('def');
    self::assertEquals('abcdef', $a->get());
  }
}
