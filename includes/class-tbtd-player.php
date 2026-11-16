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
			$activity + self::audio() + array(
				'resultsUrl' => self::results_url(),
				'i18n'       => array(
					/* translators: 1: current sentence, 2: total */
					'count'        => __( 'Sentence %1$d of %2$d', 'translation-drill' ),
					/* translators: %d: sentence number */
					'goTo'         => __( 'Sentence %d', 'translation-drill' ),
					'showAnswer'   => __( 'Show model answer', 'translation-drill' ),
					'hideAnswer'   => __( 'Hide model answer', 'translation-drill' ),
					/* translators: spoken label for a gapped phrase. 1: its first letter, 2: number of letters */
					'startsWith'   => __( 'Starts with %1$s, %2$d letters', 'translation-drill' ),
					'tapReveal'    => __( 'Tap to see the full expression', 'translation-drill' ),
					'listen'       => __( 'Listen', 'translation-drill' ),
					'stop'         => __( 'Stop', 'translation-drill' ),
					'loading'      => __( 'Loading', 'translation-drill' ),
					'listenLabel'  => __( 'Listen to the model answer', 'translation-drill' ),
					'next'         => __( 'Next →', 'translation-drill' ),
					'finish'       => __( 'CHECK YOUR SCORE', 'translation-drill' ),
					'finished'     => __( 'Drill finished', 'translation-drill' ),
					/* translators: %d: number of points collected */
					'points'       => __( '%d points', 'translation-drill' ),
					'complete'     => __( 'Drill complete', 'translation-drill' ),
					'fullScore'    => __( 'Full score!', 'translation-drill' ),
					'fullScoreSub' => __( 'You collected every point in this drill.', 'translation-drill' ),
					'partialSub'   => __( 'Reveal the phrases, open the model answer and listen to it — each one is a point.', 'translation-drill' ),
					'saving'       => __( 'Saving to My results…', 'translation-drill' ),
					'saved'        => __( 'Saved to My results', 'translation-drill' ),
					'saveFailed'   => __( 'Not saved — check your connection.', 'translation-drill' ),
					'retrySave'    => __( 'Try saving again', 'translation-drill' ),
					'previewNote'  => __( 'Preview — your own drills are not saved to results.', 'translation-drill' ),
				),
			)
		);
	}

	/**
	 * The page holding TBT Results' [tbt_my_results], for the end screen's
	 * "My results" link. Results has no helper for it, so the first published
	 * page with the shortcode is used. Cached for 12 hours, empty included.
	 *
	 * @return string Permalink, or '' when Results is inactive or no page has it.
	 */
	private static function results_url() {
		if ( ! shortcode_exists( 'tbt_my_results' ) ) {
			return '';
		}
		$url = get_transient( 'tbtd_results_url' );
		if ( false !== $url ) {
			return (string) $url;
		}

		global $wpdb;
		$page_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
				'%' . $wpdb->esc_like( '[tbt_my_results' ) . '%'
			)
		);
		$url = $page_id ? (string) get_permalink( $page_id ) : '';
		set_transient( 'tbtd_results_url', $url, 12 * HOUR_IN_SECONDS );
		return $url;
	}

	/**
	 * Audio for the model answer, when TBT Flashcards is active and has an
	 * ElevenLabs key. The drill borrows Flashcards' proxy so both share one
	 * key, one voice and one MP3 cache. The key is read only to test that it
	 * is set; it never reaches the page.
	 *
	 * @return array Empty when audio is off.
	 */
	private static function audio() {
		static $audio = null;
		if ( null !== $audio ) {
			return $audio;
		}
		$audio = array();
		if ( function_exists( 'tbt_fc_tts_proxy' ) && '' !== (string) get_option( 'tbt_fc_elevenlabs_api_key', '' ) ) {
			$audio = array(
				'ttsUrl'    => admin_url( 'admin-ajax.php' ),
				'ttsAction' => 'tbt_fc_tts',
				'ttsNonce'  => wp_create_nonce( 'tbt_fc_tts_nonce' ),
			);
		}
		return $audio;
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
			// The teacher's note stays on the teacher page: shown under the model
			// answer, students took it for the answer itself.
			$items[] = array(
				'pl' => (string) $item->pl,
				'en' => (string) $item->en,
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

		// The end screen says nothing is saved instead of showing a save status.
		if ( $is_owner || $is_draft ) {
			$data['preview'] = true;
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
				<div class="tbtd-p-head-meta">
					<p class="tbtd-p-count" data-role="count"></p>
					<p class="tbtd-p-points" data-role="points" aria-live="polite"></p>
				</div>
			</header>

			<nav class="tbtd-p-track" data-role="track" aria-label="<?php esc_attr_e( 'Sentences', 'translation-drill' ); ?>"></nav>

			<div class="tbtd-p-card" data-role="card">
				<section class="tbtd-p-step" aria-labelledby="tbtd-s1">
					<p class="tbtd-p-step-head" id="tbtd-s1">
						<span class="tbtd-p-step-num">1</span><span class="tbtd-p-step-label"><?php esc_html_e( 'Polish sentence', 'translation-drill' ); ?></span>
					</p>
					<p class="tbtd-p-sentence" lang="pl" data-role="pl"></p>
				</section>

				<section class="tbtd-p-step" data-role="step-phrases" aria-labelledby="tbtd-s2">
					<p class="tbtd-p-step-head" id="tbtd-s2">
						<span class="tbtd-p-step-num">2</span><span class="tbtd-p-step-label"><?php esc_html_e( 'Translate phrases', 'translation-drill' ); ?></span>
					</p>
					<ul class="tbtd-p-phrases" data-role="phrases"></ul>
				</section>

				<section class="tbtd-p-step" data-role="step-answer" aria-labelledby="tbtd-s3">
					<p class="tbtd-p-step-head" id="tbtd-s3">
						<span class="tbtd-p-step-num" data-role="answer-num">3</span><span class="tbtd-p-step-label"><?php esc_html_e( 'Model answer', 'translation-drill' ); ?></span>
					</p>
					<button type="button" class="tbtd-p-btn tbtd-p-btn--primary" data-role="answer-btn"><?php esc_html_e( 'Show model answer', 'translation-drill' ); ?> <kbd>A</kbd></button>
					<div class="tbtd-p-answer" data-role="answer-panel" hidden>
						<p class="tbtd-p-sentence" lang="en" data-role="en"></p>
						<div class="tbtd-p-answer-actions">
							<button type="button" class="tbtd-p-btn tbtd-p-listen" data-role="listen" data-state="idle" hidden></button>
							<button type="button" class="tbtd-p-btn" data-role="hide-btn"><?php esc_html_e( 'Hide model answer', 'translation-drill' ); ?></button>
						</div>
					</div>
				</section>
			</div>

			<div class="tbtd-p-nav" data-role="nav">
				<button type="button" class="tbtd-p-btn" data-role="prev"><?php esc_html_e( '← Previous', 'translation-drill' ); ?></button>
				<p class="tbtd-p-keys" aria-hidden="true">
					<span><kbd>←</kbd> <kbd>→</kbd> <?php esc_html_e( 'move', 'translation-drill' ); ?></span>
					<span><kbd>A</kbd> <?php esc_html_e( 'model answer', 'translation-drill' ); ?></span>
					<?php if ( ! empty( self::audio() ) ) : ?>
						<span><kbd>P</kbd> <?php esc_html_e( 'listen', 'translation-drill' ); ?></span>
					<?php endif; ?>
				</p>
				<button type="button" class="tbtd-p-btn" data-role="next"><?php esc_html_e( 'Next →', 'translation-drill' ); ?></button>
			</div>

			<section class="tbtd-p-card tbtd-p-end" data-role="end" hidden aria-live="polite">
				<div class="tbtd-p-end-badge" data-role="end-badge">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
				</div>
				<h3 class="tbtd-p-end-title" data-role="end-title" tabindex="-1"></h3>
				<p class="tbtd-p-end-sub" data-role="end-sub"></p>

				<div class="tbtd-p-stats">
					<div class="tbtd-p-stat">
						<p class="tbtd-p-stat-num" data-role="end-score"></p>
						<p class="tbtd-p-stat-label"><?php esc_html_e( 'Points', 'translation-drill' ); ?></p>
					</div>
					<div class="tbtd-p-stat">
						<p class="tbtd-p-stat-num" data-role="end-time"></p>
						<p class="tbtd-p-stat-label"><?php esc_html_e( 'Time', 'translation-drill' ); ?></p>
					</div>
				</div>

				<div class="tbtd-p-missed" data-role="missed" hidden>
					<p class="tbtd-p-missed-label"><?php esc_html_e( 'Points still to collect — go back to sentence:', 'translation-drill' ); ?></p>
					<div class="tbtd-p-missed-list" data-role="missed-list"></div>
				</div>

				<p class="tbtd-p-save" data-role="save" hidden></p>

				<div class="tbtd-p-end-actions">
					<button type="button" class="tbtd-p-btn tbtd-p-btn--primary" data-role="again"><?php esc_html_e( 'Try again ↺', 'translation-drill' ); ?></button>
					<a class="tbtd-p-btn" href="#" data-role="results" hidden><?php esc_html_e( 'My results', 'translation-drill' ); ?></a>
				</div>
			</section>

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
