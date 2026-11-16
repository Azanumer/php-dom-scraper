<?php
/**
 * DomScraper — dependency-free HTML scraping with CSS selectors.
 *
 * Loads HTML (string, file or URL) into DOMDocument and lets you query it
 * with the CSS selectors you already know, translated to XPath under the
 * hood. No extensions beyond DOM and libxml (both ship with PHP).
 *
 * Supported selectors:
 *   tag, #id, .class, *, [attr], [attr=value], [attr^=value],
 *   [attr$=value], [attr*=value], descendant (space), child (>),
 *   comma groups (a, b). Pseudo-classes are NOT supported.
 *
 * Example:
 *   $scraper = DomScraper::fromUrl( 'https://example.com' );
 *   $title   = $scraper->text( 'h1' );
 *   $links   = $scraper->links( 'article a' ); // [['href' => ..., 'text' => ...], ...]
 */

declare(strict_types=1);

class DomScraper
{
	/** @var DOMDocument */
	private $document;

	/** @var DOMXPath */
	private $xpath;

	/**
	 * @param string $html HTML to load (empty = blank document).
	 */
	public function __construct( string $html = '' ) {
		$this->load( $html );
	}

	/**
	 * Load an HTML string, replacing any previous document.
	 *
	 * @param string $html HTML markup.
	 * @return $this
	 */
	public function load( string $html ) {
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		// Convert to HTML entities so UTF-8 content survives loadHTML().
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$html = mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
		}
		$doc->loadHTML( $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->document = $doc;
		$this->xpath    = new DOMXPath( $doc );
		return $this;
	}

	/**
	 * Load HTML from a file.
	 *
	 * @param string $path File path.
	 * @return $this
	 * @throws InvalidArgumentException When the file cannot be read.
	 */
	public function loadFile( string $path ) {
		$html = @file_get_contents( $path );
		if ( false === $html ) {
			throw new InvalidArgumentException( "Cannot read file: {$path}" );
		}
		return $this->load( $html );
	}

	/**
	 * Fetch a URL and load the response HTML.
	 *
	 * Uses cURL when available, file_get_contents() otherwise.
	 *
	 * @param string $url     URL to fetch.
	 * @param int    $timeout Request timeout in seconds.
	 * @return $this
	 * @throws RuntimeException When the fetch fails or returns no body.
	 */
	public function loadUrl( string $url, int $timeout = 10 ) {
		$html = null;

		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS      => 5,
					CURLOPT_TIMEOUT        => $timeout,
					CURLOPT_USERAGENT      => 'DomScraper/1.0 (+https://github.com/Azanumer/php-dom-scraper)',
				)
			);
			$html = curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_close( $ch );
			if ( false === $html || $code >= 400 ) {
				throw new RuntimeException( "Failed to fetch {$url} (HTTP {$code})" );
			}
		} else {
			$context = stream_context_create(
				array(
					'http' => array(
						'timeout'         => $timeout,
						'follow_location' => 1,
						'max_redirects'   => 5,
						'header'          => "User-Agent: DomScraper/1.0\r\n",
					),
				)
			);
			$html = @file_get_contents( $url, false, $context );
			if ( false === $html ) {
				throw new RuntimeException( "Failed to fetch {$url}" );
			}
		}

		return $this->load( $html );
	}

	/**
	 * Create a scraper from a URL in one call.
	 *
	 * @param string $url     URL to fetch.
	 * @param int    $timeout Request timeout in seconds.
	 * @return self
	 */
	public static function fromUrl( string $url, int $timeout = 10 ) {
		$scraper = new self();
		return $scraper->loadUrl( $url, $timeout );
	}

	/**
	 * All elements matching a CSS selector.
	 *
	 * @param string $selector CSS selector.
	 * @return DOMElement[]
	 */
	public function query( string $selector ): array {
		$xpath = $this->selectorToXpath( $selector );
		$nodes = $this->xpath->query( $xpath );
		if ( false === $nodes ) {
			return array();
		}
		$out = array();
		foreach ( $nodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * First element matching a CSS selector, or null.
	 *
	 * @param string $selector CSS selector.
	 * @return DOMElement|null
	 */
	public function first( string $selector ) {
		$nodes = $this->query( $selector );
		return $nodes ? $nodes[0] : null;
	}

	/**
	 * Trimmed, whitespace-normalised text of the first match ('' if none).
	 *
	 * @param string $selector CSS selector.
	 * @return string
	 */
	public function text( string $selector ): string {
		$node = $this->first( $selector );
		return null === $node ? '' : self::normaliseText( $node->textContent );
	}

	/**
	 * Text of every match, in document order.
	 *
	 * @param string $selector CSS selector.
	 * @return string[]
	 */
	public function texts( string $selector ): array {
		$out = array();
		foreach ( $this->query( $selector ) as $node ) {
			$out[] = self::normaliseText( $node->textContent );
		}
		return $out;
	}

	/**
	 * Attribute value of the first match (null when missing).
	 *
	 * @param string $selector CSS selector.
	 * @param string $name     Attribute name.
	 * @return string|null
	 */
	public function attr( string $selector, string $name ) {
		$node = $this->first( $selector );
		if ( null === $node || ! $node->hasAttribute( $name ) ) {
			return null;
		}
		return $node->getAttribute( $name );
	}

	/**
	 * Attribute values of every match (null for elements missing it).
	 *
	 * @param string $selector CSS selector.
	 * @param string $name     Attribute name.
	 * @return array
	 */
	public function attrs( string $selector, string $name ): array {
		$out = array();
		foreach ( $this->query( $selector ) as $node ) {
			$out[] = $node->hasAttribute( $name ) ? $node->getAttribute( $name ) : null;
		}
		return $out;
	}

	/**
	 * Inner HTML of the first match ('' if none).
	 *
	 * @param string $selector CSS selector.
	 * @return string
	 */
	public function html( string $selector ): string {
		$node = $this->first( $selector );
		if ( null === $node ) {
			return '';
		}
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $this->document->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Links inside a selector: [['href' => ..., 'text' => ...], ...].
	 * Resolves relative hrefs against $baseUrl when given.
	 *
	 * @param string      $selector CSS selector for the anchors (default 'a').
	 * @param string|null $baseUrl  Base URL for relative hrefs.
	 * @return array
	 */
	public function links( string $selector = 'a', ?string $baseUrl = null ): array {
		$out = array();
		foreach ( $this->query( $selector ) as $node ) {
			$href = $node->getAttribute( 'href' );
			if ( '' === $href ) {
				continue;
			}
			if ( null !== $baseUrl ) {
				$href = self::resolveUrl( $href, $baseUrl );
			}
			$out[] = array(
				'href' => $href,
				'text' => self::normaliseText( $node->textContent ),
			);
		}
		return $out;
	}

	/**
	 * Images inside a selector: [['src' => ..., 'alt' => ...], ...].
	 * Resolves relative srcs against $baseUrl when given.
	 *
	 * @param string      $selector CSS selector for the images (default 'img').
	 * @param string|null $baseUrl  Base URL for relative srcs.
	 * @return array
	 */
	public function images( string $selector = 'img', ?string $baseUrl = null ): array {
		$out = array();
		foreach ( $this->query( $selector ) as $node ) {
			$src = $node->getAttribute( 'src' );
			if ( '' === $src ) {
				continue;
			}
			if ( null !== $baseUrl ) {
				$src = self::resolveUrl( $src, $baseUrl );
			}
			$out[] = array(
				'src' => $src,
				'alt' => $node->getAttribute( 'alt' ),
			);
		}
		return $out;
	}

	/**
	 * The underlying DOMDocument (for advanced use).
	 *
	 * @return DOMDocument
	 */
	public function getDocument(): DOMDocument {
		return $this->document;
	}

	/**
	 * The underlying DOMXPath (for advanced use).
	 *
	 * @return DOMXPath
	 */
	public function getXpath(): DOMXPath {
		return $this->xpath;
	}

	/* ------------------------------------------------------------------ */
	/*  Selector → XPath translation                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Translate a CSS selector (possibly a comma group) to an XPath
	 * expression. Each group becomes a union branch.
	 *
	 * @param string $selector CSS selector.
	 * @return string XPath expression.
	 * @throws InvalidArgumentException On an unsupported selector.
	 */
	private function selectorToXpath( string $selector ): string {
		$selector = trim( $selector );
		if ( '' === $selector ) {
			throw new InvalidArgumentException( 'Empty selector' );
		}
		$branches = array();
		foreach ( self::splitTopLevel( $selector, ',' ) as $group ) {
			$branches[] = $this->groupToXpath( trim( $group ) );
		}
		return implode( ' | ', $branches );
	}

	/**
	 * Translate one comma-group (a chain of simple selectors joined by
	 * descendant / child combinators) to XPath.
	 *
	 * @param string $group Single selector group.
	 * @return string XPath expression.
	 */
	private function groupToXpath( string $group ): string {
		// Split on combinators, keeping them: '>' or runs of whitespace.
		$parts = preg_split( '/(\s*>\s*|\s+)/', $group, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$xpath     = '';
		$combinator = '//'; // The first simple selector searches the whole document.
		foreach ( $parts as $part ) {
			$trimmed = trim( $part );
			if ( '>' === $trimmed ) {
				$combinator = '/';
				continue;
			}
			if ( '' === $trimmed ) {
				$combinator = '//';
				continue;
			}
			$xpath .= $combinator . $this->simpleSelectorToXpath( $trimmed );
			$combinator = '//'; // Default between two simple selectors is descendant.
		}
		return $xpath;
	}

	/**
	 * Translate one simple selector (tag, #id, .class, [attr...]) to an
	 * XPath step, e.g. "article.post[data-id=7]" →
	 * "article[contains(concat(' ', normalize-space(@class), ' '), ' post ')][@data-id='7']".
	 *
	 * @param string $simple Simple selector.
	 * @return string XPath step.
	 */
	private function simpleSelectorToXpath( string $simple ): string {
		$tag      = '*';
		$tagSet   = false;
		$conditions = array();

		$offset = 0;
		$len    = strlen( $simple );
		while ( $offset < $len ) {
			$ch = $simple[ $offset ];
			if ( '#' === $ch ) {
				if ( ! preg_match( '/#([\w-]+)/', $simple, $m, 0, $offset ) ) {
					throw new InvalidArgumentException( "Invalid selector: {$simple}" );
				}
				$conditions[] = '@id=' . self::xpathLiteral( $m[1] );
				$offset      += strlen( $m[0] );
			} elseif ( '.' === $ch ) {
				if ( ! preg_match( '/\.([\w-]+)/', $simple, $m, 0, $offset ) ) {
					throw new InvalidArgumentException( "Invalid selector: {$simple}" );
				}
				$conditions[] = "contains(concat(' ', normalize-space(@class), ' '), " . self::xpathLiteral( ' ' . $m[1] . ' ' ) . ')';
				$offset      += strlen( $m[0] );
			} elseif ( '[' === $ch ) {
				$close = strpos( $simple, ']', $offset );
				if ( false === $close ) {
					throw new InvalidArgumentException( "Invalid selector: {$simple}" );
				}
				$conditions[] = $this->attributeToXpath( substr( $simple, $offset + 1, $close - $offset - 1 ) );
				$offset        = $close + 1;
			} elseif ( preg_match( '/^(\*|[a-zA-Z][\w-]*)/', substr( $simple, $offset ), $m ) ) {
				if ( $tagSet ) {
					throw new InvalidArgumentException( "Invalid selector: {$simple}" );
				}
				$tag    = $m[1];
				$tagSet = true;
				$offset += strlen( $m[0] );
			} else {
				throw new InvalidArgumentException( "Invalid selector: {$simple}" );
			}
		}

		$step = $tag;
		foreach ( $conditions as $condition ) {
			$step .= '[' . $condition . ']';
		}
		return $step;
	}

	/**
	 * Translate the inside of [ ... ] to an XPath predicate.
	 *
	 * @param string $inner Contents between the brackets.
	 * @return string XPath predicate (without brackets).
	 */
	private function attributeToXpath( string $inner ): string {
		$inner = trim( $inner );
		if ( ! preg_match( '/^([\w-]+)(?:\s*([~|^$*]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+)))?$/', $inner, $m ) ) {
			throw new InvalidArgumentException( "Invalid attribute selector: [{$inner}]" );
		}
		$attr = $m[1];
		if ( ! isset( $m[2] ) || '' === $m[2] ) {
			return '@' . $attr;
		}
		$op    = $m[2];
		$value = isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( isset( $m[4] ) && '' !== $m[4] ? $m[4] : ( isset( $m[5] ) ? $m[5] : '' ) );
		$lit   = self::xpathLiteral( $value );

		switch ( $op ) {
			case '=':
				return '@' . $attr . '=' . $lit;
			case '^=':
				return "starts-with(@{$attr}, {$lit})";
			case '$=':
				// substring(@attr, string-length(@attr) - string-length('v') + 1) = 'v'
				return "substring(@{$attr}, string-length(@{$attr}) - string-length({$lit}) + 1) = {$lit}";
			case '*=':
				return "contains(@{$attr}, {$lit})";
			case '~=':
				return "contains(concat(' ', normalize-space(@{$attr}), ' '), " . self::xpathLiteral( ' ' . $value . ' ' ) . ')';
			case '|=':
				return "@{$attr} = {$lit} or starts-with(@{$attr}, " . self::xpathLiteral( $value . '-' ) . ')';
			default:
				throw new InvalidArgumentException( "Unsupported attribute operator: {$op}" );
		}
	}

	/**
	 * Split a string on a delimiter that appears outside [...] and quotes.
	 *
	 * @param string $input     Input string.
	 * @param string $delimiter Single-character delimiter.
	 * @return string[]
	 */
	private static function splitTopLevel( string $input, string $delimiter ): array {
		$parts   = array();
		$current = '';
		$depth   = 0;
		$quote   = null;
		$len     = strlen( $input );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $input[ $i ];
			if ( null !== $quote ) {
				$current .= $ch;
				if ( $ch === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote    = $ch;
				$current .= $ch;
				continue;
			}
			if ( '[' === $ch ) {
				$depth++;
			} elseif ( ']' === $ch ) {
				$depth = max( 0, $depth - 1 );
			}
			if ( 0 === $depth && $ch === $delimiter ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $ch;
		}
		$parts[] = $current;
		return $parts;
	}

	/**
	 * Quote a string as an XPath literal, handling embedded quotes.
	 *
	 * @param string $value Value to quote.
	 * @return string XPath string literal.
	 */
	private static function xpathLiteral( string $value ): string {
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		// Both quote types present: build a concat() of single-quoted parts.
		$parts = explode( "'", $value );
		return "concat('" . implode( "', \"'\", '", $parts ) . "')";
	}

	/**
	 * Collapse whitespace runs to a single space and trim.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function normaliseText( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Resolve a possibly-relative URL against a base URL.
	 *
	 * @param string $url     URL to resolve.
	 * @param string $baseUrl Base URL.
	 * @return string
	 */
	private static function resolveUrl( string $url, string $baseUrl ): string {
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) || '//' === substr( $url, 0, 2 ) || '#' === substr( $url, 0, 1 ) ) {
			return $url;
		}
		$base = parse_url( $baseUrl );
		if ( false === $base || ! isset( $base['scheme'], $base['host'] ) ) {
			return $url;
		}
		$root = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
		if ( '/' === substr( $url, 0, 1 ) ) {
			return $root . $url;
		}
		$path = isset( $base['path'] ) ? $base['path'] : '/';
		$dir  = substr( $path, -1 ) === '/' ? $path : substr( $path, 0, strrpos( $path, '/' ) + 1 );
		$merged = $dir . $url;
		// Resolve ./ and ../ segments.
		$segments = array();
		foreach ( explode( '/', $merged ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		return $root . '/' . implode( '/', $segments );
	}
}
