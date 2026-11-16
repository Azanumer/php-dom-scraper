<?php
/**
 * DomScraper example — scraping a blog listing page.
 *
 * Run: php examples/scrape-example.php
 * (Needs the DOM extension; no network access for the local demo.
 * The live-fetch demo at the bottom is optional — comment it out offline.)
 */

require __DIR__ . '/../src/DomScraper.php';

$html = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
  <main id="content">
    <article class="post featured" data-id="101">
      <h2><a href="/posts/hello-world">Hello World</a></h2>
      <p class="meta">By <span class="author">Azan</span> on 2026-11-16</p>
      <p class="excerpt">First post on the new blog.</p>
    </article>
    <article class="post" data-id="102">
      <h2><a href="/posts/speed-tips">10 WordPress Speed Tips</a></h2>
      <p class="meta">By <span class="author">Azan</span> on 2026-11-15</p>
      <p class="excerpt">Cache everything, defer the rest.</p>
    </article>
    <article class="post" data-id="103">
      <h2><a href="/posts/nginx-guide">Nginx for WordPress</a></h2>
      <p class="meta">By <span class="author">Sara</span> on 2026-11-14</p>
      <p class="excerpt">FastCGI cache, the easy way.</p>
    </article>
  </main>
  <footer>
    <img src="/logo.png" alt="Site logo" />
    <a href="/about">About</a>
  </footer>
</body>
</html>
HTML;

$scraper = new DomScraper( $html );

// 1. Text of the first article title.
echo 'First title: ' . $scraper->text( 'article.post h2 a' ) . PHP_EOL;

// 2. All article titles.
echo 'All titles:' . PHP_EOL;
foreach ( $scraper->texts( 'article h2' ) as $title ) {
	echo '  - ' . $title . PHP_EOL;
}

// 3. The featured post only (class selector + attribute selector).
echo 'Featured excerpt: ' . $scraper->text( 'article.featured .excerpt' ) . PHP_EOL;
echo 'Featured data-id: ' . $scraper->attr( 'article.featured', 'data-id' ) . PHP_EOL;

// 4. Every article link, resolved against the site base URL.
echo 'Links:' . PHP_EOL;
foreach ( $scraper->links( 'article h2 a', 'https://example.com' ) as $link ) {
	echo '  ' . $link['href'] . ' — ' . $link['text'] . PHP_EOL;
}

// 5. Child combinator: only direct <p> children of articles.
echo 'Direct <p> children of articles: ' . count( $scraper->query( 'article > p' ) ) . PHP_EOL;

// 6. Comma group: authors and excerpts together.
echo 'Authors + excerpts:' . PHP_EOL;
foreach ( $scraper->texts( '.author, .excerpt' ) as $text ) {
	echo '  - ' . $text . PHP_EOL;
}

// 7. Images.
foreach ( $scraper->images( 'footer img', 'https://example.com' ) as $img ) {
	echo 'Image: ' . $img['src'] . ' (alt: ' . $img['alt'] . ')' . PHP_EOL;
}

// 8. Live fetch (optional — needs network access).
// $live = DomScraper::fromUrl( 'https://example.com' );
// echo 'Live page title: ' . $live->text( 'h1' ) . PHP_EOL;
