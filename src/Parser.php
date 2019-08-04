<?php declare(strict_types=1);

namespace PN\FeedParse;

class Parser
{
  public const JSONFEED_1 = 'https://jsonfeed.org/version/1';

  protected $p, $dispatcher;

  /** @var Feed */
  protected $feed;

  public function __construct()
  {
    $this->dispatcher = new HandlerDispatcher();

    $p = $this->p = xml_parser_create_ns('UTF-8', "\t");
    xml_parser_set_option($p, \XML_OPTION_CASE_FOLDING, 0);

    xml_set_element_handler($this->p,
      [$this->dispatcher, 'handleElementStart'],
      [$this->dispatcher, 'handleElementEnd']);
    xml_set_character_data_handler($this->p,
      [$this->dispatcher, 'handleChardata']);
  }

  public function __destruct()
  {
    xml_parser_free($this->p);
  }

  public function process($chunk, $final = false)
  {
    $ok = xml_parse($this->p, $chunk, $final);
    if ( ! $ok) {
      throw new XMLError($this->p);
    }
  }

  public function getResult(): array
  {
    $this->process('', true);
    return $this->dispatcher->getResult();
  }
}
