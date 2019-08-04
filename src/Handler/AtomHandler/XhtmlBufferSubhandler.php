<?php declare(strict_types=1);

namespace PN\FeedParse\Handler\AtomHandler;

use PN\FeedParse\Handler\HandlerInterface;
use PN\FeedParse\Handler\Control\HandlerFinished;
use PN\FeedParse\Handler\Utilities\BuffersChardata;

// This class transforms XHTML to HTML (oh my, the obscenity!) in a sloppy
// but probably acceptable way.
// Don't use this in production, for I don't and I don't have the resources
// to test this properly.
class XhtmlBufferSubhandler implements HandlerInterface
{
  use BuffersChardata;

  protected $output = '', $nestingLevel = 0;

  protected const SELF_CLOSING_ELEMENTS = [
    'area' => true,
    'base' => true,
    'br' => true,
    'col' => true,
    'embed' => true,
    'hr' => true,
    'img' => true,
    'input' => true,
    'link' => true,
    'meta' => true,
    'param' => true,
    'source' => true,
    'track' => true,
    'wbr' => true,
  ];

  protected function unnamespaceName(string $name): string
  {
    $nssepPosition = strpos($name, "\t");
    if ($nssepPosition !== false) {
      $name = substr($name, $nssepPosition + 1);
    }
    return $name;
  }

  protected function escapeAttributeValue(string $value): string
  {
    return str_replace(
      ['&', '<', '>', '"'],
      ['&amp;', '&lt;', '&gt;', '&quot;'],
      $value);
  }

  protected function serializeAttributes(array $attributes): ?string
  {
    if (array_key_exists('xmlns', $attributes)) {
      unset($attributes['xmlns']);
    }

    if (count($attributes) === 0) {
      return null;
    }
    $pairs = [ '' ];
    foreach ($attributes as $name => $value) {
      $name = $this->unnamespaceName($name);
      $value = $this->escapeAttributeValue($value);
      $pairs[] = "{$name}=\"{$value}\"";
    }
    return implode(' ', $pairs);
  }

  public function handleElementStart(string $name, array $attributes)
  {
    $chardata = $this->flushChardata();
    if ($chardata !== null && $chardata !== '') {
      $this->output .= $this->escapeChardata($chardata);
    }

    $name = $this->unnamespaceName($name);
    $attrs = $this->serializeAttributes($attributes);
    $this->output .= "<{$name}";
    if ($attrs !== null) {
      $this->output .= $attrs;
    }
    $this->output .= ">";

    $this->nestingLevel += 1;
  }

  protected function escapeChardata(string $chardata): string
  {
    return str_replace(
      ['&', '<', '>'],
      ['&amp;', '&lt;', '&gt;'],
      $chardata);
  }

  public function handleElementEnd(string $name)
  {
    $name = $this->unnamespaceName($name);
    if (static::SELF_CLOSING_ELEMENTS[$name] ?? false) {
      // don't append closing part
    } else {
      $body = $this->flushChardata();
      if ($body !== null) {
        $body = $this->escapeChardata($body);
        $this->output .= $body;
      }
      $this->output .= "</{$name}>";
    }

    $this->nestingLevel -= 1;
    if ($this->nestingLevel === 0) {
      throw new HandlerFinished($this->output);
    }
  }
}
