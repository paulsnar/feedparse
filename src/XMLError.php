<?php declare(strict_types=1);

namespace PN\FeedParse;

class XMLError extends \RuntimeException
{
  public function __construct($parser)
  {
    $code = xml_get_error_code($parser);
    $err = xml_error_string($code);
    $line = xml_get_current_line_number($parser);
    $col = xml_get_current_column_number($parser);
    $byte = xml_get_current_byte_index($parser);

    parent::__construct(
      sprintf('%s (at %d:%d, byte %d)',
      $err, $line, $col, $byte), $code);
  }
}
