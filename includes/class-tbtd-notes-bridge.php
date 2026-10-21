<?php
/**
 * Contributes published drills to the TBT Notes class panel.
 *
 * Notes exposes a generic extension point — the `tbt_notes_class_extras`
 * filter — and knows nothing about Drill. All the coupling lives here, on
 * Drill's side of the line, modelled on TBT Swipe's bridge: a drill attached
 * to a lesson shows its own icon on that lesson's row.
 *
 * Notes is optional. The filter is Notes' own, so with Notes inactive nothing
 * in this class ever runs. QR codes in the panel come from Swipe's
 * `window.TBTNotesQR` adapter; Drill ships none, and without Swipe Notes
 * renders the plain link.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Notes_Bridge {

	/**
	 * Group slug in the extras contract. Stable: it is what Notes uses to
	 * remember which section a user expanded.
	 */
	const GROUP_KEY = 'translation_drills';

	public function __construct() {
		// Priority 20, so Swipe (10) keeps first place on a lesson row.
		add_filter( 'tbt_notes_class_extras', array( $this, 'add_class_drills' ), 20, 3 );
	}

	/**
	 * Is the host plugin actually there? The filter alone is a good signal (it
	 * only fires from Notes), but a site could apply it elsewhere, so the
	 * pieces this bridge reads through are checked too.
	 *
	 * @return bool
	 */
	protected function notes_available() {
		return class_exists( 'TBT_Notes_REST' ) && TBTD_Classes::available();
	}

	/**
	 * Append this class's published drills to the extras Notes will render.
	 *
	 * Scoping is by class, not by owner: the readers here are the class's
	 * students, so filtering on owner_id would hand every student an empty list.
	 * That is safe without a check of our own because Notes runs this filter
	 * behind can_read_class — by the time we are called, the caller is already
	 * a proven member (or the owner) of the class. A second membership rule here
	 * would only create somewhere for the two to disagree.
	 *
	 * @param array $extras   Groups collected so far.
	 * @param int   $class_id Class being viewed.
	 * @param int   $user_id  Current user (unused: access is already settled).
	 * @return array
	 */
	public function add_class_drills( $extras, $class_id, $user_id = 0 ) {
		if ( ! is_array( $extras ) || ! $this->notes_available() ) {
			return $extras;
		}

		$class_id = (int) $class_id;
		if ( $class_id <= 0 ) {
			return $extras;
		}

		$drills = TBTD_DB::published_drills_for_class( $class_id );
		if ( empty( $drills ) ) {
			return $extras;
		}

		$items = array();
		foreach ( $drills as $drill ) {
			$url = TBTD_Urls::drill_url( $drill->slug );
			if ( '' === $url ) {
				// No student page configured: there is nothing to open or scan.
				continue;
			}

			$n       = (int) $drill->item_count;
			$items[] = array(
				'id'        => (int) $drill->id,
				// 0 for "Any lesson": Notes then lists it under the lessons
				// instead of on a row.
				'lesson_id' => (int) $drill->lesson_id,
				'title'     => (string) $drill->title,
				/* translators: %d: number of sentences in the drill. */
				'subtitle'  => sprintf( _n( '%d sentence', '%d sentences', $n, 'translation-drill' ), $n ),
				'url'       => $url,
			);
		}

		if ( empty( $items ) ) {
			return $extras;
		}

		$extras[] = array(
			'key'    => self::GROUP_KEY,
			'label'  => __( 'Translation drills', 'translation-drill' ),
			// Row tooltip, in Polish because students read it in class. Ignored
			// by Notes before 1.18.1.
			'action' => __( 'Pokaż ćwiczenie', 'translation-drill' ),
			'icon'   => self::icon_svg(),
			'items'  => $items,
		);

		return $extras;
	}

	/**
	 * The Drill mark, inline, for the extras contract.
	 *
	 * Shipped with the plugin rather than linked from uploads: a media-library
	 * URL can be moved or cleaned up, and an uploaded file is one more request
	 * for a 24px mark. Read once per request and cached, so a panel with several
	 * classes does not re-read it.
	 *
	 * Multicolour by design — Notes is told not to recolour it — so it carries
	 * its own fills and does not rely on currentColor.
	 *
	 * @return string SVG markup, or '' if the file cannot be read (Notes then
	 *                falls back to its own glyph).
	 */
	protected static function icon_svg() {
		static $svg = null;

		if ( null === $svg ) {
			$path = TBTD_DIR . 'assets/img/drill-icon.svg';
			$raw  = is_readable( $path ) ? file_get_contents( $path ) : '';
			$svg  = is_string( $raw ) ? trim( $raw ) : '';
		}

		return $svg;
	}
}
