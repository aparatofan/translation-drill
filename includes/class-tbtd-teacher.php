<?php
/**
 * [tbt_drill_builder] — the teacher page.
 *
 * One page, two views, chosen by the query string:
 *   (none)          the library: every drill this teacher owns, by class
 *   ?tbtd_new=1     the builder, empty
 *   ?tbtd_edit=ID   the builder, opened on one of the teacher's own drills
 *
 * The library is server-rendered so it paints in one pass inside Divi. The
 * builder ships its starting data inside the page, so an edit opens with no
 * loading flash and no extra read route.
 *
 * Renders nothing at all for a visitor without the capability: students may
 * land here, and there is no reason to advertise a tool they cannot use.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Teacher {

	const SHORTCODE = 'tbt_drill_builder';

	private static $enqueued = false;

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
	}

	private function is_teacher_page() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( $post->post_content, self::SHORTCODE );
	}

	public function maybe_enqueue() {
		if ( $this->is_teacher_page() && TBTD_Capabilities::user_can_manage() ) {
			self::enqueue();
		}
	}

	/**
	 * Writes go through REST with a wp_rest nonce minted into the page. A
	 * cached copy would carry someone else's nonce and every save would fail,
	 * so this page is never cached.
	 */
	public function maybe_nocache() {
		if ( headers_sent() || ! $this->is_teacher_page() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Idempotent, and public because render() calls it too: a page built by
	 * a theme module can render the shortcode after wp_enqueue_scripts ran.
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style( 'tbtd-tokens', TBTD_URL . 'assets/css/tbt-tokens.css', array(), TBTD_VERSION );
		wp_enqueue_style( 'tbtd-teacher', TBTD_URL . 'assets/css/teacher.css', array( 'tbtd-tokens' ), TBTD_VERSION );
		wp_enqueue_script( 'tbtd-qrcode', TBTD_URL . 'assets/js/lib/qrcode.min.js', array(), TBTD_VERSION, true );
		wp_enqueue_script( 'tbtd-teacher', TBTD_URL . 'assets/js/teacher.js', array( 'tbtd-qrcode' ), TBTD_VERSION, true );

		$user_id = get_current_user_id();
		wp_localize_script(
			'tbtd-teacher',
			'tbtdTeacher',
			array(
				'restBase'     => esc_url_raw( rest_url( TBTD_Rest::NS . '/manage/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'maxSentences' => TBTD_Translator::max_sentences(),
				'maxItems'     => TBTD_Rest::MAX_ITEMS,
				'remaining'    => TBTD_Translator::remaining( $user_id ),
				'libraryUrl'   => TBTD_Urls::library_url(),
				'playerReady'  => '' !== TBTD_Urls::player_page(),
				'editParam'    => TBTD_Urls::EDIT_PARAM,
				'i18n'         => self::strings(),
			)
		);
	}

	/**
	 * Every string the scripts write. Static markup is translated in PHP.
	 *
	 * @return array
	 */
	private static function strings() {
		return array(
			'english'          => __( 'English', 'translation-drill' ),
			'polish'           => __( 'Polish', 'translation-drill' ),
			'note'             => __( 'Language note', 'translation-drill' ),
			'notePlaceholder'  => __( 'Shown under the answer. Use **double asterisks** for bold.', 'translation-drill' ),
			'enPlaceholder'    => __( 'Type or paste the English sentence…', 'translation-drill' ),
			'plPlaceholder'    => __( 'Press Translate, or write the Polish yourself…', 'translation-drill' ),
			'enHint'           => __( 'Click a word to highlight it, or drag across several. Up to 4 per sentence.', 'translation-drill' ),
			'enEmpty'          => __( 'Your sentence appears here, ready for highlighting.', 'translation-drill' ),
			'plEmpty'          => __( 'The Polish sentence appears here.', 'translation-drill' ),
			'removeHighlight'  => __( 'Remove highlight', 'translation-drill' ),
			/* translators: %s: the highlighted words */
			'removeNamed'      => __( 'Remove the highlight “%s”', 'translation-drill' ),
			'allColoursUsed'   => __( 'All four colours are used. Remove one with its × to add another.', 'translation-drill' ),
			'overlap'          => __( 'Those words touch an existing highlight. Remove it first with its ×.', 'translation-drill' ),
			'plSelectFor'      => __( 'Click or drag the Polish words for:', 'translation-drill' ),
			'plAllPairedRemove' => __( 'Every highlight already has its Polish partner. Remove one with its × to pair it differently.', 'translation-drill' ),
			'plAllPaired'      => __( 'Every highlight has its Polish partner.', 'translation-drill' ),
			'plNoMarks'        => __( 'No highlights in the English, so none are needed here.', 'translation-drill' ),
			'statusNew'        => __( 'Needs translation', 'translation-drill' ),
			'statusChanged'    => __( 'English changed — translate again', 'translation-drill' ),
			'statusDone'       => __( 'Translated', 'translation-drill' ),
			'statusMissing'    => __( 'Check highlights', 'translation-drill' ),
			'translateOne'     => __( 'Translate this sentence', 'translation-drill' ),
			'translateAgain'   => __( 'Translate again', 'translation-drill' ),
			'removeSentence'   => __( 'Remove sentence', 'translation-drill' ),
			/* translators: %d: number of sentences */
			'translateN'       => __( 'Translate %d sentences', 'translation-drill' ),
			'translate1'       => __( 'Translate 1 sentence', 'translation-drill' ),
			'allTranslated'    => __( 'All sentences translated', 'translation-drill' ),
			'translating'      => __( 'Translating — this can take up to a minute…', 'translation-drill' ),
			/* translators: %d: translations left today */
			'remaining'        => __( '%d AI requests left today', 'translation-drill' ),
			'noSentences'      => __( 'Add at least one sentence.', 'translation-drill' ),
			'emptySentence'    => __( 'One of the sentences is empty. Write it or remove it.', 'translation-drill' ),
			/* translators: %d: maximum sentences */
			'tooManyItems'     => __( 'A drill can hold up to %d sentences.', 'translation-drill' ),
			'needTitle'        => __( 'Give the drill a title.', 'translation-drill' ),
			'saving'           => __( 'Saving…', 'translation-drill' ),
			'savedDraft'       => __( 'Draft saved.', 'translation-drill' ),
			'savedLive'        => __( 'Saved. Students see the changes straight away.', 'translation-drill' ),
			'published'        => __( 'Published. Share the link or the QR code with your students.', 'translation-drill' ),
			/* translators: %d: sentence number */
			'missingPl'        => __( 'Sentence %d has no Polish version yet.', 'translation-drill' ),
			/* translators: %d: sentence number in Stage 2 */
			'missingEn'        => __( 'Sentence %d in Stage 2 has Polish but no English.', 'translation-drill' ),
			/* translators: 1: sentence number, 2: English fragment */
			'unpairedLine'     => __( 'Sentence %1$d: no Polish highlight for “%2$s”', 'translation-drill' ),
			'unpairedConfirm'  => __( 'Some highlights have no Polish partner, so their hints will show the English only. Publish anyway?', 'translation-drill' ),
			'confirmRemove'    => __( 'Remove this sentence?', 'translation-drill' ),
			/* translators: %d: number of sentences */
			'confirmRemoveAll' => __( 'Remove all %d sentences?', 'translation-drill' ),
			// Content stage.
			/* translators: %d: sentence number */
			'sentenceLabel'    => __( 'Sentence %d', 'translation-drill' ),
			'sentence1'        => __( '1 sentence', 'translation-drill' ),
			/* translators: %d: number of sentences */
			'sentenceN'        => __( '%d sentences', 'translation-drill' ),
			'aiTag'            => __( 'AI', 'translation-drill' ),
			'aiTagTitle'       => __( 'Written by the AI', 'translation-drill' ),
			/* translators: 1: number of sentences, 2: CEFR level */
			'generating'       => __( 'Writing %1$d sentences at %2$s…', 'translation-drill' ),
			'generated1'       => __( 'Added 1 sentence. Edit or remove it before you continue.', 'translation-drill' ),
			/* translators: %d: number of sentences */
			'generatedN'       => __( 'Added %d sentences. Edit or remove any before you continue.', 'translation-drill' ),
			/* translators: 1: sentences added, 2: sentences written, 3: maximum sentences per drill */
			'generatedPart'    => __( 'Added %1$d of %2$d sentences: a drill can hold up to %3$d.', 'translation-drill' ),
			'continueReady'    => __( 'Happy with the list? Highlight the key expressions next.', 'translation-drill' ),
			'continueEmpty'    => __( 'Add at least one sentence to continue.', 'translation-drill' ),
			'continueOpen'     => __( 'Changes here show up in Stage 3 straight away.', 'translation-drill' ),
			'classNone'        => __( 'No class: the drill stays open. Anyone logged in to TBT can use the link.', 'translation-drill' ),
			'classSet'         => __( 'The drill is listed under this class. The link still works for anyone logged in.', 'translation-drill' ),
			'confirmDelete'    => __( 'Delete this drill and all its sentences? This cannot be undone.', 'translation-drill' ),
			'confirmLeave'     => __( 'You have unsaved changes.', 'translation-drill' ),
			'unpublishConfirm' => __( 'Move this drill back to drafts? Students will no longer be able to open it.', 'translation-drill' ),
			'copied'           => __( 'Copied!', 'translation-drill' ),
			'copy'             => __( 'Copy link', 'translation-drill' ),
			'staleNonce'       => __( 'Your session has expired. Refresh the page.', 'translation-drill' ),
			'quota'            => __( 'You\'ve used today\'s AI limit. Try again tomorrow.', 'translation-drill' ),
			'networkError'     => __( 'Something went wrong. Check your connection and try again.', 'translation-drill' ),
			'noPlayerPage'     => __( 'Saved, but an administrator has not chosen the student page yet, so there is no link.', 'translation-drill' ),
			'noLesson'         => __( 'Any lesson', 'translation-drill' ),
			'saveDraft'        => __( 'Save draft', 'translation-drill' ),
			'saveChanges'      => __( 'Save changes', 'translation-drill' ),
			'publish'          => __( 'Approve and publish', 'translation-drill' ),
			'unpublish'        => __( 'Back to draft', 'translation-drill' ),
			'stateDraft'       => __( 'Draft — students cannot open it yet.', 'translation-drill' ),
			'stateLive'        => __( 'Published — students with the link can practise.', 'translation-drill' ),
			'stateNew'         => __( 'Not saved yet.', 'translation-drill' ),
			// Library filter.
			'itemOne'          => __( 'drill', 'translation-drill' ),
			'itemMany'         => __( 'drills', 'translation-drill' ),
			/* translators: 1: drills shown, 2: drills in the group, 3: "drill" or "drills" */
			'filterOf'         => __( '%1$d of %2$d %3$s', 'translation-drill' ),
			/* translators: 1: number of drills, 2: "drill" or "drills" */
			'filterAll'        => __( '%1$d %2$s', 'translation-drill' ),
			/* translators: 1: drills shown, 2: drills in the library, 3: "drill" or "drills" */
			'filterShowing'    => __( 'Showing %1$d of %2$d %3$s', 'translation-drill' ),
			/* translators: 1: class or group name, 2: number of drills */
			'optionLabel'      => __( '%1$s (%2$d)', 'translation-drill' ),
			/* translators: %1$s: the matching sentence */
			'hitQuote'         => __( '“%1$s”', 'translation-drill' ),
			/* translators: %1$d: number of other matching sentences */
			'hitMore'          => __( '+%1$d more', 'translation-drill' ),
		);
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		if ( ! TBTD_Capabilities::user_can_manage() ) {
			return '';
		}

		// The hero is off by default: the TBT pages carry their own header row
		// in Divi. hero="yes" brings the built-in one back for a bare page.
		$atts = shortcode_atts( array( 'hero' => 'no' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$hero = 'yes' === strtolower( trim( (string) $atts['hero'] ) );

		self::enqueue();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$edit_id = isset( $_GET[ TBTD_Urls::EDIT_PARAM ] ) ? absint( $_GET[ TBTD_Urls::EDIT_PARAM ] ) : 0;
		$is_new  = isset( $_GET[ TBTD_Urls::NEW_PARAM ] );
		// phpcs:enable

		ob_start();
		echo '<div class="tbt tbtd-teacher"><div class="tbtd-page">';

		if ( $hero ) {
			$this->render_hero( $edit_id > 0 || $is_new );
		}

		if ( $edit_id > 0 || $is_new ) {
			$drill = null;
			if ( $edit_id > 0 ) {
				$row = TBTD_DB::get_owned_drill( $edit_id, get_current_user_id() );
				if ( ! $row ) {
					$this->render_backlink();
					echo '<div class="tbtd-notice tbtd-notice--error">' . esc_html__( 'That drill no longer exists, or it isn\'t yours.', 'translation-drill' ) . '</div>';
					echo '</div></div>';
					return ob_get_clean();
				}
				$drill = TBTD_Rest::drill_payload( $row, TBTD_DB::get_items( $edit_id ) );
			}
			$this->render_builder( $drill );
		} else {
			$this->render_library();
		}

		echo '</div></div>';
		return ob_get_clean();
	}

	/**
	 * The shared TBT tool hero — same gradient, logo and type as Swipe and the
	 * Matching Game, so the tools read as one product.
	 */
	private function render_hero( $building ) {
		?>
		<header class="tbtd-hero">
			<div class="tbtd-hero-content">
				<p class="tbtd-hero-eyebrow"><?php esc_html_e( 'The Blue Tree Teacher Tools', 'translation-drill' ); ?></p>
				<h1 class="tbtd-hero-title"><?php esc_html_e( 'Translation Drill', 'translation-drill' ); ?></h1>
				<p class="tbtd-hero-sub">
					<?php if ( $building ) : ?>
						<span><?php esc_html_e( 'Write the English, highlight the key expressions.', 'translation-drill' ); ?></span>
						<span><?php esc_html_e( 'AI prepares the Polish; you check it and publish.', 'translation-drill' ); ?></span>
					<?php else : ?>
						<span><?php esc_html_e( 'Polish sentences in, natural English out.', 'translation-drill' ); ?></span>
						<span><?php esc_html_e( 'Every drill you have made, ready to share with your students.', 'translation-drill' ); ?></span>
					<?php endif; ?>
				</p>
			</div>
			<img class="tbtd-hero-logo" src="https://thebluetree.pl/wp-content/uploads/2020/12/TBT-white-logo.png"
				alt="<?php esc_attr_e( 'The Blue Tree', 'translation-drill' ); ?>" loading="lazy" decoding="async">
		</header>
		<?php
	}

	private function render_backlink() {
		$url = TBTD_Urls::library_url();
		if ( '' !== $url ) {
			echo '<a class="tbtd-backlink" href="' . esc_url( $url ) . '">' . esc_html__( '← Back to your drills', 'translation-drill' ) . '</a>';
		}
	}

	/**
	 * The library: drafts first, then each class, then drills with no class.
	 *
	 * The Admin Bar (title ─ search ─ class ─ button) is the one Swipe uses.
	 * Filtering runs in the browser over the rows below: the library has no
	 * pagination, so every drill the teacher owns is already on the page,
	 * together with the text of its sentences for the content search.
	 */
	private function render_library() {
		$user_id = get_current_user_id();
		$drills  = TBTD_DB::drills_for_owner( $user_id );
		$groups  = $this->group_drills( $drills );

		// One query for every sentence in the library, grouped by drill.
		$texts = array();
		if ( $drills ) {
			foreach ( TBTD_DB::item_texts_for_owner( $user_id ) as $item ) {
				$texts[ (int) $item->parent_id ][] = array(
					TBTD_Markup::plain( $item->en ),
					TBTD_Markup::plain( $item->pl ),
				);
			}
		}

		// The filter is only rendered over a library that has something in it.
		$has_items      = ! empty( $drills );
		$filter_options = $has_items ? $this->filter_options( $groups ) : array();
		$player_ready   = '' !== TBTD_Urls::player_page();
		?>
		<div class="tbtd-library" data-role="library">
			<div class="tbtd-library-head<?php echo $has_items ? ' tbtd-library-head--filter' : ''; ?>" data-role="library-head">
				<div class="tbtd-section-head">
					<h2 class="tbtd-section-title"><?php esc_html_e( 'Your drills', 'translation-drill' ); ?></h2>
					<?php // Joins the title to the next item; with no drills it runs to the button. ?>
					<span class="tbtd-library-line" aria-hidden="true"></span>
				</div>
				<?php if ( $has_items ) : ?>
					<div class="tbtd-filter" role="search" data-role="filter">
						<div class="tbtd-filter-search">
							<label class="tbtd-sr" for="tbtd-filter-q"><?php esc_html_e( 'Search drills', 'translation-drill' ); ?></label>
							<svg class="tbtd-filter-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
								<circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/>
								<path d="m20 20-3.6-3.6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
							</svg>
							<input type="search" id="tbtd-filter-q" class="tbtd-input" data-role="filter-q"
								placeholder="<?php esc_attr_e( 'Search drills and sentences', 'translation-drill' ); ?>"
								autocomplete="off" spellcheck="false">
							<button type="button" class="tbtd-filter-clear" data-role="filter-clear"
								aria-label="<?php esc_attr_e( 'Clear search', 'translation-drill' ); ?>" hidden>&times;</button>
						</div>
						<span class="tbtd-library-line" aria-hidden="true"></span>
						<label class="tbtd-sr" for="tbtd-filter-class"><?php esc_html_e( 'Class', 'translation-drill' ); ?></label>
						<select id="tbtd-filter-class" class="tbtd-select tbtd-filter-class" data-role="filter-class">
							<option value=""><?php esc_html_e( 'All classes', 'translation-drill' ); ?></option>
							<?php foreach ( $filter_options as $option ) : ?>
								<option value="<?php echo esc_attr( $option['value'] ); ?>"
									data-name="<?php echo esc_attr( $option['name'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<span class="tbtd-library-line tbtd-library-line--end" aria-hidden="true" data-role="filter-end"></span>
				<?php endif; ?>
				<a class="tbtd-btn tbtd-btn--primary tbtd-library-cta" href="<?php echo esc_url( TBTD_Urls::new_url() ); ?>"><?php esc_html_e( 'Create new drill', 'translation-drill' ); ?></a>
			</div>

			<?php if ( $has_items ) : ?>
				<p class="tbtd-filter-summary" data-role="filter-summary" aria-live="polite" hidden>
					<span data-role="filter-summary-text"></span>
					<button type="button" class="tbtd-filter-link" data-role="filter-reset"><?php esc_html_e( 'Clear filters', 'translation-drill' ); ?></button>
				</p>
			<?php endif; ?>

			<div class="tbtd-notice tbtd-notice--error" data-role="error" hidden></div>

			<?php if ( ! $player_ready ) : ?>
				<div class="tbtd-notice tbtd-notice--warn">
					<?php esc_html_e( 'No student page is set yet, so drills cannot be shared. An administrator can choose it under TBT → Translation Drill Settings.', 'translation-drill' ); ?>
				</div>
			<?php endif; ?>

			<?php // Always present, so deleting the last drill can show it without a reload. ?>
			<div class="tbtd-empty" data-role="library-empty"<?php echo $has_items ? ' hidden' : ''; ?>>
				<p><?php esc_html_e( 'No drills yet. Create your first one: write a few English sentences from your lesson and highlight the expressions you taught.', 'translation-drill' ); ?></p>
			</div>

			<?php
			foreach ( $groups as $group ) {
				$this->render_group( $group, $texts );
			}
			?>

			<?php if ( $has_items ) : ?>
				<div class="tbtd-empty" data-role="filter-empty" hidden>
					<p>
						<?php esc_html_e( 'No drills match your search.', 'translation-drill' ); ?>
						<button type="button" class="tbtd-filter-link" data-role="filter-reset"><?php esc_html_e( 'Clear filters', 'translation-drill' ); ?></button>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<div class="tbtd-modal" data-role="qr-modal" hidden>
			<div class="tbtd-modal-box" role="dialog" aria-modal="true" aria-labelledby="tbtd-qr-title">
				<p class="tbtd-modal-title" id="tbtd-qr-title" data-role="qr-title"></p>
				<div class="tbtd-qr" data-role="qr-target"></div>
				<button type="button" class="tbtd-btn tbtd-btn--ghost" data-role="qr-close"><?php esc_html_e( 'Close', 'translation-drill' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Drafts, then each class in the order the drills bring it up, then
	 * drills with no class.
	 *
	 * Each group carries the two values the library filter addresses it by:
	 * 'key' (dropdown value, URL value, data-filter-group) and 'search_name'.
	 * Only a real class with a resolved title is searchable — "Drafts",
	 * "No class" and the 'Class' fallback are the plugin's own words, so
	 * typing "class" must not match every unattached drill.
	 *
	 * @param object[] $drills Drills with item_count.
	 * @return array[] Each array( 'key', 'title', 'search_name', 'drills' ).
	 */
	private function group_drills( array $drills ) {
		$drafts  = array();
		$classes = array();
		$loose   = array();

		foreach ( $drills as $drill ) {
			if ( TBTD_DB::STATUS_DRAFT === $drill->status ) {
				$drafts[] = $drill;
			} elseif ( $drill->class_id ) {
				$class_id = (int) $drill->class_id;
				if ( ! isset( $classes[ $class_id ] ) ) {
					$title                = TBTD_Classes::class_title( $class_id );
					$classes[ $class_id ] = array(
						'key'         => 'c' . $class_id,
						'title'       => '' !== $title ? $title : __( 'Class', 'translation-drill' ),
						'search_name' => $title,
						'drills'      => array(),
					);
				}
				$classes[ $class_id ]['drills'][] = $drill;
			} else {
				$loose[] = $drill;
			}
		}

		$groups = array();
		if ( $drafts ) {
			$groups[] = array(
				'key'         => 'drafts',
				'title'       => __( 'Drafts', 'translation-drill' ),
				'search_name' => '',
				'drills'      => $drafts,
			);
		}
		foreach ( $classes as $group ) {
			$groups[] = $group;
		}
		if ( $loose ) {
			$groups[] = array(
				'key'         => 'none',
				'title'       => __( 'No class', 'translation-drill' ),
				'search_name' => '',
				'drills'      => $loose,
			);
		}
		return $groups;
	}

	/**
	 * Options for the class dropdown: classes A–Z (accent-stripped, so
	 * "Łukasz" sorts under L), then Drafts, then No class.
	 *
	 * @param array[] $groups Groups from group_drills().
	 * @return array[] Each array( 'value', 'label', 'name', 'count' ).
	 */
	private function filter_options( array $groups ) {
		$classes = array();
		$drafts  = null;
		$none    = null;

		foreach ( $groups as $group ) {
			$count  = count( $group['drills'] );
			$option = array(
				'value' => $group['key'],
				'label' => sprintf(
					/* translators: 1: class or group name, 2: number of drills */
					__( '%1$s (%2$d)', 'translation-drill' ),
					$group['title'],
					$count
				),
				// The bare name, so JS can rebuild the label after a delete.
				'name'  => $group['title'],
				'count' => $count,
			);

			if ( 'drafts' === $group['key'] ) {
				$drafts = $option;
			} elseif ( 'none' === $group['key'] ) {
				$none = $option;
			} else {
				$classes[] = $option;
			}
		}

		usort(
			$classes,
			function ( $a, $b ) {
				return strnatcasecmp( remove_accents( $a['name'] ), remove_accents( $b['name'] ) );
			}
		);

		if ( $drafts ) {
			$classes[] = $drafts;
		}
		if ( $none ) {
			$classes[] = $none;
		}
		return $classes;
	}

	/**
	 * One group of drill rows.
	 *
	 * @param array $group Group from group_drills().
	 * @param array $texts Sentence pairs by drill ID, for the content search.
	 */
	private function render_group( array $group, array $texts ) {
		$count = count( $group['drills'] );
		?>
		<section class="tbtd-group" data-role="group" data-filter-group="<?php echo esc_attr( $group['key'] ); ?>"<?php echo '' !== $group['search_name'] ? ' data-filter-name="' . esc_attr( $group['search_name'] ) . '"' : ''; ?>>
			<div class="tbtd-group-head">
				<h3 class="tbtd-group-name" data-role="group-name"><?php echo esc_html( $group['title'] ); ?></h3>
				<span class="tbtd-group-count" data-role="group-count">
					<?php
					/* translators: %d: number of drills */
					echo esc_html( sprintf( _n( '%d drill', '%d drills', $count, 'translation-drill' ), $count ) );
					?>
				</span>
				<span class="tbtd-group-rule" aria-hidden="true"></span>
			</div>
			<?php foreach ( $group['drills'] as $drill ) : ?>
				<?php $this->render_row( $drill, isset( $texts[ (int) $drill->id ] ) ? $texts[ (int) $drill->id ] : array() ); ?>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * One drill row in the library.
	 *
	 * Drafts offer Preview, Edit and Delete only: a student opening a draft's
	 * link gets "not available", so Copy link and QR would fail silently.
	 *
	 * The data-search-* attributes are what the library filter matches and
	 * marks: the element text may already hold a <mark>, so the source text
	 * travels separately. data-search-items is the [ english, polish ] text
	 * of every sentence, markup stripped.
	 *
	 * @param object $drill Row with item_count.
	 * @param array  $pairs Sentence pairs, each array( english, polish ).
	 */
	private function render_row( $drill, array $pairs = array() ) {
		$is_draft = TBTD_DB::STATUS_DRAFT === $drill->status;
		$url      = TBTD_Urls::drill_url( $drill->slug );
		$class    = $drill->class_id ? TBTD_Classes::class_title( (int) $drill->class_id ) : '';
		$lesson   = $drill->lesson_id ? TBTD_Classes::lesson_title( (int) $drill->lesson_id ) : '';
		$chip     = '' !== $class ? ( '' !== $lesson ? $class . ' · ' . $lesson : $class ) : '';
		$count    = (int) $drill->item_count;
		// Hex-escaped so the attribute holds no quotes or ampersands for
		// esc_attr() to reinterpret; JSON.parse() restores them.
		$items_json = wp_json_encode( array_values( $pairs ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		?>
		<article class="tbtd-drill<?php echo $is_draft ? ' tbtd-drill--draft' : ''; ?>" data-id="<?php echo (int) $drill->id; ?>"
			data-search-title="<?php echo esc_attr( $drill->title ); ?>"
			<?php if ( '' !== $chip ) : ?>
				data-search-chip="<?php echo esc_attr( $chip ); ?>"
			<?php endif; ?>
			data-search-items="<?php echo esc_attr( $items_json ? $items_json : '[]' ); ?>">
			<div class="tbtd-drill-body">
				<h4 class="tbtd-drill-title" data-role="item-title"><?php echo esc_html( $drill->title ); ?></h4>
				<div class="tbtd-drill-meta">
					<?php if ( $is_draft ) : ?>
						<span class="tbtd-chip tbtd-chip--draft"><?php esc_html_e( 'Draft', 'translation-drill' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $chip ) : ?>
						<span class="tbtd-chip" data-role="item-chip"><?php echo esc_html( $chip ); ?></span>
					<?php endif; ?>
					<span>
						<?php
						/* translators: %d: number of sentences */
						echo esc_html( sprintf( _n( '%d sentence', '%d sentences', $count, 'translation-drill' ), $count ) );
						?>
					</span>
					<span aria-hidden="true">·</span>
					<span><?php echo esc_html( mysql2date( get_option( 'date_format' ), $drill->updated_at ) ); ?></span>
				</div>
				<p class="tbtd-hit-line" data-role="item-hit" hidden></p>
			</div>
			<div class="tbtd-drill-actions">
				<?php if ( '' !== $url ) : ?>
					<a class="tbtd-btn tbtd-btn--ghost<?php echo $is_draft ? '' : ' tbtd-btn--fill'; ?>" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
						<?php echo $is_draft ? esc_html__( 'Preview', 'translation-drill' ) : esc_html__( 'Open', 'translation-drill' ); ?>
					</a>
				<?php endif; ?>
				<a class="tbtd-btn tbtd-btn--ghost" href="<?php echo esc_url( TBTD_Urls::edit_url( (int) $drill->id ) ); ?>"><?php esc_html_e( 'Edit', 'translation-drill' ); ?></a>
				<?php if ( ! $is_draft && '' !== $url ) : ?>
					<button type="button" class="tbtd-btn tbtd-btn--ghost" data-role="copy" data-url="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Copy link', 'translation-drill' ); ?></button>
					<button type="button" class="tbtd-btn tbtd-btn--ghost" data-role="qr" data-url="<?php echo esc_url( $url ); ?>" data-title="<?php echo esc_attr( $drill->title ); ?>"><?php esc_html_e( 'QR code', 'translation-drill' ); ?></button>
				<?php endif; ?>
				<button type="button" class="tbtd-btn tbtd-btn--ghost tbtd-btn--danger" data-role="delete"><?php esc_html_e( 'Delete', 'translation-drill' ); ?></button>
			</div>
		</article>
		<?php
	}

	/**
	 * The four-stage builder: details, content, highlight, approve. Rows are
	 * drawn by teacher.js from the JSON below; everything static is here.
	 *
	 * @param array|null $drill Drill payload, or null for a new drill.
	 */
	private function render_builder( $drill ) {
		$classes     = TBTD_Classes::for_teacher( get_current_user_id() );
		$class_id    = $drill ? (int) $drill['class_id'] : 0;
		$class_title = '';
		// The field shows a name, the drill stores an id. A saved class this
		// teacher no longer owns resolves to no class, as the select did.
		foreach ( $classes as $class ) {
			if ( (int) $class['id'] === $class_id ) {
				$class_title = (string) ( $class['title'] ?? '' );
				break;
			}
		}
		if ( '' === $class_title ) {
			$class_id = 0;
		}
		$lessons      = $class_id ? TBTD_Classes::lessons_for_class( $class_id ) : array();
		$lesson_id    = $drill ? (int) $drill['lesson_id'] : 0;
		$lesson_known = $lesson_id > 0 && in_array( $lesson_id, array_map( 'intval', array_column( $lessons, 'id' ) ), true );
		$max       = TBTD_Translator::max_sentences();
		// A new drill opens on the AI composer and waits for "Continue" before
		// Stage 3; an existing one opens on its own sentences, Stage 3 open.
		$is_new     = ! $drill;
		$has_items  = $drill && ! empty( $drill['items'] );
		$levels     = array(
			'A1' => __( 'elementary', 'translation-drill' ),
			'A2' => __( 'pre-intermediate', 'translation-drill' ),
			'B1' => __( 'intermediate', 'translation-drill' ),
			'B2' => __( 'upper-intermediate', 'translation-drill' ),
			'C1' => __( 'advanced', 'translation-drill' ),
			'C2' => __( 'proficient', 'translation-drill' ),
		);
		?>
		<?php $this->render_backlink(); ?>

		<div class="tbtd-builder" data-role="builder">
			<div class="tbtd-notice tbtd-notice--error" data-role="error" hidden></div>

			<script type="application/json" data-role="drill-data"><?php echo wp_json_encode( $drill, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

			<section class="tbtd-stage" data-stage="1">
				<div class="tbtd-stage-card">
					<div class="tbtd-stage-head">
						<h2 class="tbtd-stage-title"><b>1</b> <?php esc_html_e( 'Details', 'translation-drill' ); ?></h2>
						<p class="tbtd-stage-sub"><?php esc_html_e( 'Name the drill and, if you like, attach it to a class.', 'translation-drill' ); ?></p>
					</div>

					<div class="tbtd-field">
						<label class="tbtd-label" for="tbtd-title"><?php esc_html_e( 'Title', 'translation-drill' ); ?></label>
						<input type="text" class="tbtd-input" id="tbtd-title" maxlength="200" data-role="title"
							placeholder="<?php esc_attr_e( 'e.g. Psychologists — workplace expressions', 'translation-drill' ); ?>"
							value="<?php echo esc_attr( $drill ? $drill['title'] : '' ); ?>">
					</div>

					<?php if ( TBTD_Classes::available() && $classes ) : ?>
						<div class="tbtd-pair">
							<div class="tbtd-field">
								<label class="tbtd-label" for="tbtd-class"><?php esc_html_e( 'Class', 'translation-drill' ); ?> <span class="tbtd-optional"><?php esc_html_e( 'optional', 'translation-drill' ); ?></span></label>
								<?php
								/*
								 * A text field bound to a datalist rather than a select,
								 * as in Swipe: a teacher with thirty classes types two
								 * letters and the list narrows. The datalist hands back
								 * the class name, so teacher.js resolves the id it stands
								 * for and parks it in the hidden field the save reads.
								 */
								?>
								<input type="text" class="tbtd-input" id="tbtd-class" data-role="class"
									list="tbtd-class-list" autocomplete="off" spellcheck="false"
									placeholder="<?php esc_attr_e( 'No class — type to filter', 'translation-drill' ); ?>"
									value="<?php echo esc_attr( $class_title ); ?>">
								<datalist id="tbtd-class-list">
									<?php foreach ( $classes as $class ) : ?>
										<option value="<?php echo esc_attr( (string) ( $class['title'] ?? '' ) ); ?>"
											data-id="<?php echo (int) $class['id']; ?>"></option>
									<?php endforeach; ?>
								</datalist>
								<input type="hidden" data-role="class-id" value="<?php echo $class_id ? (int) $class_id : ''; ?>">
								<p class="tbtd-help" data-role="class-help"></p>
								<p class="tbtd-help" data-role="class-hint" hidden>
									<strong><?php esc_html_e( 'No class matches that. Pick one from the list, or clear the field to save without a class.', 'translation-drill' ); ?></strong>
								</p>
							</div>
							<?php
							/*
							 * Newest lesson first, "Any lesson" last. Unlike Swipe the
							 * lesson stays optional, so a drill saved with "Any lesson"
							 * keeps it. A saved lesson the class no longer has falls
							 * back to "Any lesson", never to the newest: opening a drill
							 * must not re-anchor it on its own.
							 */
							?>
							<div class="tbtd-field" data-role="lesson-wrap" <?php echo $class_id ? '' : 'hidden'; ?>>
								<label class="tbtd-label" for="tbtd-lesson"><?php esc_html_e( 'Lesson', 'translation-drill' ); ?></label>
								<select class="tbtd-select" id="tbtd-lesson" data-role="lesson">
									<?php foreach ( $lessons as $lesson ) : ?>
										<option value="<?php echo (int) $lesson['id']; ?>" <?php selected( (int) $lesson['id'], $lesson_id ); ?>><?php echo esc_html( (string) ( $lesson['header'] ?? '' ) ); ?></option>
									<?php endforeach; ?>
									<option value="0" <?php selected( $lesson_known, false ); ?>><?php esc_html_e( 'Any lesson', 'translation-drill' ); ?></option>
								</select>
							</div>
						</div>
					<?php endif; ?>

				</div>
			</section>

			<section class="tbtd-stage" data-stage="2">
				<div class="tbtd-stage-card">
					<div class="tbtd-stage-head">
						<h2 class="tbtd-stage-title"><b>2</b> <?php esc_html_e( 'Content', 'translation-drill' ); ?></h2>
						<p class="tbtd-stage-sub"><?php esc_html_e( 'Write the English sentences yourself, or let the AI write them. These are the answers students will say.', 'translation-drill' ); ?></p>
					</div>

					<?php
					/*
					 * Real radios under the tiles, as Swipe's level picker. The
					 * mode only decides which composer shows; both feed one list.
					 */
					?>
					<fieldset class="tbtd-modes">
						<legend class="tbtd-sr"><?php esc_html_e( 'How do you want to add sentences?', 'translation-drill' ); ?></legend>
						<label class="tbtd-tile">
							<input type="radio" class="tbtd-tile-input" name="tbtd-mode" value="own" data-role="mode-own" <?php checked( ! $is_new ); ?>>
							<span class="tbtd-tile-box">
								<span class="tbtd-tile-code"><?php esc_html_e( 'Write my own', 'translation-drill' ); ?></span>
								<span class="tbtd-tile-name"><?php esc_html_e( 'Type or paste the English sentences.', 'translation-drill' ); ?></span>
							</span>
						</label>
						<label class="tbtd-tile">
							<input type="radio" class="tbtd-tile-input" name="tbtd-mode" value="ai" data-role="mode-ai" <?php checked( $is_new ); ?>>
							<span class="tbtd-tile-box">
								<span class="tbtd-tile-code"><?php esc_html_e( 'Generate with AI', 'translation-drill' ); ?></span>
								<span class="tbtd-tile-name"><?php esc_html_e( 'Choose the level and topic. The AI writes the sentences.', 'translation-drill' ); ?></span>
							</span>
						</label>
					</fieldset>

					<div class="tbtd-compose" data-role="compose-ai" <?php echo $is_new ? '' : 'hidden'; ?>>
						<fieldset class="tbtd-fieldset">
							<legend class="tbtd-label"><?php esc_html_e( 'How many sentences?', 'translation-drill' ); ?></legend>
							<div class="tbtd-tiles tbtd-tiles--count">
								<?php for ( $n = TBTD_Translator::GENERATE_MIN; $n <= TBTD_Translator::GENERATE_MAX; $n++ ) : ?>
									<label class="tbtd-tile">
										<input type="radio" class="tbtd-tile-input" name="tbtd-count" value="<?php echo (int) $n; ?>" <?php checked( 6, $n ); ?>>
										<span class="tbtd-tile-box"><span class="tbtd-tile-code"><?php echo (int) $n; ?></span></span>
									</label>
								<?php endfor; ?>
							</div>
						</fieldset>

						<fieldset class="tbtd-fieldset">
							<legend class="tbtd-label"><?php esc_html_e( 'Level', 'translation-drill' ); ?></legend>
							<div class="tbtd-tiles tbtd-tiles--level">
								<?php foreach ( $levels as $code => $name ) : ?>
									<label class="tbtd-tile">
										<input type="radio" class="tbtd-tile-input" name="tbtd-level" value="<?php echo esc_attr( $code ); ?>" <?php checked( 'B1', $code ); ?>>
										<span class="tbtd-tile-box">
											<span class="tbtd-tile-code"><?php echo esc_html( $code ); ?></span>
											<span class="tbtd-tile-name"><?php echo esc_html( $name ); ?></span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>

						<div class="tbtd-pair">
							<div class="tbtd-field">
								<label class="tbtd-label" for="tbtd-vocabulary"><?php esc_html_e( 'Vocabulary area', 'translation-drill' ); ?> <span class="tbtd-optional"><?php esc_html_e( 'optional', 'translation-drill' ); ?></span></label>
								<input type="text" class="tbtd-input" id="tbtd-vocabulary" maxlength="200" data-role="vocabulary"
									placeholder="<?php esc_attr_e( 'e.g. talking to parents about a child\'s progress', 'translation-drill' ); ?>">
							</div>
							<div class="tbtd-field">
								<label class="tbtd-label" for="tbtd-grammar"><?php esc_html_e( 'Grammar topic', 'translation-drill' ); ?> <span class="tbtd-optional"><?php esc_html_e( 'optional', 'translation-drill' ); ?></span></label>
								<input type="text" class="tbtd-input" id="tbtd-grammar" maxlength="200" data-role="grammar"
									placeholder="<?php esc_attr_e( 'e.g. present perfect', 'translation-drill' ); ?>">
							</div>
						</div>

						<div class="tbtd-gen-row">
							<button type="button" class="tbtd-btn tbtd-btn--primary" data-role="generate"><?php esc_html_e( 'Generate sentences', 'translation-drill' ); ?></button>
							<p class="tbtd-help" data-role="gen-status" role="status" aria-live="polite"><?php esc_html_e( 'New sentences are added to the list below. Edit or remove any before you continue.', 'translation-drill' ); ?></p>
						</div>
					</div>

					<div class="tbtd-compose" data-role="compose-own" <?php echo $is_new ? 'hidden' : ''; ?>>
						<details class="tbtd-paste" data-role="paste-box">
							<summary><?php esc_html_e( 'Paste several sentences at once', 'translation-drill' ); ?></summary>
							<textarea class="tbtd-textarea" id="tbtd-paste" data-role="paste" rows="6"
								placeholder="<?php esc_attr_e( "One English sentence per line.\nYou can mark expressions now as [expression|1], or highlight them below.", 'translation-drill' ); ?>"></textarea>
							<button type="button" class="tbtd-btn tbtd-btn--soft" data-role="paste-add"><?php esc_html_e( 'Add these sentences', 'translation-drill' ); ?></button>
						</details>
						<p class="tbtd-help tbtd-compose-help"><?php esc_html_e( 'Type one English sentence per row below. Press Enter to start the next one.', 'translation-drill' ); ?></p>
					</div>

					<div class="tbtd-list-head">
						<h3 class="tbtd-list-title"><?php esc_html_e( 'Your sentences', 'translation-drill' ); ?></h3>
						<span class="tbtd-list-count" data-role="list-count"></span>
						<span class="tbtd-list-rule" aria-hidden="true"></span>
					</div>
					<ol class="tbtd-srows" data-role="srows"></ol>
					<div class="tbtd-list-empty" data-role="list-empty" hidden><?php esc_html_e( 'No sentences yet. Generate some above, or add your own.', 'translation-drill' ); ?></div>
					<div class="tbtd-list-tools">
						<button type="button" class="tbtd-btn tbtd-btn--ghost tbtd-add" data-role="add"><?php esc_html_e( '+ Add a sentence', 'translation-drill' ); ?></button>
						<button type="button" class="tbtd-linkbtn" data-role="remove-all" hidden><?php esc_html_e( 'Remove all', 'translation-drill' ); ?></button>
					</div>

					<div class="tbtd-translate-bar">
						<button type="button" class="tbtd-btn tbtd-btn--primary" data-role="continue" <?php echo $has_items ? 'hidden' : ''; ?>><?php esc_html_e( 'Continue to highlighting', 'translation-drill' ); ?></button>
						<p class="tbtd-help" data-role="continue-help"></p>
					</div>
				</div>
			</section>

			<section class="tbtd-stage" data-stage="3" data-role="stage-highlight" data-state="<?php echo $has_items ? 'active' : 'waiting'; ?>">
				<div class="tbtd-stage-card">
					<div class="tbtd-stage-head">
						<h2 class="tbtd-stage-title"><b>3</b> <?php esc_html_e( 'Highlight', 'translation-drill' ); ?></h2>
						<p class="tbtd-stage-sub"><?php esc_html_e( 'Click a word or drag across several to highlight the key expressions, then translate. Check every Polish sentence before you publish.', 'translation-drill' ); ?></p>
					</div>

					<p class="tbtd-stage-wait" data-role="stage-wait" <?php echo $has_items ? 'hidden' : ''; ?>><?php esc_html_e( 'Your sentences appear here when you continue from Stage 2.', 'translation-drill' ); ?></p>

					<div data-role="stage-body" <?php echo $has_items ? '' : 'hidden'; ?>>
						<ol class="tbtd-items" data-role="items"></ol>

						<div class="tbtd-translate-bar">
							<button type="button" class="tbtd-btn tbtd-btn--primary" data-role="translate"><?php esc_html_e( 'Translate', 'translation-drill' ); ?></button>
							<p class="tbtd-help" data-role="translate-status">
								<?php
								/* translators: %d: maximum sentences per translation */
								echo esc_html( sprintf( __( 'Up to %d sentences go to the AI at a time; longer drills are sent in parts.', 'translation-drill' ), $max ) );
								?>
							</p>
						</div>
					</div>
				</div>
			</section>

			<section class="tbtd-stage" data-stage="4">
				<div class="tbtd-stage-card">
					<div class="tbtd-stage-head">
						<h2 class="tbtd-stage-title"><b>4</b> <?php esc_html_e( 'Approve and share', 'translation-drill' ); ?></h2>
						<p class="tbtd-stage-sub" data-role="state-line"></p>
					</div>

					<ul class="tbtd-warnings" data-role="warnings" hidden></ul>

					<div class="tbtd-save-row">
						<button type="button" class="tbtd-btn tbtd-btn--ghost" data-role="save"><?php esc_html_e( 'Save draft', 'translation-drill' ); ?></button>
						<a class="tbtd-btn tbtd-btn--ghost" data-role="preview" href="#" target="_blank" rel="noopener" hidden><?php esc_html_e( 'Preview as a student', 'translation-drill' ); ?></a>
						<button type="button" class="tbtd-btn tbtd-btn--primary" data-role="publish"><?php esc_html_e( 'Approve and publish', 'translation-drill' ); ?></button>
						<button type="button" class="tbtd-linkbtn" data-role="unpublish" hidden><?php esc_html_e( 'Back to draft', 'translation-drill' ); ?></button>
					</div>
					<p class="tbtd-saved-msg" data-role="saved-msg" role="status" aria-live="polite"></p>

					<div class="tbtd-share" data-role="share" hidden>
						<figure class="tbtd-qr" data-role="share-qr"></figure>
						<div class="tbtd-share-body">
							<label class="tbtd-label" for="tbtd-share-url"><?php esc_html_e( 'Student link', 'translation-drill' ); ?></label>
							<div class="tbtd-linkrow">
								<input type="text" class="tbtd-input tbtd-input--mono" id="tbtd-share-url" data-role="share-url" readonly>
								<button type="button" class="tbtd-btn tbtd-btn--ghost" data-role="share-copy"><?php esc_html_e( 'Copy link', 'translation-drill' ); ?></button>
								<a class="tbtd-btn tbtd-btn--ghost tbtd-btn--fill" data-role="share-open" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'translation-drill' ); ?></a>
							</div>
							<p class="tbtd-help"><?php esc_html_e( 'Students must be logged in to TBT to open it. The link stays the same when you edit the drill later.', 'translation-drill' ); ?></p>
						</div>
					</div>
				</div>
			</section>
			<?php
			// Starting the next drill ends the whole flow rather than Stage 4, so the
			// button sits centred under the stage instead of inside its card. It follows
			// the share panel's visibility.
			?>
			<div class="tbtd-another-row" data-role="another-row" hidden>
				<a class="tbtd-btn tbtd-btn--primary" href="<?php echo esc_url( TBTD_Urls::new_url() ); ?>">
					<?php esc_html_e( 'Create another drill', 'translation-drill' ); ?>
				</a>
			</div>
		</div>
		<?php
	}
}
