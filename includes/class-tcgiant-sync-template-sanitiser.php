<?php
/**
 * What a templated or eBay-only description may carry to eBay.
 *
 * eBay publishes no list of allowed tags or CSS. Its AddItem reference says
 * active content is "blocked/removed", and its help page says a listing with
 * it fails with "Disallowed JavaScript/HTML Syntax"
 * (https://www.ebay.com/help/policies/listing-policies/javascript-policy?id=4247).
 * So the subset here is ours, kept small, and the live tests on
 * thecardboardshop.com settle what eBay keeps of it.
 *
 * In order, for the markup:
 *
 * 1. Whole elements go first, with everything inside them. wp_kses() strips a
 *    tag it does not allow but keeps its inner text, so the code of a <script>,
 *    or the stylesheet of an imported WP-Lister description, would otherwise
 *    reach eBay as visible words.
 * 2. wp_kses() with our own list. Anything else loses its tag and keeps its
 *    text, which suits harmless wrappers such as <font> and <center>. Only
 *    http and https survive as protocols, so javascript:, vbscript: and data:
 *    URLs go; inline style goes through safecss_filter_attr() inside kses.
 * 3. Links: only to eBay's own pages, forced to https. Every other <a> becomes
 *    its text (https://www.ebay.com/help/policies/listing-policies/links-policy?id=4248).
 * 4. Images: https only; the shop's own http address is moved to https when
 *    the site serves https, anything else is dropped with a warning. Each is
 *    given max-width:100%;height:auto.
 *
 * The stylesheet never goes through kses, which turns a bare ">" into "&gt;"
 * and would break child selectors. filter_css() cleans it, and the template
 * puts it in as one <style> after the markup has been through all of the
 * above.
 *
 * Never used on the None path: a shop with no template sends exactly what
 * 3.27.7 sent (see TCGiant_Sync_Exporter::build_description()).
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Template_Sanitiser class.
 */
class TCGiant_Sync_Template_Sanitiser {

	/**
	 * eBay's ceiling on a description, in characters, tags included (AddItem
	 * Item.Description; the formatting guide). Counted with mb_strlen(), so a
	 * German description is measured as eBay measures it, not in bytes.
	 */
	const MAX_CHARS = 500000;

	/**
	 * Images wider than this are flagged: eBay's formatting guide asks for
	 * descriptions that read on a phone
	 * (https://developer.ebay.com/api-docs/user-guides/static/trading-user-guide/formatting.html).
	 */
	const MAX_FIXED_WIDTH = 700;

	/**
	 * Elements removed with everything inside them.
	 */
	const REMOVE_ELEMENTS = array(
		'script', 'noscript', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
		'form', 'select', 'textarea', 'option', 'template', 'title', 'head', 'link', 'meta',
		'base', 'svg', 'math', 'video', 'audio', 'canvas',
	);

	/**
	 * eBay's own sites, the only places a link may go.
	 */
	const EBAY_HOSTS = '/(^|\.)ebay\.(com|co\.uk|de|fr|it|es|ca|com\.au|ie|at|ch|nl|be|pl)$/i';

	/**
	 * Strings a third party reports eBay's active-content check matching. The
	 * exact rule is UNVERIFIED (live test 6), so these warn and never block
	 * (https://help.m2epro.com/support/solutions/articles/9000219018).
	 */
	const ACTIVE_CONTENT_WORDS = array( '.cookie', 'cookie(', 'replace(', 'includes' );

	/**
	 * Words for payment outside eBay's checkout.
	 */
	const PAYMENT_WORDS = array( 'paypal', 'venmo', 'zelle', 'cash app', 'cashapp', 'western union', 'moneygram', 'bank transfer', 'wire transfer', 'money order', 'bitcoin', 'crypto' );

	/**
	 * The tags and attributes kept, for wp_kses().
	 *
	 * @param bool $marker Whether the content marker's data-tcg may stay on a
	 *                     <div>. Only the whole description's last pass allows
	 *                     it, so content cannot bring a marker of its own.
	 * @return array
	 */
	public static function allowed_html( $marker = false ) {
		// id, so a converted WP-Lister template's #wpl_wrapper rules still
		// find their element. eBay shows a description in a frame of its own,
		// so an id cannot clash with eBay's page.
		$global = array(
			'id'    => true,
			'class' => true,
			'style' => true,
			'title' => true,
			'dir'   => true,
			'lang'  => true,
			'align' => true,
		);

		$tags = array( 'div', 'p', 'span', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 'small', 'sup', 'sub', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'img', 'a', 'blockquote', 'section', 'header', 'footer', 'article', 'figure', 'figcaption' );

		$allowed = array();
		foreach ( $tags as $tag ) {
			$allowed[ $tag ] = $global;
		}

		$allowed['a']     += array( 'href' => true, 'target' => true, 'rel' => true );
		$allowed['img']   += array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true );
		$allowed['table'] += array( 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'width' => true );
		foreach ( array( 'td', 'th' ) as $cell ) {
			$allowed[ $cell ] += array( 'colspan' => true, 'rowspan' => true, 'width' => true, 'valign' => true );
		}

		if ( $marker ) {
			$allowed['div']['data-tcg'] = true;
		}

		return $allowed;
	}

	/**
	 * Clean markup down to what may go to eBay.
	 *
	 * @param string $html Markup.
	 * @param array  $args 'marker'     => bool, allow the content marker;
	 *                     'home_host'  => the site's own host;
	 *                     'site_https' => whether the site serves https.
	 * @return array{html: string, removed: string[], warnings: string[]}
	 */
	public static function clean( $html, array $args = array() ) {
		$removed  = array();
		$warnings = array();
		$html     = (string) $html;

		// 1. Whole elements, with what is in them.
		if ( class_exists( 'DOMDocument' ) ) {
			$html = self::remove_elements( $html, $removed );
		} else {
			$html       = self::remove_elements_by_pattern( $html, $removed );
			$warnings[] = __( 'This server\'s PHP has no DOM extension, so scripts, styles and the like were removed by pattern matching rather than by reading the HTML. Check the preview closely.', 'tcgiant-sync' );
		}

		// 2. Our own list of tags and attributes.
		$html = wp_kses( $html, self::allowed_html( ! empty( $args['marker'] ) ), array( 'http', 'https' ) );

		// 3 and 4. Links and images, from what kses left.
		$html = self::links_and_images( $html, $args, $removed, $warnings );

		return array(
			'html'     => $html,
			'removed'  => $removed,
			'warnings' => $warnings,
		);
	}

	/**
	 * Clean a stylesheet, which never goes through kses.
	 *
	 * Removes @import, expression(), behavior, -moz-binding, javascript: and
	 * any url() that is not https, image-set() and the like with an address
	 * that is not https, and every "<", so that nothing in it can close the
	 * <style> it is put in.
	 *
	 * @param string $css Stylesheet.
	 * @return array{css: string, removed: string[]}
	 */
	public static function filter_css( $css ) {
		$removed = array();
		$css     = (string) $css;

		// Comments first: one can hide what follows from the rules below.
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '#/\*.*$#s', '', $css );

		// CSS escapes that stand for a letter, a digit, "-" or "_": a browser
		// reads @\69mport as @import and \75rl( as url(, which the checks
		// below would not. Written out as the letter they stand for, which a
		// browser reads the same; any other escape is left as it is. An
		// escaped backslash is one pair, read first and left as it is: taken
		// apart, @\\69mport would become @\import, which a browser reads as
		// @import.
		$css = preg_replace_callback( '/\\\\(?:(\\\\)|([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?|([g-zG-Z_\-]))/', function ( $m ) {
			if ( ! empty( $m[1] ) ) {
				return $m[0];
			}
			$char = isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( hexdec( $m[2] ) < 128 ? chr( (int) hexdec( $m[2] ) ) : '' );
			return '' !== $char && preg_match( '/^[A-Za-z0-9_\-]$/', $char ) ? $char : $m[0];
		}, $css );

		if ( false !== strpos( $css, '<' ) ) {
			$css       = str_replace( '<', '', $css );
			$removed[] = 'css: <';
		}

		$css = preg_replace_callback( '/@import\b[^;]*;?/i', function () use ( &$removed ) {
			$removed[] = 'css: @import';
			return '';
		}, $css );

		$css = preg_replace_callback( '/[^;{}]*(?:expression\s*\(|\bbehaviou?r\s*:|-moz-binding|javascript\s*:|vbscript\s*:)[^;{}]*;?/i', function ( $m ) use ( &$removed ) {
			$removed[] = 'css: ' . trim( preg_replace( '/\s+/', ' ', $m[0] ) );
			return '';
		}, $css );

		// image-set(), image() and cross-fade() take an address as a plain
		// string as well as in url(): a declaration with one that is not https
		// goes whole. Read string by string, so a ";" or "}" inside one does
		// not end the declaration early.
		$css = preg_replace_callback( '/(?:"[^"]*"|\'[^\']*\'|[^;{}"\'])*?(?<![\w\-])(?:-webkit-)?(?:image-set|image|cross-fade)\s*\((?:"[^"]*"|\'[^\']*\'|[^;{}"\'])*;?/i', function ( $m ) use ( &$removed ) {
			if ( preg_match_all( '/(["\'])(.*?)\1/s', $m[0], $strings ) ) {
				foreach ( $strings[2] as $address ) {
					if ( 0 !== stripos( trim( $address ), 'https://' ) || preg_match( '/[\s\'"()\\\\]/', trim( $address ) ) ) {
						$removed[] = 'css: ' . trim( preg_replace( '/\s+/', ' ', $m[0] ) );
						return '';
					}
				}
			}
			return $m[0];
		}, $css );

		// A url( never closed runs to the end of the stylesheet, where a
		// browser still fetches it: everything from it goes.
		if ( preg_match( '/url\(\s*[^)]*$/i', $css, $open, PREG_OFFSET_CAPTURE ) ) {
			$removed[] = 'css: ' . trim( preg_replace( '/\s+/', ' ', substr( $open[0][0], 0, 80 ) ) );
			$css       = substr( $css, 0, $open[0][1] );
		}

		$css = preg_replace_callback( '/url\(\s*([\'"]?)(.*?)\1\s*\)/is', function ( $m ) use ( &$removed ) {
			if ( 0 === stripos( trim( $m[2] ), 'https://' ) && ! preg_match( '/[\s\'"()\\\\]/', trim( $m[2] ) ) ) {
				return 'url("' . trim( $m[2] ) . '")';
			}
			$removed[] = 'css: url(' . trim( $m[2] ) . ')';
			return 'none';
		}, $css );

		return array(
			'css'     => trim( (string) $css ),
			'removed' => $removed,
		);
	}

	/**
	 * Warnings about what a description says, never blocking it.
	 *
	 * @param string $html Final description.
	 * @return string[]
	 */
	public static function lint( $html ) {
		$warnings = array();
		$text     = html_entity_decode( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Contact details before a sale are banned
		// (https://www.ebay.com/help/policies/payment-policies/offering-buy-sell-outside-ebay-policy?id=4272).
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m ) ) {
			/* translators: %s: what was found */
			$warnings[] = sprintf( __( 'An email address (%s): eBay bans contact details in a listing.', 'tcgiant-sync' ), $m[0] );
		}
		if ( preg_match( '/(?<![\w])(?:\+\d{1,3}[\s.\-]?)?(?:\(\d{2,4}\)[\s.\-]?|\d{2,4}[\s.\-])\d{3,4}[\s.\-]\d{3,4}(?![\w])/', $text, $m ) ) {
			/* translators: %s: what was found */
			$warnings[] = sprintf( __( 'What looks like a phone number (%s): eBay bans contact details in a listing.', 'tcgiant-sync' ), trim( $m[0] ) );
		}
		if ( preg_match_all( '/\b(?:https?:\/\/)?(?:www\.)?([a-z0-9][a-z0-9\-]*\.)+(?:com|net|org|co\.uk|de|fr|it|es|ca|com\.au|io|shop|store|biz|info)\b/i', $text, $all ) ) {
			foreach ( $all[0] as $domain ) {
				$host = preg_replace( '#^https?://#i', '', $domain );
				if ( ! preg_match( self::EBAY_HOSTS, $host ) ) {
					/* translators: %s: a web address */
					$warnings[] = sprintf( __( 'A web address (%s): eBay allows links and addresses for its own pages only.', 'tcgiant-sync' ), $domain );
					break;
				}
			}
		}
		if ( preg_match( '/(?:^|[\s(])@[A-Za-z0-9_.]{3,}\b|\b(?:facebook|instagram|twitter|tiktok|whatsapp|telegram|snapchat)\b/i', $text, $m ) ) {
			/* translators: %s: what was found */
			$warnings[] = sprintf( __( 'A social media handle or name (%s): eBay bans contact details in a listing.', 'tcgiant-sync' ), trim( $m[0] ) );
		}

		// In the text or an attribute (an alt, a link's address), of any length.
		if ( preg_match( '/\[\[[^\n]*?\]\]/', $text . "\n" . (string) $html, $m ) ) {
			/* translators: %s: what was found */
			$warnings[] = sprintf( __( 'The text %s looks like another plugin\'s placeholder, which nothing here fills: buyers would see it as it is.', 'tcgiant-sync' ), $m[0] );
		}

		$lower = strtolower( $text );
		foreach ( self::PAYMENT_WORDS as $word ) {
			if ( false !== strpos( $lower, $word ) ) {
				/* translators: %s: a payment word */
				$warnings[] = sprintf( __( 'A payment word ("%s"): only name methods buyers can choose in eBay checkout.', 'tcgiant-sync' ), $word );
				break;
			}
		}

		foreach ( self::ACTIVE_CONTENT_WORDS as $word ) {
			if ( false !== stripos( (string) $html, $word ) ) {
				/* translators: %s: a word */
				$warnings[] = sprintf( __( 'The text "%s" is reported to trip eBay\'s active-content check, which can refuse a listing. This is not confirmed; Check with eBay to be sure.', 'tcgiant-sync' ), $word );
			}
		}

		// A fixed width: not a max-width or min-width, which shrink with the
		// screen, nor a media query's "(width: 768px)", which sets none.
		if ( preg_match_all( '/(?<![\w\-(])width\s*:\s*(\d+)px|\bwidth\s*=\s*["\']?(\d+)/i', (string) $html, $widths, PREG_SET_ORDER ) ) {
			foreach ( $widths as $w ) {
				$px = max( (int) $w[1], (int) ( $w[2] ?? 0 ) );
				if ( $px > self::MAX_FIXED_WIDTH ) {
					/* translators: %d: pixels */
					$warnings[] = sprintf( __( 'A fixed width of %d px: wider than a phone screen, so the listing scrolls sideways there. Keep fixed widths to 700 px or less.', 'tcgiant-sync' ), $px );
					break;
				}
			}
		}

		return $warnings;
	}

	/**
	 * Parse markup into a document whose <body> holds it.
	 *
	 * The tags that would end the body early are taken out first: libxml stops
	 * reading at a stray </body>, and everything after it was lost.
	 *
	 * @param string $html Markup.
	 * @return array{0: DOMDocument, 1: DOMElement}|null
	 */
	private static function load( $html ) {
		$html = preg_replace( '#</?(?:html|body)\b[^>]*>#i', '', (string) $html );

		$doc  = new DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$body = $ok ? $doc->getElementsByTagName( 'body' )->item( 0 ) : null;
		return $body instanceof DOMElement ? array( $doc, $body ) : null;
	}

	/**
	 * The markup inside a node.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param DOMNode     $node Node.
	 * @return string
	 */
	private static function inner( DOMDocument $doc, DOMNode $node ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}
		return $out;
	}

	/**
	 * Step 1, reading the HTML.
	 *
	 * @param string   $html    Markup.
	 * @param string[] $removed Filled with what went.
	 * @return string
	 */
	private static function remove_elements( $html, array &$removed ) {
		// Elements with no content of their own go by pattern first. libxml
		// reads an <embed> as one that holds what follows it, and removing it
		// took the rest of the description with it.
		$html = preg_replace_callback( '#</?(embed|meta|link|base|frame|param|source|track)\b[^>]*>#i', function ( $m ) use ( &$removed ) {
			if ( '/' !== $m[0][1] ) {
				$removed[] = strtolower( $m[1] );
			}
			return '';
		}, (string) $html );

		$loaded = self::load( $html );
		if ( null === $loaded ) {
			return self::remove_elements_by_pattern( $html, $removed );
		}
		list( $doc, $body ) = $loaded;

		// A <head> libxml made of its own still holds whatever it moved there.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'head' ) ) as $head ) {
			if ( $head->hasChildNodes() ) {
				$removed[] = 'head';
			}
		}

		$doomed = array();
		$xpath  = new DOMXPath( $doc );
		foreach ( $xpath->query( '//body//*|//body//comment()|//body//processing-instruction()' ) as $node ) {
			if ( $node instanceof DOMComment ) {
				$doomed[]  = $node;
				$removed[] = 'comment';
			} elseif ( $node instanceof DOMProcessingInstruction ) {
				$doomed[] = $node;
			} elseif ( $node instanceof DOMElement && in_array( strtolower( $node->nodeName ), self::REMOVE_ELEMENTS, true ) ) {
				$doomed[]  = $node;
				$removed[] = strtolower( $node->nodeName );
			}
		}

		foreach ( $doomed as $node ) {
			// One inside another already gone has no parent left to leave.
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		return self::inner( $doc, $body );
	}

	/**
	 * Step 1 without PHP's DOM extension.
	 *
	 * @param string   $html    Markup.
	 * @param string[] $removed Filled with what went.
	 * @return string
	 */
	private static function remove_elements_by_pattern( $html, array &$removed ) {
		$html = preg_replace_callback( '/<!--.*?(?:-->|$)/s', function () use ( &$removed ) {
			$removed[] = 'comment';
			return '';
		}, (string) $html );

		foreach ( self::REMOVE_ELEMENTS as $name ) {
			$whole = '#<' . $name . '\b[^>]*>.*?</' . $name . '\s*>#is';
			$lone  = '#</?' . $name . '\b[^>]*>#i';
			$html  = preg_replace_callback( $whole, function () use ( &$removed, $name ) {
				$removed[] = $name;
				return '';
			}, $html );
			$html  = preg_replace_callback( $lone, function () use ( &$removed, $name ) {
				$removed[] = $name;
				return '';
			}, $html );
		}

		return $html;
	}

	/**
	 * Steps 3 and 4.
	 *
	 * @param string   $html     Markup kses has been through.
	 * @param array    $args     See clean().
	 * @param string[] $removed  Filled with what went.
	 * @param string[] $warnings Filled with what was said.
	 * @return string
	 */
	private static function links_and_images( $html, array $args, array &$removed, array &$warnings ) {
		if ( false === stripos( $html, '<a' ) && false === stripos( $html, '<img' ) && false === stripos( $html, 'url(' ) ) {
			return $html;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return self::links_and_images_by_pattern( $html, $args, $removed, $warnings );
		}

		$loaded = self::load( $html );
		if ( null === $loaded ) {
			return self::links_and_images_by_pattern( $html, $args, $removed, $warnings );
		}
		list( $doc, $body ) = $loaded;

		foreach ( iterator_to_array( $body->getElementsByTagName( 'a' ) ) as $a ) {
			$href = self::ebay_link( (string) $a->getAttribute( 'href' ) );
			if ( '' !== $href ) {
				$a->setAttribute( 'href', $href );
				continue;
			}
			$host      = (string) wp_parse_url( (string) $a->getAttribute( 'href' ), PHP_URL_HOST );
			$removed[] = 'link' . ( '' !== $host ? ' to ' . $host : '' );
			while ( $a->firstChild ) {
				$a->parentNode->insertBefore( $a->firstChild, $a );
			}
			$a->parentNode->removeChild( $a );
		}

		foreach ( iterator_to_array( $body->getElementsByTagName( 'img' ) ) as $img ) {
			$src = self::image_src( (string) $img->getAttribute( 'src' ), $args );
			if ( '' === $src ) {
				$was       = (string) $img->getAttribute( 'src' );
				$removed[] = 'image ' . ( '' !== $was ? $was : '(no address)' );
				/* translators: %s: an image address */
				$warnings[] = sprintf( __( 'An image was left out because it is not served over https: %s', 'tcgiant-sync' ), '' !== $was ? $was : __( '(no address)', 'tcgiant-sync' ) );
				$img->parentNode->removeChild( $img );
				continue;
			}
			$img->setAttribute( 'src', $src );
			// Once: the content region comes through here twice.
			$style = trim( (string) $img->getAttribute( 'style' ) );
			if ( false === stripos( $style, 'max-width:100%;height:auto' ) ) {
				$img->setAttribute( 'style', ( '' !== $style ? rtrim( $style, '; ' ) . ';' : '' ) . 'max-width:100%;height:auto' );
			}
		}

		// A background picture in an inline style is a picture too: kses
		// lets any http(s) address through there.
		foreach ( iterator_to_array( $body->getElementsByTagName( '*' ) ) as $el ) {
			if ( $el->hasAttribute( 'style' ) && false !== stripos( (string) $el->getAttribute( 'style' ), 'url(' ) ) {
				$el->setAttribute( 'style', self::style_urls( (string) $el->getAttribute( 'style' ), $args, $removed, $warnings ) );
			}
		}

		return self::inner( $doc, $body );
	}

	/**
	 * The url() addresses in an inline style, by the rule for images.
	 *
	 * @param string   $style    The style attribute's value.
	 * @param array    $args     See clean().
	 * @param string[] $removed  Filled with what went.
	 * @param string[] $warnings Filled with what was said.
	 * @return string
	 */
	private static function style_urls( $style, array $args, array &$removed, array &$warnings ) {
		return (string) preg_replace_callback( '/url\(\s*([\'"]?)(.*?)\1\s*\)/is', function ( $m ) use ( $args, &$removed, &$warnings ) {
			$src = self::image_src( trim( $m[2] ), $args );
			if ( '' !== $src && ! preg_match( '/[\s\'"()\\\\]/', $src ) ) {
				return 'url(' . $src . ')';
			}
			$removed[] = 'image ' . trim( $m[2] );
			/* translators: %s: an image address */
			$warnings[] = sprintf( __( 'An image was left out because it is not served over https: %s', 'tcgiant-sync' ), trim( $m[2] ) );
			return 'none';
		}, $style );
	}

	/**
	 * Steps 3 and 4 without PHP's DOM extension, on kses's tidy output.
	 *
	 * @param string   $html     Markup kses has been through.
	 * @param array    $args     See clean().
	 * @param string[] $removed  Filled with what went.
	 * @param string[] $warnings Filled with what was said.
	 * @return string
	 */
	private static function links_and_images_by_pattern( $html, array $args, array &$removed, array &$warnings ) {
		// A pair: an opening tag and the first </a> after it, with no other
		// <a> between - one never closed is not paired with the next one's.
		$html = preg_replace_callback( '#<a\b([^>]*)>((?:(?!<a\b).)*?)</a>#is', function ( $m ) use ( &$removed ) {
			$href = preg_match( '/href\s*=\s*"([^"]*)"/i', $m[1], $h ) ? html_entity_decode( $h[1] ) : '';
			$ok   = self::ebay_link( $href );
			if ( '' !== $ok ) {
				return '<a' . preg_replace( '/href\s*=\s*"[^"]*"/i', 'href="' . esc_url( $ok ) . '"', $m[1] ) . '>' . $m[2] . '</a>';
			}
			$removed[] = 'link';
			return $m[2];
		}, $html );

		// An <a> never closed has no pair above: its tag goes, unless it is
		// an eBay link the pass above kept.
		$html = preg_replace_callback( '#<a\b([^>]*)>#i', function ( $m ) use ( &$removed ) {
			$href = preg_match( '/href\s*=\s*"([^"]*)"/i', $m[1], $h ) ? html_entity_decode( $h[1] ) : '';
			if ( '' !== $href && self::ebay_link( $href ) === $href ) {
				return $m[0];
			}
			$removed[] = 'link';
			return '';
		}, $html );

		$html = preg_replace_callback( '#(\sstyle\s*=\s*")([^"]*)(")#i', function ( $m ) use ( $args, &$removed, &$warnings ) {
			if ( false === stripos( $m[2], 'url(' ) ) {
				return $m[0];
			}
			return $m[1] . esc_attr( self::style_urls( html_entity_decode( $m[2], ENT_QUOTES ), $args, $removed, $warnings ) ) . $m[3];
		}, $html );

		return preg_replace_callback( '#<img\b([^>]*)/?>#i', function ( $m ) use ( $args, &$removed, &$warnings ) {
			$was = preg_match( '/src\s*=\s*"([^"]*)"/i', $m[1], $s ) ? html_entity_decode( $s[1] ) : '';
			$src = self::image_src( $was, $args );
			if ( '' === $src ) {
				$removed[] = 'image ' . $was;
				/* translators: %s: an image address */
				$warnings[] = sprintf( __( 'An image was left out because it is not served over https: %s', 'tcgiant-sync' ), $was );
				return '';
			}
			$attrs = preg_replace( '/src\s*=\s*"[^"]*"/i', 'src="' . esc_url( $src ) . '"', $m[1] );
			$attrs = preg_replace( '/\sstyle\s*=\s*"[^"]*"/i', '', $attrs );
			return '<img' . rtrim( $attrs, ' /' ) . ' style="max-width:100%;height:auto">';
		}, $html );
	}

	/**
	 * A link's address when it may stay: eBay's own pages, as https.
	 *
	 * @param string $href Address.
	 * @return string The address to keep, or '' when the link goes.
	 */
	public static function ebay_link( $href ) {
		$href = trim( (string) $href );

		// parse_url() and a browser disagree about some addresses. A browser
		// reads "\" as "/" and drops tabs and newlines, so in
		// https://evil.example\@www.ebay.com/ parse_url() sees the host
		// www.ebay.com and the browser evil.example. None of them, and no
		// user:password@ part, belongs in a link to eBay.
		if ( preg_match( '/[\\\\\s\x00-\x1f\x7f]/', $href ) ) {
			return '';
		}
		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		// A plain host name, letters, digits, dots and hyphens, before it is
		// judged an eBay one.
		if ( ! preg_match( '/^[a-z0-9.\-]+$/i', (string) $parts['host'] ) || ! preg_match( self::EBAY_HOSTS, (string) $parts['host'] ) ) {
			return '';
		}
		return 'https://' . substr( $href, strpos( $href, '://' ) + 3 );
	}

	/**
	 * An image's address when it may stay.
	 *
	 * @param string $src  Address.
	 * @param array  $args See clean().
	 * @return string The address to use, or '' when the image goes.
	 */
	private static function image_src( $src, array $args ) {
		$src   = trim( (string) $src );
		$parts = wp_parse_url( $src );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' === $scheme ) {
			return $src;
		}
		$own = '' !== (string) ( $args['home_host'] ?? '' ) && 0 === strcasecmp( (string) $parts['host'], (string) $args['home_host'] );
		if ( 'http' === $scheme && $own && ! empty( $args['site_https'] ) ) {
			return 'https://' . substr( $src, 7 );
		}
		return '';
	}
}
