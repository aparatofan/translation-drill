<?php
/**
 * The plugin's two page addresses and the links built from them.
 *
 * One place decides every URL, so the library, the builder, the share box and
 * wp-admin cannot disagree about where a drill lives.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Urls {

	/** Query parameter carrying a drill's slug on the student page. */
	const DRILL_PARAM = 'drill';

	/** Query parameter opening a drill in the builder. */
	const EDIT_PARAM = 'tbtd_edit';

	/** Query parameter opening an empty builder. */
	const NEW_PARAM = 'tbtd_new';

	/**
	 * The student page: the published page holding [tbt_drill], or ''.
	 *
	 * @return string
	 */
	public static function player_page() {
		return self::page_url( (int) get_option( 'tbtd_player_page_id', 0 ) );
	}

	/**
	 * The teacher page: the setting, else the current page, else ''.
	 *
	 * The current-page fallback is what lets an unconfigured site work the
	 * moment the shortcode is placed: the page links back to itself.
	 *
	 * @return string
	 */
	public static function builder_page() {
		$url = self::page_url( (int) get_option( 'tbtd_builder_page_id', 0 ) );
		if ( '' !== $url ) {
			return $url;
		}
		$post = get_post();
		if ( $post && is_singular() ) {
			$permalink = get_permalink( $post );
			return $permalink ? (string) $permalink : '';
		}
		return '';
	}

	/**
	 * A drill's student link, or '' when no student page is configured.
	 *
	 * @param string $slug Drill slug.
	 * @return string
	 */
	public static function drill_url( $slug ) {
		$base = self::player_page();
		if ( '' === $base || '' === (string) $slug ) {
			return '';
		}
		return add_query_arg( self::DRILL_PARAM, rawurlencode( (string) $slug ), $base );
	}

	/**
	 * Link opening one drill in the builder.
	 *
	 * @param int $drill_id Drill ID.
	 * @return string
	 */
	public static function edit_url( $drill_id ) {
		$base = self::builder_page();
		return '' === $base ? '' : add_query_arg( self::EDIT_PARAM, (int) $drill_id, $base );
	}

	/**
	 * Link opening an empty builder.
	 *
	 * @return string
	 */
	public static function new_url() {
		$base = self::builder_page();
		return '' === $base ? '' : add_query_arg( self::NEW_PARAM, '1', $base );
	}

	/**
	 * Link back to the library view of the builder page.
	 *
	 * @return string
	 */
	public static function library_url() {
		$base = self::builder_page();
		return '' === $base ? '' : remove_query_arg( array( self::EDIT_PARAM, self::NEW_PARAM ), $base );
	}

	/**
	 * Permalink of a published page, or ''.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	private static function page_url( $page_id ) {
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return $url ? (string) $url : '';
	}
}
