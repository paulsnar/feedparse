# PN\FeedParse

This package provides a strict online feed parser. It accepts
[Atom][atom-rfc4287] and [RSS][rss-2.0-spec] feeds (currently only Atom 1.0 and
RSS 2.0 with some RSS 1.0 modules are supported) and returns PHP arrays
conforming to [JSON Feed][jsonfeed-1-spec].

[atom-rfc4287]: https://tools.ietf.org/html/rfc4287
[rss-2.0-spec]: https://cyber.harvard.edu/rss/rss.html
[jsonfeed-1-spec]: https://jsonfeed.org/version/1

Currently supported:

* [RSS 2.0][rss-2.0-spec]
  * If `<content:encoded xmlns:content="http://purl.org/rss/1.0/modules/content">`
    is present, it might override `<description>`
  * Channel-level `<cloud>` element is transformed into `hubs[]` with
    `type === 'rssCloud'`, and the URL is created from the `domain`, `port` and
    `path` attributes; `procedure` and `protocol` are put into a `_rsscloud`
    field within the hub descriptor
* [Atom 1.0 (RFC 4287)][atom-rfc4287]
  * `<content type="xhtml">` is transformed into HTML, perhaps not perfectly,
    but probably well enough. Namespaces are lost during this conversion though;
    this is currently a deliberate design choice due to JSON Feed not really
    having an equivalent to non-HTML content.

All the fields that [don't have any semantic equivalents in JSON
Feed][jsonfeed-mapping] are dropped, sorry. Probably you should look for a
different package then.

[jsonfeed-mapping]: https://jsonfeed.org/mappingrssandatom

Note that this parser is _strict_ but not _validating_. If the feed is not
correctly-formed (say, it's just a `<rss version="2.0" />`) the parser will
go through it and return basically an empty JSON Feed object, where not even
the required fields will be present. Therefore don't trust that the returned
array will be compliant -- everything might be missing. :)

## Usage

```php
$parser = new PN\FeedParse\Parser();
while ($haveXml) {
  $parser->process($xmlChunk);
}
$result = $parser->getResult();
/*
 * $result = ['version' => 'https://jsonfeed.org/version/1',
 *            'title' => '...', 'home_page_url' => '...',
 *            'items' => [
 *              ['id' => '...', 'title' => '...', '...'],
 *              // ...
 *            ]];
 */
```

## Extras

Result `item` entries can optionally have an `_fp_item_source` field, which
contains the source information, if any. The attributes are the same as a
top-level feed entity would have, except `version` is never set.

## License

[ISC](./LICENSE.txt)
