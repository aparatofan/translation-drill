<?php
/**
 * Authenticated REST routes behind the teacher page.
 *
 * Everything here requires TBTD_CAP and a valid wp_rest nonce (WordPress
 * checks the X-WP-Nonce header itself for cookie authentication). Anything
 * touching an existing drill also requires ownership — administrators
 * included; wp-admin is the cross-user surface.
 *
 * There is deliberately no public read route. Students only ever receive a
 * drill inside the server-rendered student page, after the login check.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Rest {

	const NS = 'translation-drill/v1';

	/** Most sentences one drill may hold. */
	const MAX_ITEMS = 100;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/manage/classes/(?P<class_id>\d+)/lessons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'get_lessons' ),
				'args'                => array(
					'class_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/translate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'translate' ),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/drills',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'save' ),
			)
		);

		register_rest_route(
			self::NS,
			'/manage/drills/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'delete' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function can_manage() {
		return TBTD_Capabilities::user_can_manage();
	}

	/**
	 * Lessons of one of the teacher's own classes.
	 */
	public function get_lessons( WP_REST_Request $request ) {
		$class_id = (int) $request['class_id'];
		if ( ! TBTD_Classes::user_owns_class( get_current_user_id(), $class_id ) ) {
			return new WP_Error( 'tbtd_invalid_class', __( 'You don\'t have access to that class.', 'translation-drill' ), array( 'status' => 403 ) );
		}

		$lessons = array();
		foreach ( TBTD_Classes::lessons_for_class( $class_id ) as $lesson ) {
			$lessons[] = array(
				'id'    => (int) ( $lesson['id'] ?? 0 ),
				'title' => (string) ( $lesson['header'] ?? '' ),
			);
		}
		return rest_ensure_response( $lessons );
	}

	/**
	 * Translate a batch of highlighted English sentences.
	 */
	public function translate( WP_REST_Request $request ) {
		$sentences = $request->get_param( 'sentences' );
		$result    = TBTD_Translator::translate(
			is_array( $sentences ) ? $sentences : array(),
			(string) $request->get_param( 'context' ),
			get_current_user_id()
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Create or update a drill, items included, as a draft or published.
	 */
	public function save( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = absint( $request->get_param( 'id' ) );
		$drill   = null;

		if ( $id > 0 ) {
			$drill = TBTD_DB::get_owned_drill( $id, $user_id );
			if ( ! $drill ) {
				return new WP_Error( 'tbtd_not_found', __( 'That drill no longer exists, or it isn\'t yours.', 'translation-drill' ), array( 'status' => 404 ) );
			}
		}

		$title = mb_substr( sanitize_text_field( (string) $request->get_param( 'title' ) ), 0, 200 );
		if ( '' === $title ) {
			return new WP_Error( 'tbtd_no_title', __( 'Give the drill a title.', 'translation-drill' ), array( 'status' => 400 ) );
		}

		$attachment = TBTD_Classes::validate_attachment(
			$user_id,
			absint( $request->get_param( 'class_id' ) ),
			absint( $request->get_param( 'lesson_id' ) )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$items = self::sanitize_items( $request->get_param( 'items' ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$status = TBTD_DB::STATUS_PUBLISHED === $request->get_param( 'status' )
			? TBTD_DB::STATUS_PUBLISHED
			: TBTD_DB::STATUS_DRAFT;

		if ( TBTD_DB::STATUS_PUBLISHED === $status ) {
			$check = self::check_publishable( $items );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$fields = array(
			'title'     => $title,
			'context'   => mb_substr( sanitize_text_field( (string) $request->get_param( 'context' ) ), 0, 500 ),
			'status'    => $status,
			'class_id'  => $attachment['class_id'],
			'lesson_id' => $attachment['lesson_id'],
		);

		if ( $drill ) {
			TBTD_DB::update_drill( $drill, $fields );
			$drill_id = (int) $drill->id;
		} else {
			$drill_id = TBTD_DB::insert_drill( $user_id, $fields );
			if ( ! $drill_id ) {
				return new WP_Error( 'tbtd_db_error', __( 'The drill could not be saved. Try again.', 'translation-drill' ), array( 'status' => 500 ) );
			}
		}

		TBTD_DB::replace_items( $drill_id, $items );

		$saved = TBTD_DB::get_drill( $drill_id );
		return rest_ensure_response( self::drill_payload( $saved, TBTD_DB::get_items( $drill_id ) ) );
	}

	/**
	 * Delete one of the teacher's own drills.
	 */
	public function delete( WP_REST_Request $request ) {
		$drill = TBTD_DB::get_owned_drill( (int) $request['id'], get_current_user_id() );
		if ( ! $drill ) {
			return new WP_Error( 'tbtd_not_found', __( 'That drill no longer exists, or it isn\'t yours.', 'translation-drill' ), array( 'status' => 404 ) );
		}
		TBTD_DB::delete_drill( (int) $drill->id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Everything the builder needs to show a saved drill. Also used by the
	 * teacher page to open a drill for editing without a second request.
	 *
	 * @param object   $drill Drill row.
	 * @param object[] $items Item rows.
	 * @return array
	 */
	public static function drill_payload( $drill, array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'en'   => (string) $item->en,
				'pl'   => (string) $item->pl,
				'note' => (string) $item->note,
			);
		}

		return array(
			'id'         => (int) $drill->id,
			'slug'       => (string) $drill->slug,
			'title'      => (string) $drill->title,
			'context'    => (string) $drill->context,
			'status'     => (string) $drill->status,
			'class_id'   => $drill->class_id ? (int) $drill->class_id : 0,
			'lesson_id'  => $drill->lesson_id ? (int) $drill->lesson_id : 0,
			'items'      => $out,
			'url'        => TBTD_Urls::drill_url( $drill->slug ),
			'editUrl'    => TBTD_Urls::edit_url( (int) $drill->id ),
		);
	}

	/**
	 * Sanitise the submitted sentence list.
	 *
	 * A row with neither English nor Polish is a blank the teacher added and
	 * never filled, and is dropped silently. A row with Polish but no English
	 * is a mistake worth naming.
	 *
	 * @param mixed $raw Submitted items.
	 * @return array|WP_Error
	 */
	private static function sanitize_items( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items  = array();
		$number = 0;
		foreach ( $raw as $item ) {
			++$number;
			if ( ! is_array( $item ) ) {
				continue;
			}
			$en = TBTD_Markup::sanitize( (string) ( $item['en'] ?? '' ) );
			$pl = TBTD_Markup::sanitize( (string) ( $item['pl'] ?? '' ), TBTD_Markup::numbers( $en ) );

			if ( '' === TBTD_Markup::plain( $en ) ) {
				if ( '' === TBTD_Markup::plain( $pl ) ) {
					continue;
				}
				return new WP_Error(
					'tbtd_missing_en',
					/* translators: %d: sentence number */
					sprintf( __( 'Sentence %d has Polish but no English.', 'translation-drill' ), $number ),
					array( 'status' => 400 )
				);
			}

			$items[] = array(
				'en'   => $en,
				'pl'   => $pl,
				'note' => TBTD_Markup::sanitize_note( (string) ( $item['note'] ?? '' ) ),
			);
		}

		if ( count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'tbtd_too_many_items',
				/* translators: %d: maximum sentences per drill */
				sprintf( __( 'A drill can hold up to %d sentences.', 'translation-drill' ), self::MAX_ITEMS ),
				array( 'status' => 400 )
			);
		}

		return $items;
	}

	/**
	 * A drill can only go live when every sentence has its Polish version.
	 * Unpaired highlights are advisory and do not block publishing.
	 *
	 * @param array $items Sanitised items.
	 * @return true|WP_Error
	 */
	private static function check_publishable( array $items ) {
		if ( empty( $items ) ) {
			return new WP_Error( 'tbtd_empty', __( 'Add at least one sentence before publishing.', 'translation-drill' ), array( 'status' => 400 ) );
		}
		foreach ( array_values( $items ) as $i => $item ) {
			if ( '' === TBTD_Markup::plain( $item['pl'] ) ) {
				return new WP_Error(
					'tbtd_missing_pl',
					/* translators: %d: sentence number */
					sprintf( __( 'Sentence %d has no Polish version yet. Translate it or write it before publishing.', 'translation-drill' ), $i + 1 ),
					array( 'status' => 400 )
				);
			}
		}
		return true;
	}
}
