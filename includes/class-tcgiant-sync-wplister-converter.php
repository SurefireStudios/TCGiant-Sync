<?php
/**
 * Turn a WP-Lister for eBay listing template into a TCGiant one.
 *
 * WP-Lister keeps a template as a folder - wp-content/uploads/wp-lister/
 * templates/<name>/ - and its Templates page downloads one as <name>.zip with
 * that folder at the top (read from WP-Lister Pro 3.8.8's own source):
 *
 * - template.html: the body, with [[...]] placeholders;
 * - style.css: a header comment, then CSS using $<field> variables;
 * - config.json: the seller's values for the template's custom fields;
 * - functions.php: PHP that registers those fields and their defaults with
 *   wplister_register_custom_fields( type, slug, default, label, config );
 * - header.php, footer.php and gallery views: more PHP.
 *
 * Only template.html, style.css and config.json are taken. functions.php is
 * read as text, never run, for the defaults of its custom fields, matched by
 * pattern; config.json's values win over them; both are written into the HTML
 * and CSS as plain values. No file is ever included, required or evaluated,
 * and nothing is extracted to disk: a zip is read in memory, a file at a time,
 * each one capped.
 *
 * What comes out is a draft, saved through the same save path as the editor,
 * and a report: what was mapped, what has no equivalent, what was dropped
 * (PHP, widgets, iframes), the meta keys that need the allowlist, and the
 * attribute labels that match no attribute of this shop's.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_WPLister_Converter class.
 */
class TCGiant_Sync_WPLister_Converter {

	/** A WP-Lister download larger than this is not a template. */
	const MAX_ZIP_BYTES = 2097152;

	/** Nor is any one file in it larger than this. */
	const MAX_FILE_BYTES = 524288;

	/** The files read. Everything else is left where it is. */
	const FILES = array( 'template.html', 'style.css', 'config.json', 'functions.php' );

	/** One placeholder for another, as they are. */
	const SIMPLE = array(
		'product_title'                    => '{{title}}',
		'product_content'                  => '{{{description}}}',
		'product_excerpt'                  => '{{{short_description}}}',
		'product_excerpt_nl2br'            => '{{{short_description}}}',
		'product_additional_content'       => '{{{short_description}}}',
		'product_additional_content_nl2br' => '{{{short_description}}}',
		'product_sku'                      => '{{sku}}',
		'ebay_store_url'                   => '{{ebay_store_url}}',
		'product_main_image_url'           => '{{main_image}}',
		'product_main_image'               => '{{#main_image}}<img src="{{main_image}}" alt="{{title}}" style="max-width:100%;height:auto">{{/main_image}}',
		'product_gallery'                  => '{{{gallery}}}',
		'additional_product_images'        => '{{{gallery}}}',
		'product_thumbnails'               => '{{{gallery}}}',
		'product_variations'               => '{{{variations_table}}}',
	);

	/** WP-Lister's that have no equivalent here, and are taken out, by why. */
	const UNSUPPORTED = array(
		'price'   => array( 'product_price', 'product_price_raw' ),
		'listing' => array( 'ebay_item_id', 'wpl_listing_id', 'admin_ajax_url' ),
		'other'   => array( 'ebay_store_category_id', 'ebay_store_category_name', 'product_category', 'product_tags', 'product_weight', 'product_width', 'product_height', 'product_length', 'product_addons' ),
	);

	/** Widgets that write an iframe with document.write: active content. */
	const WIDGETS = array( 'widget_new_listings', 'widget_featured_listings', 'widget_ending_listings', 'widget_related_listings' );

	// -------------------------------------------------------------------------
	// Reading
	// -------------------------------------------------------------------------

	/**
	 * Read a WP-Lister template zip, in memory.
	 *
	 * @param string $path The uploaded file.
	 * @return array|WP_Error files (name => text), folder, php (PHP files seen),
	 *                        ignored (other files and deeper folders).
	 */
	public static function read_zip( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'This server\'s PHP cannot read zip files. Paste the template\'s HTML and CSS instead.', 'tcgiant-sync' ) );
		}
		if ( ! is_file( $path ) || filesize( $path ) > self::MAX_ZIP_BYTES ) {
			return new WP_Error( 'too_large', self::too_large_message() );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'not_zip', __( 'That file could not be read as a zip.', 'tcgiant-sync' ) );
		}

		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) ) {
				continue;
			}
			$name = (string) $stat['name'];

			// A path that climbs out of its folder, or starts at the root, is
			// no template's: the whole file is refused.
			if ( self::unsafe_path( $name ) ) {
				$zip->close();
				/* translators: %s: a path inside the zip */
				return new WP_Error( 'unsafe_path', sprintf( __( 'That zip holds a path that leaves its folder (%s), so nothing in it was read.', 'tcgiant-sync' ), $name ) );
			}
			$entries[ $name ] = array( 'index' => $i, 'size' => (int) $stat['size'] );
		}

		$picked = self::pick( array_keys( $entries ) );
		$files  = array();
		foreach ( $picked['files'] as $file => $entry_name ) {
			if ( $entries[ $entry_name ]['size'] > self::MAX_FILE_BYTES ) {
				$zip->close();
				/* translators: %s: a file name */
				return new WP_Error( 'file_too_large', sprintf( __( '%s in that zip is larger than 512 KB, so it is not a template file.', 'tcgiant-sync' ), $file ) );
			}
			$text = $zip->getFromIndex( $entries[ $entry_name ]['index'], self::MAX_FILE_BYTES + 1 );
			if ( false === $text || strlen( $text ) > self::MAX_FILE_BYTES ) {
				$zip->close();
				/* translators: %s: a file name */
				return new WP_Error( 'file_too_large', sprintf( __( '%s in that zip could not be read, or is larger than 512 KB.', 'tcgiant-sync' ), $file ) );
			}
			$files[ $file ] = (string) $text;
		}
		$zip->close();

		if ( ! isset( $files['template.html'] ) ) {
			return new WP_Error( 'no_template', __( 'That zip has no template.html at the top of its folder, so it is not a WP-Lister template.', 'tcgiant-sync' ) );
		}

		return array(
			'files'   => $files,
			'folder'  => $picked['folder'],
			'php'     => $picked['php'],
			'ignored' => $picked['ignored'],
		);
	}

	/**
	 * What to say of a zip too large to read: most likely its images. The
	 * choice of a template on this site is named only where there is one.
	 *
	 * @return string
	 */
	public static function too_large_message() {
		// The smaller of ours and what this site's PHP takes in an upload.
		$limit = function_exists( 'wp_max_upload_size' ) && (int) wp_max_upload_size() > 0 ? min( self::MAX_ZIP_BYTES, (int) wp_max_upload_size() ) : self::MAX_ZIP_BYTES;
		$size  = function_exists( 'size_format' ) ? (string) size_format( $limit ) : round( $limit / 1048576, 1 ) . ' MB';
		return self::installed()
			/* translators: %s: a size, such as 2 MB */
			? sprintf( __( 'That zip is larger than %s, most likely for the template\'s images. Convert it from "One of the WP-Lister templates on this site", or paste its template.html and style.css instead.', 'tcgiant-sync' ), $size )
			/* translators: %s: a size, such as 2 MB */
			: sprintf( __( 'That zip is larger than %s, most likely for the template\'s images. Paste its template.html and style.css instead.', 'tcgiant-sync' ), $size );
	}

	/**
	 * Whether a path inside a zip is one to refuse.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public static function unsafe_path( $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		return '' === $name
			|| '/' === $name[0]
			|| 1 === preg_match( '#(^|/)\.\.(/|$)#', $name )
			|| 1 === preg_match( '#^[A-Za-z]:#', $name )
			|| false !== strpos( $name, "\0" );
	}

	/**
	 * Which entries are the template's: its files in the first folder (or at
	 * the top, if there is no folder), one level deep and no deeper.
	 *
	 * @param string[] $names Entry names.
	 * @return array{files: array<string, string>, folder: string, php: string[], ignored: string[]}
	 */
	private static function pick( array $names ) {
		$folder = null;
		foreach ( $names as $name ) {
			if ( preg_match( '#^([^/]+)/template\.html$#i', $name, $m ) ) {
				$folder = $m[1];
				break;
			}
		}
		$prefix = null === $folder ? '' : $folder . '/';

		$out = array( 'files' => array(), 'folder' => (string) $folder, 'php' => array(), 'ignored' => array() );
		foreach ( $names as $name ) {
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}
			$inside = '' === $prefix ? $name : ( 0 === strpos( $name, $prefix ) ? substr( $name, strlen( $prefix ) ) : null );
			if ( null === $inside || false !== strpos( $inside, '/' ) ) {
				$out['ignored'][] = $name;
				// Not read, but a PHP file of the template's is named among
				// the ones never run, wherever it is in its folder.
				if ( null !== $inside && '.php' === strtolower( substr( $inside, -4 ) ) ) {
					$out['php'][] = $inside;
				}
				continue;
			}
			$file = strtolower( $inside );
			if ( in_array( $file, self::FILES, true ) ) {
				$out['files'][ $file ] = $name;
			}
			if ( '.php' === substr( $file, -4 ) ) {
				$out['php'][] = $inside;
			} elseif ( ! in_array( $file, self::FILES, true ) ) {
				$out['ignored'][] = $name;
			}
		}
		return $out;
	}

	/**
	 * The WP-Lister templates on this site, if it has WP-Lister's folder.
	 *
	 * @return array<string, string> Folder name => its path.
	 */
	public static function installed() {
		$uploads = wp_upload_dir();
		$base    = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' ) . '/wp-lister/templates';
		$found   = array();
		foreach ( (array) glob( $base . '/*/template.html' ) as $file ) {
			$dir = dirname( (string) $file );
			$found[ basename( $dir ) ] = $dir;
		}
		ksort( $found );
		return $found;
	}

	/**
	 * Read one of this site's WP-Lister templates, by its folder name.
	 *
	 * @param string $name Folder name, as installed() lists it.
	 * @return array|WP_Error As read_zip().
	 */
	public static function read_installed( $name ) {
		$all = self::installed();
		if ( ! isset( $all[ (string) $name ] ) ) {
			return new WP_Error( 'no_template', __( 'That WP-Lister template is not on this site.', 'tcgiant-sync' ) );
		}
		$dir = $all[ (string) $name ];

		$files = array();
		foreach ( self::FILES as $file ) {
			$path = $dir . '/' . $file;
			if ( ! is_file( $path ) ) {
				continue;
			}
			if ( filesize( $path ) > self::MAX_FILE_BYTES ) {
				/* translators: %s: a file name */
				return new WP_Error( 'file_too_large', sprintf( __( '%s is larger than 512 KB, so it is not a template file.', 'tcgiant-sync' ), $file ) );
			}
			$files[ $file ] = (string) file_get_contents( $path );
		}

		$php = array();
		foreach ( (array) glob( $dir . '/*.php' ) as $path ) {
			$php[] = basename( (string) $path );
		}
		foreach ( (array) glob( $dir . '/*/*.php' ) as $path ) {
			$php[] = basename( dirname( (string) $path ) ) . '/' . basename( (string) $path );
		}

		return array(
			'files'   => $files,
			'folder'  => (string) $name,
			'php'     => $php,
			'ignored' => array(),
		);
	}

	/**
	 * HTML and CSS pasted in, for a server whose PHP has no zip extension.
	 *
	 * @param string $html   template.html.
	 * @param string $css    style.css.
	 * @param string $config config.json, if there is one.
	 * @return array As read_zip().
	 */
	public static function from_paste( $html, $css, $config = '' ) {
		$files = array(
			'template.html' => (string) $html,
			'style.css'     => (string) $css,
		);
		if ( '' !== trim( (string) $config ) ) {
			$files['config.json'] = (string) $config;
		}
		return array(
			'files'   => $files,
			'folder'  => '',
			'php'     => array(),
			'ignored' => array(),
		);
	}

	// -------------------------------------------------------------------------
	// Converting
	// -------------------------------------------------------------------------

	/**
	 * Convert what was read.
	 *
	 * @param array $source As read_zip() returns it.
	 * @return array name, html, css, report.
	 */
	public static function convert( array $source ) {
		$files  = (array) ( $source['files'] ?? array() );
		$html   = (string) ( $files['template.html'] ?? '' );
		$css    = (string) ( $files['style.css'] ?? '' );
		$report = array(
			'mapped'      => array(),
			'unsupported' => array(),
			'dropped'     => array(),
			'allowlist'   => array(),
			'attributes'  => array(),
			'fields'      => array(),
			'unresolved'  => array(),
			'skipped'     => array(),
			'suggestions' => array(),
		);

		// What the zip held besides the template: another template's folder,
		// to convert on its own, and the rest left where it is.
		$other = array();
		$files_left = 0;
		foreach ( (array) ( $source['ignored'] ?? array() ) as $entry ) {
			if ( preg_match( '#^([^/]+)/template\.html$#i', (string) $entry, $folder ) ) {
				$other[ $folder[1] ] = true;
			} elseif ( '.php' !== strtolower( substr( (string) $entry, -4 ) ) ) {
				// A PHP file is listed once, below, among those never run.
				$files_left++;
			}
		}
		foreach ( array_keys( $other ) as $folder ) {
			/* translators: %s: a folder name */
			$report['skipped'][] = sprintf( __( 'Another template, in the folder %s: convert it on its own.', 'tcgiant-sync' ), $folder );
		}
		if ( $files_left > 0 ) {
			/* translators: %d: number of files */
			$report['skipped'][] = sprintf( _n( '%d other file (an image or the like), not read: a template here names its pictures by address.', '%d other files (images and the like), not read: a template here names its pictures by address.', $files_left, 'tcgiant-sync' ), $files_left );
		}

		foreach ( (array) ( $source['php'] ?? array() ) as $php ) {
			$report['dropped'][] = sprintf(
				/* translators: %s: a file name */
				__( '%s: PHP, which templates here never run.', 'tcgiant-sync' ),
				$php
			);
		}

		// Custom fields: functions.php's defaults, then config.json's values,
		// for the fields functions.php registers, as WP-Lister applies them.
		// With no functions.php (a pasted template), config.json's own.
		// A functions.php that registers its fields in a loop, or through
		// variables, registers what the pattern cannot read: then config.json
		// is applied whole, and the report says why. A key the template itself
		// uses, as $key or [[key]], is always applied.
		$registered = isset( $files['functions.php'] );
		$fields     = self::field_defaults( (string) ( $files['functions.php'] ?? '' ) );
		$config     = json_decode( (string) ( $files['config.json'] ?? '' ), true );
		$unread     = $registered && ! $fields && false !== strpos( (string) $files['functions.php'], 'wplister_register_custom_fields' );
		if ( $unread && is_array( $config ) && $config ) {
			$report['unresolved'][] = __( 'functions.php registers its custom fields in a way this cannot read (in a loop, or through variables), so every value in config.json was written in.', 'tcgiant-sync' );
		}
		foreach ( is_array( $config ) ? $config : array() as $slug => $value ) {
			if ( ! is_string( $slug ) || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $slug ) || ! is_scalar( $value ) ) {
				continue;
			}
			$used = false !== strpos( $html . $css, '[[' . $slug . ']]' ) || 1 === preg_match( '/\$' . preg_quote( $slug, '/' ) . '(?![A-Za-z0-9_])/', $html . $css );
			if ( $registered && ! $unread && ! $used && ! isset( $fields[ $slug ] ) ) {
				/* translators: %s: a field name */
				$report['unresolved'][] = sprintf( __( 'config.json sets "%s", which functions.php does not register, so WP-Lister never used it either. Left out.', 'tcgiant-sync' ), $slug );
				continue;
			}
			$fields[ $slug ] = array( 'value' => (string) $value, 'from' => 'config.json' );
		}
		// Longest first, so $title never eats the start of $title_color.
		uksort( $fields, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		foreach ( $fields as $slug => $field ) {
			$value = self::field_value( $field['value'] );
			$html  = str_replace( '[[' . $slug . ']]', $value, $html );
			$css   = str_replace( '[[' . $slug . ']]', $value, $css );
			$css   = (string) preg_replace( '/\$' . preg_quote( $slug, '/' ) . '(?![A-Za-z0-9_])/', addcslashes( $value, '\\$' ), $css );
			$html  = (string) preg_replace( '/\$' . preg_quote( $slug, '/' ) . '(?![A-Za-z0-9_])/', addcslashes( $value, '\\$' ), $html );
			$report['fields'][ $slug ] = $value . ' (' . $field['from'] . ')';
		}

		$html = self::placeholders( $html, $report );

		// What WP-Lister would run that a template here does not.
		if ( preg_match_all( '#<iframe\b#i', $html, $m ) ) {
			$report['dropped'][] = __( 'iframes: eBay counts them as active content, so they were taken out of the draft.', 'tcgiant-sync' );
			$html = (string) preg_replace( array( '#<iframe\b[^>]*>.*?</iframe\s*>#is', '#</?iframe\b[^>]*>#i' ), '', $html );
		}
		if ( preg_match_all( '#<script\b#i', $html . $css ) ) {
			$report['dropped'][] = __( 'scripts: eBay does not allow JavaScript, so they were taken out of the draft.', 'tcgiant-sync' );
			$html = (string) preg_replace( array( '#<script\b[^>]*>.*?</script\s*>#is', '#</?script\b[^>]*>#i' ), '', $html );
			$css  = (string) preg_replace( array( '#<script\b[^>]*>.*?</script\s*>#is', '#</?script\b[^>]*>#i' ), '', $css );
		}
		// A shortcode this site has: its tags go and any text inside them
		// stays. Any other bracketed text - "[NM]", "[same day]" - is text,
		// and stays as it is.
		if ( function_exists( 'shortcode_exists' ) && preg_match_all( '/(?<!\[)\[([a-z][a-z0-9_\-]*)(?:\s[^\]]*)?\](?!\])/i', $html, $short ) ) {
			foreach ( array_unique( $short[1] ) as $code ) {
				if ( ! shortcode_exists( $code ) ) {
					continue;
				}
				/* translators: %s: a shortcode */
				$report['dropped'][] = sprintf( __( '[%s]: a WordPress shortcode, not run in templates here, so its tags were taken out; any text inside them is kept.', 'tcgiant-sync' ), $code );
				$html = (string) preg_replace( '/(?<!\[)\[\/?' . preg_quote( $code, '/' ) . '(?:\s[^\]]*)?\](?!\])/i', '', $html );
			}
		}

		// What nothing fills: a $field no default or config.json value covers,
		// and a [[placeholder]] left over. Listed, so the seller sees them.
		// In the CSS or an attribute's value: "$AUD 12" in the text is a price.
		// A <style> block in the HTML is CSS too.
		preg_match_all( '/=\s*"[^"]*"|=\s*\'[^\']*\'/', $html, $attribute_values );
		preg_match_all( '#<style\b[^>]*>(.*?)</style\s*>#is', $html, $style_blocks );
		if ( preg_match_all( '/\$([A-Za-z_][A-Za-z0-9_\-]*)/', $css . "\n" . implode( "\n", $style_blocks[1] ) . "\n" . implode( "\n", $attribute_values[0] ), $vars ) ) {
			foreach ( array_unique( $vars[1] ) as $var ) {
				/* translators: %s: a variable */
				$report['unresolved'][] = sprintf( __( '$%s: a WP-Lister custom field with no value here, left in as it is. Set it in the stylesheet or the HTML.', 'tcgiant-sync' ), $var );
			}
		}
		if ( preg_match_all( '/\[\[[^\n]*?\]\]/', $css . "\n" . $html, $left ) ) {
			foreach ( array_unique( $left[0] ) as $placeholder ) {
				/* translators: %s: a placeholder */
				$report['unresolved'][] = sprintf( __( '%s: left as it is; buyers would see it as text.', 'tcgiant-sync' ), $placeholder );
			}
		}

		// After those the placeholders added: the options table, a private meta key.
		$report['suggestions'] = array_merge( $report['suggestions'], self::suggestions( $html ) );

		return array(
			'name'   => self::name( $css, (string) ( $source['folder'] ?? '' ) ),
			'html'   => trim( $html ) . "\n",
			'css'    => trim( $css ) . "\n",
			'report' => $report,
		);
	}

	/**
	 * Convert, and save the result as a draft through the editor's save path.
	 *
	 * @param array $source  As read_zip() returns it.
	 * @param int   $user_id Who converted it.
	 * @return array|WP_Error id, report.
	 */
	public static function save_draft( array $source, $user_id = 0 ) {
		$converted = self::convert( $source );
		$id        = TCGiant_Sync_Templates::save( 0, array(
			'name'            => $converted['name'],
			'status'          => 'draft',
			'html'            => $converted['html'],
			'css'             => $converted['css'],
			'lang'            => TCGiant_Sync_Templates::marketplace_lang(),
			'content_filters' => 'core',
		), $user_id );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array(
			'id'     => (int) $id,
			'report' => $converted['report'],
		);
	}

	/**
	 * The defaults functions.php registers for its custom fields, read as
	 * text by pattern: wplister_register_custom_fields( 'color',
	 * 'section_head_color', '#FFFFFF', ... ). Section titles have none.
	 *
	 * @param string $php functions.php, as text. Never run.
	 * @return array<string, array{value: string, from: string}>
	 */
	public static function field_defaults( $php ) {
		// Comments first, so a call left commented out is not read as one.
		// token_get_all() only splits the text into PHP's tokens; it runs
		// nothing.
		$text = '';
		if ( function_exists( 'token_get_all' ) ) {
			foreach ( token_get_all( (string) $php ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$text .= is_array( $token ) ? $token[1] : $token;
			}
			$php = $text;
		}

		$fields = array();
		// A default may be wrapped for translation: __( 'Text', 'domain' ).
		if ( preg_match_all( '/wplister_register_custom_fields\s*\(\s*([\'"])([a-z_]+)\1\s*,\s*([\'"])([A-Za-z0-9_\-]+)\3\s*,\s*(?:(?:esc_html|esc_attr)?_[_e]\s*\(\s*)?([\'"])(.*?)(?<!\\\\)\5/s', (string) $php, $calls, PREG_SET_ORDER ) ) {
			foreach ( $calls as $call ) {
				if ( 'title' === $call[2] ) {
					continue;
				}
				$fields[ $call[4] ] = array( 'value' => stripslashes( $call[6] ), 'from' => 'functions.php' );
			}
		}
		return $fields;
	}

	/**
	 * A field's value as it may go into the HTML and CSS: text, no markup.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function field_value( $value ) {
		// "{" and "}" go, so a value cannot close a rule; ";" stays, for an
		// entity such as &amp;.
		return trim( str_replace( array( '<', '>', '{', '}' ), '', wp_strip_all_tags( (string) $value ) ) );
	}

	/**
	 * Map WP-Lister's [[...]] placeholders to ours, and say what became of each.
	 *
	 * @param string $html   template.html.
	 * @param array  $report Filled in.
	 * @return string
	 */
	private static function placeholders( $html, array &$report ) {
		$labels = self::attribute_labels();
		$allow  = array_flip( (array) TCGiant_Sync_Templates::settings()['meta_allowlist'] );

		// Any characters inside the brackets, as WP-Lister matches
		// [[attribute_(.*)]]: a label such as "Card #", "Grade:" or "Größe".
		$pattern = 1 === preg_match( '//u', (string) $html ) ? '/\[\[([^\[\]]+?)\]\]/u' : '/\[\[([^\[\]]+?)\]\]/';
		$out     = preg_replace_callback( $pattern, function ( $m ) use ( &$report, $labels, $allow ) {
			$name = trim( $m[1] );

			if ( isset( self::SIMPLE[ $name ] ) ) {
				$report['mapped'][ $m[0] ] = self::SIMPLE[ $name ];
				if ( 'product_variations' === $name ) {
					$report['suggestions'][] = __( '[[product_variations]] became the options table, which lists each option and leaves the prices out: eBay shows those itself.', 'tcgiant-sync' );
				}
				return self::SIMPLE[ $name ];
			}

			if ( preg_match( '/^img_(url_)?(\d+)$/', $name, $img ) ) {
				$n = (int) $img[2];
				if ( $n < 1 || $n > TCGiant_Sync_Templates::MAX_IMAGE_N ) {
					/* translators: 1: placeholder, 2: highest image number */
					$report['dropped'][] = sprintf( __( '%1$s: images after the %2$dth are not available here.', 'tcgiant-sync' ), $m[0], TCGiant_Sync_Templates::MAX_IMAGE_N );
					return '';
				}
				$ours = '' !== $img[1]
					? '{{image.' . $n . '}}'
					: '{{#image.' . $n . '}}<img src="{{image.' . $n . '}}" alt="" style="max-width:100%;height:auto">{{/image.' . $n . '}}';
				$report['mapped'][ $m[0] ] = $ours;
				return $ours;
			}

			if ( 0 === strpos( $name, 'attribute_' ) ) {
				$label = substr( $name, 10 );
				$key   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
				if ( isset( $labels[ $key ] ) ) {
					$ours = '{{attr.' . $labels[ $key ] . '}}';
				} else {
					$ours                   = '{{attr.' . sanitize_title( $label ) . '}}';
					$report['attributes'][] = $label;
				}
				$report['mapped'][ $m[0] ] = $ours;
				return $ours;
			}

			if ( 0 === strpos( $name, 'meta_' ) ) {
				$key = substr( $name, 5 );
				if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $key ) ) {
					/* translators: %s: placeholder */
					$report['unsupported'][] = sprintf( __( '%s: not a meta key this can read.', 'tcgiant-sync' ), $m[0] );
					return '';
				}
				if ( ! isset( $allow[ $key ] ) ) {
					$report['allowlist'][] = $key;
					if ( '_' === $key[0] ) {
						/* translators: %s: a meta key */
						$report['suggestions'][] = sprintf( __( '%s starts with "_": WordPress and plugins keep private figures, such as the cost of goods, under keys like that. Allow it only if it is meant for buyers.', 'tcgiant-sync' ), $key );
					}
				}
				$report['mapped'][ $m[0] ] = '{{meta.' . $key . '}}';
				return '{{meta.' . $key . '}}';
			}

			if ( in_array( $name, self::WIDGETS, true ) ) {
				/* translators: %s: placeholder */
				$report['dropped'][] = sprintf( __( '%s: a widget that writes an iframe with JavaScript, which eBay does not allow.', 'tcgiant-sync' ), $m[0] );
				return '';
			}

			foreach ( self::UNSUPPORTED as $why => $names ) {
				if ( in_array( $name, $names, true ) ) {
					if ( 'price' === $why ) {
						/* translators: %s: placeholder */
						$report['unsupported'][] = sprintf( __( '%s: eBay shows the price itself, beside the description, so a template here does not repeat it.', 'tcgiant-sync' ), $m[0] );
					} elseif ( 'listing' === $why ) {
						/* translators: %s: placeholder */
						$report['unsupported'][] = sprintf( __( '%s: WP-Lister\'s own record of the listing, which a template here does not have.', 'tcgiant-sync' ), $m[0] );
					} else {
						/* translators: %s: placeholder */
						$report['unsupported'][] = sprintf( __( '%s: not available to templates here; the item specifics table carries what the listing sends.', 'tcgiant-sync' ), $m[0] );
					}
					return '';
				}
			}

			// Anything else: a placeholder a functions.php filter added, most
			// likely, whose PHP is not run here.
			/* translators: %s: placeholder */
			$report['unsupported'][] = sprintf( __( '%s: not one of WP-Lister\'s own; it may come from the template\'s functions.php, which is not run here.', 'tcgiant-sync' ), $m[0] );
			return '';
		}, (string) $html );

		return null === $out ? (string) $html : (string) $out;
	}

	/**
	 * The shop's global attributes, by their label, as {{attr.x}} keys them:
	 * without pa_, the name a custom attribute of the same name has too, as
	 * the editor's Insert list gives it.
	 *
	 * @return array<string, string> Lower-case label => attribute name.
	 */
	private static function attribute_labels() {
		$out = array();
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( (array) wc_get_attribute_taxonomies() as $attr ) {
				$label         = (string) $attr->attribute_label;
				$out[ function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label ) ] = (string) $attr->attribute_name;
			}
		}
		return $out;
	}

	/**
	 * What to look at once it is converted: typed-in policy sections, and
	 * filler.
	 *
	 * @param string $html Converted HTML.
	 * @return string[]
	 */
	private static function suggestions( $html ) {
		$out = array();
		// The headings of a typed-in policy section, in the languages of the
		// marketplaces: returns, shipping, payment.
		$kinds = array(
			'returns'  => '/^(?:returns?(?: policy)?|r(?:ü|ue)ckgabe|r(?:ü|ue)cknahme|widerruf|retours?|resi|reso|devoluci(?:ó|o)n(?:es)?)$/iu',
			'shipping' => '/^(?:shipping|delivery|postage|versand|lieferung|livraison|exp(?:é|e)dition|spedizione|env(?:í|i)os?)$/iu',
			'payment'  => '/^(?:payments?(?: methods)?|zahlung(?:en)?|zahlungsarten|paiements?|pagamento|pagos?)$/iu',
		);
		if ( preg_match_all( '#<(h[1-6]|strong|b)\b[^>]*>\s*([^<]{2,40}?)\s*:?\s*</\1>#iu', $html, $heads ) ) {
			$english = 'en' === TCGiant_Sync_Templates::marketplace_lang();
			$seen    = array();
			foreach ( $heads[2] as $head ) {
				$head = trim( $head );
				$kind = '';
				foreach ( $kinds as $name => $pattern ) {
					if ( preg_match( $pattern, $head ) ) {
						$kind = $name;
						break;
					}
				}
				$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $head, 'UTF-8' ) : strtolower( $head );
				if ( '' === $kind || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				if ( 'payment' === $kind ) {
					/* translators: %s: a heading */
					$out[] = sprintf( __( 'The section headed "%s" lists payment methods typed into the template. eBay shows the ones its checkout offers itself; consider taking the section out.', 'tcgiant-sync' ), $head );
					continue;
				}
				/* translators: 1: a heading, 2: a placeholder */
				$say = sprintf( __( 'The section headed "%1$s" is text typed into the template, which can drift from the policy eBay shows. Consider replacing it with %2$s, which is written from your Business Policy.', 'tcgiant-sync' ), $head, 'returns' === $kind ? '{{{policy.returns}}}' : '{{{policy.handling}}}' );
				if ( ! $english ) {
					$say .= ' ' . __( '(Its wording is English only in this version.)', 'tcgiant-sync' );
				}
				$out[] = $say;
			}
		}
		if ( preg_match_all( '/replace this text|your logo should go here|placehold\.it|lorem ipsum/i', $html, $filler ) ) {
			/* translators: %s: the text found */
			$out[] = sprintf( __( 'The template still holds placeholder text (%s). Replace or remove it before you publish.', 'tcgiant-sync' ), '"' . implode( '", "', array_unique( array_map( 'strtolower', $filler[0] ) ) ) . '"' );
		}
		return $out;
	}

	/**
	 * A name for the draft: the stylesheet's "Template:" line, or the folder's.
	 *
	 * @param string $css    style.css.
	 * @param string $folder Folder name.
	 * @return string
	 */
	private static function name( $css, $folder ) {
		$name = preg_match( '/^\s*Template:\s*(.+)$/mi', (string) $css, $m ) ? trim( $m[1] ) : '';
		if ( '' === $name ) {
			$name = '' !== $folder ? $folder : __( 'WP-Lister template', 'tcgiant-sync' );
		}
		/* translators: %s: template name */
		return sprintf( __( '%s (from WP-Lister)', 'tcgiant-sync' ), sanitize_text_field( $name ) );
	}

	// -------------------------------------------------------------------------
	// WP-Lister on this site
	// -------------------------------------------------------------------------

	/**
	 * Whether WP-Lister for eBay, Lite or Pro, is active here. Both define the
	 * same constants, from one code base.
	 *
	 * @return bool
	 */
	public static function wplister_active() {
		return defined( 'WPLE_PLUGIN_VERSION' ) || defined( 'WPLISTER_VERSION' ) || class_exists( 'WPL_WPLister', false );
	}

	/**
	 * Whether WP-Lister still creates orders or syncs stock: its options
	 * wplister_create_orders and wplister_handle_stock, as WP-Lister 3.8.8
	 * reads them (EbayOrdersModel: orders are made unless create_orders is
	 * "0" or unset; stock is changed only when handle_stock is "1").
	 *
	 * @return bool
	 */
	public static function wplister_doubles_up() {
		$orders = get_option( 'wplister_create_orders', false );
		return ! in_array( $orders, array( false, '0', 0 ), true ) || '1' === (string) get_option( 'wplister_handle_stock', '' );
	}
}
