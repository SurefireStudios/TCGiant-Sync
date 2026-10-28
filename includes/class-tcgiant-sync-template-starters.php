<?php
/**
 * The five starter templates.
 *
 * Installed once, as drafts, with nothing assigned, so that no shop's
 * listings change until it chooses one. Each is built on the design-settings
 * form - colours, logo, banner, the parts shown - so a seller who never opens
 * the HTML still gets a finished look.
 *
 * Every starter:
 *
 * - keeps its layout in inline styles, so that if eBay drops the stylesheet
 *   (live test 2) the description still reads, one column, top to bottom; the
 *   stylesheet only adds polish;
 * - has no JavaScript and no fixed width over 700 px, and reads at 375 px;
 * - leaves out a part with no data - no grading panel for an ungraded card, no
 *   returns block without a return policy - rather than printing filler;
 * - uses only inline properties WordPress's style filter keeps (no display,
 *   which it removes).
 *
 * Their fixed headings ("Item specifics", "Returns" and so on) are written in
 * the language the plugin is translated into when they are installed:
 * English unless there is a translation.
 *
 * Our own designs: nothing here comes from another plugin's templates.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Template_Starters class.
 */
class TCGiant_Sync_Template_Starters {

	/**
	 * Bumped when a starter is added, so that shops get the new one once.
	 */
	const VERSION = 1;

	/**
	 * The starters, each as TCGiant_Sync_Templates::save() takes it, with a
	 * summary for the list.
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		$parts = self::parts();

		return array(
			'clean'   => array(
				'name'    => __( 'Clean', 'tcgiant-sync' ),
				'summary' => __( 'One column: header, title, grading line, description, item specifics, returns and handling, and a link to your eBay Store.', 'tcgiant-sync' ),
				'html'    => self::page( $parts['header'] . $parts['title'] . $parts['grading_line'] . $parts['description'] . $parts['specifics'] . $parts['options'] . $parts['policies'] . $parts['gallery'] . $parts['store'] ),
				'css'     => self::polish(),
				'design'  => array( 'accent' => '#1f4e79', 'accent_text' => '#ffffff', 'background' => '#ffffff' ),
			),
			'cards'   => array(
				'name'    => __( 'Trading cards', 'tcgiant-sync' ),
				'summary' => __( 'Clean, with a grading panel for slabs - grader, grade and certificate number - and the card\'s condition.', 'tcgiant-sync' ),
				'html'    => self::page( $parts['header'] . $parts['title'] . $parts['condition'] . $parts['card_panel'] . $parts['description'] . $parts['specifics'] . $parts['options'] . $parts['policies'] . $parts['gallery'] . $parts['store'] ),
				'css'     => self::polish(),
				'design'  => array( 'accent' => '#b3261e', 'accent_text' => '#ffffff', 'background' => '#ffffff' ),
			),
			'coins'   => array(
				'name'    => __( 'Coins', 'tcgiant-sync' ),
				'summary' => __( 'A grade and certification panel for graded coins; metal, year and mint come through the item specifics.', 'tcgiant-sync' ),
				'html'    => self::page( $parts['header'] . $parts['title'] . $parts['coin_panel'] . $parts['description'] . $parts['specifics'] . $parts['policies'] . $parts['gallery'] . $parts['store'] ),
				'css'     => self::polish(),
				'design'  => array( 'accent' => '#7a5c1e', 'accent_text' => '#ffffff', 'background' => '#fffdf8' ),
			),
			'general' => array(
				'name'    => __( 'Sealed or general', 'tcgiant-sync' ),
				'summary' => __( 'For sealed product and everything else: the short description leads, then the item specifics, then the full description. No grading panel.', 'tcgiant-sync' ),
				'html'    => self::page( $parts['header'] . $parts['title'] . $parts['lead'] . $parts['specifics'] . $parts['options'] . $parts['description'] . $parts['policies'] . $parts['gallery'] . $parts['store'] ),
				'css'     => self::polish(),
				'design'  => array( 'accent' => '#22543d', 'accent_text' => '#ffffff', 'background' => '#ffffff' ),
			),
			'dark'    => array(
				'name'    => __( 'Dark', 'tcgiant-sync' ),
				'summary' => __( 'Clean on a dark page. Change the colours on the design form; the text colour follows the background.', 'tcgiant-sync' ),
				'html'    => self::page( $parts['header'] . $parts['title'] . $parts['grading_line'] . $parts['description'] . $parts['specifics'] . $parts['options'] . $parts['policies'] . $parts['gallery'] . $parts['store'] ),
				'css'     => self::polish(),
				'design'  => array( 'accent' => '#d4a63a', 'accent_text' => '#141414', 'background' => '#141414' ),
			),
		);
	}

	/**
	 * One starter, ready for TCGiant_Sync_Templates::save(), or null.
	 *
	 * @param string $slug Starter.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		if ( ! isset( $all[ $slug ] ) ) {
			return null;
		}
		$starter = $all[ $slug ];
		return array(
			'name'            => $starter['name'],
			'html'            => $starter['html'],
			'css'             => $starter['css'],
			// The language its headings were written in when installed: English
			// unless the plugin is translated (see the class comment). Not the
			// marketplace's, which the headings need not match.
			'lang'            => 'en',
			'content_filters' => 'core',
			'design'          => $starter['design'],
		);
	}

	/**
	 * Install each starter once, as a draft, assigning nothing. A starter the
	 * shop deleted is not brought back.
	 *
	 * Admin requests only, like the plugin's other one-off migrations.
	 */
	public static function maybe_install() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$done = get_option( TCGiant_Sync_Templates::STARTERS_OPTION, array() );
		$done = is_array( $done ) ? $done : array();
		if ( (int) ( $done['version'] ?? 0 ) >= self::VERSION ) {
			return;
		}

		$installed = is_array( $done['installed'] ?? null ) ? $done['installed'] : array();
		foreach ( array_keys( self::all() ) as $slug ) {
			if ( isset( $installed[ $slug ] ) ) {
				continue;
			}
			$id = TCGiant_Sync_Templates::save( 0, array_merge( (array) self::get( $slug ), array( 'status' => 'draft' ) ), 0 );
			if ( ! is_wp_error( $id ) ) {
				$installed[ $slug ] = (int) $id;
			}
		}

		update_option( TCGiant_Sync_Templates::STARTERS_OPTION, array(
			'version'   => self::VERSION,
			'installed' => $installed,
		), false );
	}

	/**
	 * The page every starter is built in: one column, up to 700 px, centred.
	 *
	 * @param string $inner The parts.
	 * @return string
	 */
	private static function page( $inner ) {
		return '<div class="tcg-page" style="max-width:700px;margin:0 auto;padding:0 0 18px;background:{{design.background}};color:{{design.text}};font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.55;text-align:left">' . "\n"
			. $inner
			. '</div>' . "\n";
	}

	/**
	 * The parts the starters are made of.
	 *
	 * @return array<string, string>
	 */
	private static function parts() {
		$h2    = 'margin:22px 16px 8px;font-size:19px;line-height:1.3;color:{{design.text}};border-bottom:2px solid {{design.accent}};padding:0 0 4px';
		$inset = 'margin:0 16px';
		$bar   = '<div class="tcg-header" style="background:{{design.accent}};color:{{design.accent_text}};padding:14px 16px">';
		// A heading, in the language the plugin is translated into.
		$say = function ( $text ) {
			return esc_html( $text );
		};

		return array(
			// The accent bar only with something in it: a logo, else the
			// shop's name. A shop with neither gets no empty bar.
			'header'       => '{{#show.header}}'
				. '{{#design.logo_url}}' . $bar . '<img src="{{design.logo_url}}" alt="{{shop_name}}" style="max-height:60px;width:auto;margin:0"></div>{{/design.logo_url}}'
				. '{{^design.logo_url}}{{#shop_name}}' . $bar . '<div style="font-size:20px;font-weight:bold;letter-spacing:1px">{{shop_name}}</div></div>{{/shop_name}}{{/design.logo_url}}'
				. '{{#design.banner_url}}<img src="{{design.banner_url}}" alt="" style="width:100%;height:auto;margin:0">{{/design.banner_url}}{{/show.header}}' . "\n",

			'title'        => '<h1 class="tcg-title" style="margin:18px 16px 8px;font-size:24px;line-height:1.25;color:{{design.text}}">{{title}}</h1>' . "\n",

			'condition'    => '{{#condition_name}}<p class="tcg-condition" style="' . $inset . ' 12px;font-size:15px;color:{{design.muted}}">' . $say( __( 'Condition:', 'tcgiant-sync' ) ) . ' <strong style="color:{{design.text}}">{{condition_name}}</strong></p>{{/condition_name}}' . "\n",

			'grading_line' => '{{#show.grading}}{{#grading}}<p class="tcg-grading" style="' . $inset . ' 12px;font-weight:bold;color:{{design.accent}}">{{grader}}{{#grade}} {{grade}}{{/grade}}{{#cert}} &middot; ' . $say( __( 'Cert.', 'tcgiant-sync' ) ) . ' {{cert}}{{/cert}}</p>{{/grading}}{{/show.grading}}' . "\n",

			'card_panel'   => '{{#show.grading}}{{#grading}}<table class="tcg-panel" style="width:auto;margin:4px 16px 18px;border-collapse:collapse;background:{{design.panel}};border:2px solid {{design.accent}}"><tbody><tr>'
				. '{{#grade}}<td style="padding:10px 18px;text-align:center;vertical-align:middle;background:{{design.accent}};color:{{design.accent_text}}"><div style="font-size:12px;letter-spacing:1px">' . $say( __( 'GRADE', 'tcgiant-sync' ) ) . '</div><div style="font-size:30px;font-weight:bold;line-height:1.1">{{grade}}</div></td>{{/grade}}'
				. '<td style="padding:10px 14px;vertical-align:middle">{{#grader}}<div style="font-size:15px;font-weight:bold">{{grader}}</div>{{/grader}}{{#cert}}<div style="font-size:14px;color:{{design.muted}}">' . $say( __( 'Cert. no.', 'tcgiant-sync' ) ) . ' {{cert}}</div>{{/cert}}</td>'
				. '</tr></tbody></table>{{/grading}}{{/show.grading}}' . "\n",

			'coin_panel'   => '{{#show.grading}}{{#grading}}<div class="tcg-panel" style="margin:4px 16px 18px;padding:12px 14px;background:{{design.panel}};border-left:5px solid {{design.accent}}">'
				. '{{#grade}}<div style="font-size:26px;font-weight:bold;line-height:1.2;color:{{design.accent}}">{{grade}}</div>{{/grade}}'
				. '{{#grader}}<div style="font-size:15px">' . $say( __( 'Graded by', 'tcgiant-sync' ) ) . ' <strong>{{grader}}</strong></div>{{/grader}}'
				. '{{#cert}}<div style="font-size:14px;color:{{design.muted}}">' . $say( __( 'Certification no.', 'tcgiant-sync' ) ) . ' {{cert}}</div>{{/cert}}'
				. '</div>{{/grading}}{{/show.grading}}' . "\n",

			'lead'         => '{{#show.short_description}}{{#short_description}}<div class="tcg-lead" style="' . $inset . ' 8px;font-size:18px;line-height:1.5">{{{short_description}}}</div>{{/short_description}}{{/show.short_description}}' . "\n",

			'description'  => '<div class="tcg-body" style="' . $inset . '">{{{description}}}</div>' . "\n",

			'specifics'    => '{{#show.specifics}}{{#specifics_table}}<h2 style="' . $h2 . '">' . $say( __( 'Item specifics', 'tcgiant-sync' ) ) . '</h2><div style="' . $inset . '">{{{specifics_table}}}</div>{{/specifics_table}}{{/show.specifics}}' . "\n",

			'options'      => '{{#variations_table}}<h2 style="' . $h2 . '">' . $say( __( 'Options', 'tcgiant-sync' ) ) . '</h2><div style="' . $inset . '">{{{variations_table}}}</div>{{/variations_table}}' . "\n",

			'policies'     => '{{#show.returns}}{{#policy.returns}}<h2 style="' . $h2 . '">' . $say( __( 'Returns', 'tcgiant-sync' ) ) . '</h2><div style="' . $inset . '">{{{policy.returns}}}</div>{{/policy.returns}}{{/show.returns}}' . "\n"
				. '{{#show.handling}}{{#policy.handling}}<h2 style="' . $h2 . '">' . $say( __( 'Handling', 'tcgiant-sync' ) ) . '</h2><div style="' . $inset . '">{{{policy.handling}}}</div>{{/policy.handling}}{{/show.handling}}' . "\n",

			'gallery'      => '{{#show.gallery}}{{#gallery}}<h2 style="' . $h2 . '">' . $say( __( 'More photos', 'tcgiant-sync' ) ) . '</h2><div style="' . $inset . '">{{{gallery}}}</div>{{/gallery}}{{/show.gallery}}' . "\n",

			'store'        => '{{#ebay_store_url}}<p class="tcg-store" style="margin:26px 16px 0;text-align:center"><a href="{{ebay_store_url}}" target="_blank" style="background:{{design.accent}};color:{{design.accent_text}};padding:10px 22px;border-radius:4px;text-decoration:none;font-weight:bold">' . $say( __( 'See more in our eBay Store', 'tcgiant-sync' ) ) . '</a></p>{{/ebay_store_url}}' . "\n",
		);
	}

	/**
	 * The stylesheet: polish only. Without it the page reads the same.
	 *
	 * @return string
	 */
	private static function polish() {
		return ".tcg-page .tcg-desc p { margin: 0 0 12px; }\n"
			. ".tcg-page .tcg-desc img { max-width: 100%; height: auto; }\n"
			. ".tcg-page .tcg-specifics tr:nth-child(even) th,\n.tcg-page .tcg-specifics tr:nth-child(even) td { background: {{design.panel}}; }\n"
			. ".tcg-page a { color: {{design.accent}}; }\n"
			. ".tcg-page .tcg-store a { color: {{design.accent_text}}; }\n";
	}
}
