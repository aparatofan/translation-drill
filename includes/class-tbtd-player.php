<?php
/**
 * [tbt_drill] — the student page.
 *
 * The drill is chosen by ?drill={slug}. Students must be logged in: the
 * sentences are rendered into the page only after that check, and there is
 * no public endpoint that returns them, so a link passed outside the class
 * shows a login prompt and nothing else.
 *
 * A draft is visible only to its owner (and administrators), with a banner
 * saying so. That is the teacher's preview before approving.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Player {

	const SHORTCODE = 'tbt_drill';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
	}

	private function is_player_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * The page differs per visitor (logged in or not, owner or not) and per
	 * drill, so no shared cache may keep a copy of it.
	 */
	public function maybe_nocache() {
		if ( headers_sent() || ! $this->is_player_page() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	public function maybe_enqueue() {
		if ( $this->is_player_page() ) {
			self::enqueue();
		}
	}

	public static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		wp_enqueue_style( 'tbtd-tokens', TBTD_URL . 'assets/css/tbt-tokens.css', array(), TBTD_VERSION );
		wp_enqueue_style( 'tbtd-player', TBTD_URL . 'assets/css/player.css', array( 'tbtd-tokens' ), TBTD_VERSION );
		wp_enqueue_script( 'tbtd-player', TBTD_URL . 'assets/js/player.js', array(), TBTD_VERSION, true );

		// Completion and presence go to TBT Notes' Class Progress when Notes is
		// active. Without these keys the player reports nothing.
		$activity = array();
		if ( is_user_logged_in() && class_exists( 'TBT_Notes_Activity_REST' ) && defined( 'TBT_NOTES_REST_NAMESPACE' ) ) {
			$activity = array(
				'activityBase'  => esc_url_raw( rest_url( TBT_NOTES_REST_NAMESPACE . '/activity' ) ),
				'activityNonce' => wp_create_nonce( 'wp_rest' ),
			);
		}

		wp_localize_script(
			'tbtd-player',
			'tbtdPlayer',
			$activity + array(
				'i18n' => array(
					/* translators: 1: current sentence, 2: total */
					'count'      => __( 'Sentence %1$d of %2$d', 'translation-drill' ),
					/* translators: %d: sentence number */
					'goTo'       => __( 'Sentence %d', 'translation-drill' ),
					'showAnswer' => __( 'Show answer', 'translation-drill' ),
					'hideAnswer' => __( 'Hide answer', 'translation-drill' ),
					'tapReveal'  => __( 'Tap to see the full expression', 'translation-drill' ),
					'yours'      => __( 'Your version:', 'translation-drill' ),
					'next'       => __( 'Next →', 'translation-drill' ),
					'restart'    => __( 'Back to 1 ↺', 'translation-drill' ),
				),
			)
		);
	}

	/**
	 * Can this visitor play published drills? Logged in, by default.
	 * Filterable so a membership check can be added without editing the plugin.
	 *
	 * @param object $drill Drill row.
	 * @return bool
	 */
	private function can_play( $drill ) {
		return (bool) apply_filters( 'tbtd_user_can_play', is_user_logged_in(), $drill, get_current_user_id() );
	}

	/**
	 * @param array|string $atts Shortcode attributes (none used).
	 * @return string
	 */
	public function render( $atts = array() ) {
		self::enqueue();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup by slug.
		$slug = isset( $_GET[ TBTD_Urls::DRILL_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ TBTD_Urls::DRILL_PARAM ] ) ) : '';

		if ( '' === $slug ) {
			return $this->message(
				__( 'Translation practice', 'translation-drill' ),
				__( 'Open a drill from the link or QR code your teacher shared.', 'translation-drill' )
			);
		}

		if ( ! is_user_logged_in() ) {
			$here = add_query_arg( TBTD_Urls::DRILL_PARAM, $slug, get_permalink() );
			return $this->message(
				__( 'Log in to practise', 'translation-drill' ),
				__( 'This translation drill is for TBT students. Log in and it opens straight away.', 'translation-drill' ),
				wp_login_url( $here ),
				__( 'Log in', 'translation-drill' )
			);
		}

		$drill = TBTD_DB::get_drill_by_slug( $slug );
		$is_owner = $drill && (
			(int) $drill->owner_id === get_current_user_id() || TBTD_Capabilities::user_can_manage_all()
		);

		$available = $drill && ( TBTD_DB::STATUS_PUBLISHED === $drill->status || $is_owner );
		if ( ! $available ) {
			return $this->message(
				__( 'This drill isn\'t available', 'translation-drill' ),
				__( 'The link may be old, or your teacher hasn\'t published the drill yet.', 'translation-drill' )
			);
		}

		if ( ! $is_owner && ! $this->can_play( $drill ) ) {
			return $this->message(
				__( 'This drill isn\'t available', 'translation-drill' ),
				__( 'Your account doesn\'t have access to this drill. Ask your teacher.', 'translation-drill' )
			);
		}

		$items = array();
		foreach ( TBTD_DB::get_items( (int) $drill->id ) as $item ) {
			if ( '' === TBTD_Markup::plain( $item->pl ) ) {
				continue;
			}
			$items[] = array(
				'pl'   => (string) $item->pl,
				'en'   => (string) $item->en,
				'note' => TBTD_Markup::note_html( $item->note ),
			);
		}

		if ( empty( $items ) ) {
			return $this->message(
				(string) $drill->title,
				__( 'This drill has no sentences yet.', 'translation-drill' )
			);
		}

		$data = array(
			'slug'  => (string) $drill->slug,
			'title' => (string) $drill->title,
			'items' => $items,
		);

		$is_draft = TBTD_DB::STATUS_DRAFT === $drill->status;

		// Only students on a published drill report. The owner (or an admin)
		// screen-sharing a drill must not show up in Class Progress.
		if ( TBTD_DB::STATUS_PUBLISHED === $drill->status && ! $is_owner ) {
			$data['activity'] = array(
				'objectRef'   => (string) $drill->id,
				'objectTitle' => (string) $drill->title,
			);
		}

		ob_start();
		?>
		<div class="tbt tbtd-player" data-role="player">
			<?php if ( $is_draft ) : ?>
				<div class="tbtd-preview-banner">
					<?php esc_html_e( 'Preview — this drill is a draft. Only you can see it until you publish it.', 'translation-drill' ); ?>
					<?php $edit = TBTD_Urls::edit_url( (int) $drill->id ); ?>
					<?php if ( '' !== $edit && (int) $drill->owner_id === get_current_user_id() ) : ?>
						<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Back to editing', 'translation-drill' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<header class="tbtd-p-head">
				<div class="tbtd-p-head-text">
					<p class="tbtd-p-eyebrow"><?php esc_html_e( 'Translation practice · Polish → English', 'translation-drill' ); ?></p>
					<h2 class="tbtd-p-title"><?php echo esc_html( $drill->title ); ?></h2>
				</div>
				<p class="tbtd-p-count" data-role="count"></p>
			</header>

			<nav class="tbtd-p-track" data-role="track" aria-label="<?php esc_attr_e( 'Sentences', 'translation-drill' ); ?>"></nav>

			<div class="tbtd-p-card" data-role="card">
				<section>
					<p class="tbtd-p-lang"><?php esc_html_e( 'Polish', 'translation-drill' ); ?></p>
					<p class="tbtd-p-sentence" lang="pl" data-role="pl"></p>
				</section>

				<label class="tbtd-p-field" for="tbtd-attempt">
					<span><?php esc_html_e( 'Your translation', 'translation-drill' ); ?></span>
					<textarea id="tbtd-attempt" data-role="attempt" rows="2" lang="en"
						placeholder="<?php esc_attr_e( 'Write your English version here, or say it out loud…', 'translation-drill' ); ?>"></textarea>
				</label>

				<div class="tbtd-p-actions">
					<button type="button" class="tbtd-p-btn" data-role="hint-btn" aria-pressed="false"><?php esc_html_e( 'Hint', 'translation-drill' ); ?> <kbd>H</kbd></button>
					<button type="button" class="tbtd-p-btn tbtd-p-btn--primary" data-role="answer-btn"><?php esc_html_e( 'Show answer', 'translation-drill' ); ?> <kbd>A</kbd></button>
				</div>

				<section class="tbtd-p-panel" data-role="hint-panel" hidden>
					<p class="tbtd-p-panel-title"><?php esc_html_e( 'Key expressions · tap to reveal', 'translation-drill' ); ?></p>
					<ul class="tbtd-p-hints" data-role="hints"></ul>
				</section>

				<section class="tbtd-p-panel tbtd-p-answer" data-role="answer-panel" hidden>
					<p class="tbtd-p-lang"><?php esc_html_e( 'English', 'translation-drill' ); ?></p>
					<p class="tbtd-p-sentence" lang="en" data-role="en"></p>
					<p class="tbtd-p-yours" data-role="yours" hidden></p>
					<p class="tbtd-p-note" data-role="note" hidden></p>
				</section>
			</div>

			<div class="tbtd-p-nav">
				<button type="button" class="tbtd-p-btn" data-role="prev"><?php esc_html_e( '← Previous', 'translation-drill' ); ?></button>
				<p class="tbtd-p-keys" aria-hidden="true">
					<span><kbd>←</kbd> <kbd>→</kbd> <?php esc_html_e( 'move', 'translation-drill' ); ?></span>
					<span><kbd>H</kbd> <?php esc_html_e( 'hint', 'translation-drill' ); ?></span>
					<span><kbd>A</kbd> <?php esc_html_e( 'answer', 'translation-drill' ); ?></span>
				</p>
				<button type="button" class="tbtd-p-btn" data-role="next"><?php esc_html_e( 'Next →', 'translation-drill' ); ?></button>
			</div>

			<script type="application/json" data-role="drill-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * A plain message card, optionally with one action.
	 */
	private function message( $title, $text, $action_url = '', $action_label = '' ) {
		ob_start();
		?>
		<div class="tbt tbtd-player">
			<div class="tbtd-p-card tbtd-p-card--message">
				<p class="tbtd-p-eyebrow"><?php esc_html_e( 'Translation practice', 'translation-drill' ); ?></p>
				<h2 class="tbtd-p-title"><?php echo esc_html( $title ); ?></h2>
				<p class="tbtd-p-message"><?php echo esc_html( $text ); ?></p>
				<?php if ( '' !== $action_url ) : ?>
					<a class="tbtd-p-btn tbtd-p-btn--primary" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
