<?php
/**
 * The one translation path. The REST route calls translate() and nothing
 * calls TBTD_API::translate() directly, so a quota or validation fix cannot
 * land in one place and miss another.
 *
 * Every limit is enforced here, server side, before the API call. The teacher
 * page pre-checks the sentence count for UX only and is never the gate.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Translator {

	/** Sentences allowed in one translation request, out of the box. */
	const DEFAULT_MAX_SENTENCES = 30;

	/** Ceiling on the configurable per-request cap, to protect the token budget. */
	const HARD_MAX_SENTENCES = 60;

	/** Translation requests per user per day, out of the box. 0 means unlimited. */
	const DEFAULT_MAX_REQUESTS = 40;

	/** Prefix for the per-day counter user meta key. */
	const COUNT_META_PREFIX = 'tbtd_translate_count_';

	/** User meta that overrides the global daily limit for one user. */
	const LIMIT_META = 'tbtd_max_translations_per_day';

	/**
	 * Configured maximum sentences per request.
	 *
	 * @return int
	 */
	public static function max_sentences() {
		$max = (int) get_option( 'tbtd_max_sentences', self::DEFAULT_MAX_SENTENCES );
		if ( $max < 1 ) {
			$max = self::DEFAULT_MAX_SENTENCES;
		}
		return min( $max, self::HARD_MAX_SENTENCES );
	}

	/**
	 * The site-wide daily limit. 0 means unlimited.
	 *
	 * @return int
	 */
	public static function default_max_requests() {
		return max( 0, (int) get_option( 'tbtd_max_translations_per_day', self::DEFAULT_MAX_REQUESTS ) );
	}

	/**
	 * The daily limit for one user. A numeric per-user meta value wins.
	 *
	 * @param int $user_id User ID.
	 * @return int 0 means unlimited.
	 */
	public static function max_requests( $user_id ) {
		$override = get_user_meta( (int) $user_id, self::LIMIT_META, true );
		if ( '' !== $override && is_numeric( $override ) ) {
			return max( 0, (int) $override );
		}
		return self::default_max_requests();
	}

	/**
	 * Today's counter key, in site time so a teacher's day is their own.
	 *
	 * @return string
	 */
	private static function count_meta_key() {
		return self::COUNT_META_PREFIX . current_time( 'Y-m-d' );
	}

	/**
	 * Requests this user has left today. Null means unlimited.
	 *
	 * @param int $user_id User ID.
	 * @return int|null
	 */
	public static function remaining( $user_id ) {
		$limit = self::max_requests( $user_id );
		if ( 0 === $limit ) {
			return null;
		}
		$used = (int) get_user_meta( (int) $user_id, self::count_meta_key(), true );
		return max( 0, $limit - $used );
	}

	/**
	 * Translate sentences for one teacher.
	 *
	 * Order: capability, then input, then quota, then the API call. The counter
	 * moves only after a successful answer — a failed call costs no quota.
	 *
	 * @param array  $sentences Raw English markup strings.
	 * @param string $context   Raw context line.
	 * @param int    $user_id   Requesting user.
	 * @return array|WP_Error array( 'items' => [...], 'remaining' => int|null )
	 */
	public static function translate( array $sentences, $context, $user_id ) {
		if ( ! TBTD_Capabilities::user_can_manage( $user_id ) ) {
			return new WP_Error( 'tbtd_forbidden', __( 'You are not allowed to do that.', 'translation-drill' ), array( 'status' => 403 ) );
		}

		$en = array();
		foreach ( $sentences as $sentence ) {
			$clean = TBTD_Markup::sanitize( is_string( $sentence ) ? $sentence : '' );
			if ( '' === TBTD_Markup::plain( $clean ) ) {
				return new WP_Error( 'tbtd_empty_sentence', __( 'One of the sentences is empty. Write it or remove it first.', 'translation-drill' ), array( 'status' => 400 ) );
			}
			$en[] = $clean;
		}

		if ( empty( $en ) ) {
			return new WP_Error( 'tbtd_no_sentences', __( 'Add at least one sentence.', 'translation-drill' ), array( 'status' => 400 ) );
		}

		$max = self::max_sentences();
		if ( count( $en ) > $max ) {
			return new WP_Error(
				'tbtd_too_many',
				sprintf(
					/* translators: %d: maximum sentences per request */
					__( 'Too many sentences at once — the maximum is %d. Translate them in smaller groups.', 'translation-drill' ),
					$max
				),
				array( 'status' => 400 )
			);
		}

		$remaining = self::remaining( $user_id );
		if ( null !== $remaining && $remaining <= 0 ) {
			return new WP_Error( 'tbtd_quota', __( 'You\'ve used today\'s translation limit. Try again tomorrow.', 'translation-drill' ), array( 'status' => 429 ) );
		}

		$context = mb_substr( sanitize_text_field( (string) $context ), 0, 500 );
		$result  = TBTD_API::translate( $en, $context );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}

		$key = self::count_meta_key();
		update_user_meta( (int) $user_id, $key, (int) get_user_meta( (int) $user_id, $key, true ) + 1 );

		return array(
			'items'     => $result,
			'remaining' => self::remaining( $user_id ),
		);
	}
}
