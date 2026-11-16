# DOM Scraper

Dependency-free HTML scraping for PHP — load HTML (string, file or URL) and query it with the CSS selectors you already know. Selectors are translated to XPath under the hood; the only requirements are the DOM and libxml extensions, which ship with PHP.

## Supported selectors

`tag`, `#id`, `.class`, `*`, `[attr]`, `[attr=value]`, `[attr^=value]`, `[attr$=value]`, `[attr*=value]`, `[attr~=value]`, `[attr|=value]`, descendant (`article a`), child (`article > p`) and comma groups (`h1, h2`). Pseudo-classes are not supported.

## Usage

```php
require 'src/DomScraper.php';

// From a string, a file, or a URL:
$scraper = new DomScraper($html);
$scraper = DomScraper::fromUrl('https://example.com');

// Text and attributes:
$title  = $scraper->text('h1');
$titles = $scraper->texts('article h2');
$id     = $scraper->attr('article.featured', 'data-id');

// Links and images (relative URLs resolved against a base):
$links  = $scraper->links('article h2 a', 'https://example.com');
// [['href' => 'https://example.com/posts/hello-world', 'text' => 'Hello World'], ...]
$images = $scraper->images('img', 'https://example.com');

// Raw elements for anything else:
$nodes = $scraper->query('article.post');
$first = $scraper->first('#content');

// Inner HTML of the first match:
$html  = $scraper->html('.excerpt');

// Advanced use: the underlying DOMDocument / DOMXPath are exposed:
$doc   = $scraper->getDocument();
$xpath = $scraper->getXpath();
```

Run the demo with `php examples/scrape-example.php`.

## Requirements

PHP 7.4+ with the DOM extension (mbstring recommended for non-ASCII pages, but not required).

## License

MIT — see [LICENSE](LICENSE).
