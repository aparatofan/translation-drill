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
			'remaining'        => __( '%d translations left today', 'translation-drill' ),
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
			/* translators: 1: sentence number, 2: English fragment */
			'unpairedLine'     => __( 'Sentence %1$d: no Polish highlight for “%2$s”', 'translation-drill' ),
			'unpairedConfirm'  => __( 'Some highlights have no Polish partner, so their hints will show the English only. Publish anyway?', 'translation-drill' ),
			'confirmRemove'    => __( 'Remove this sentence?', 'translation-drill' ),
			'confirmDelete'    => __( 'Delete this drill and all its sentences? This cannot be undone.', 'translation-drill' ),
			'confirmLeave'     => __( 'You have unsaved changes.', 'translation-drill' ),
			'unpublishConfirm' => __( 'Move this drill back to drafts? Students will no longer be able to open it.', 'translation-drill' ),
			'copied'           => __( 'Copied!', 'translation-drill' ),
			'copy'             => __( 'Copy link', 'translation-drill' ),
			'staleNonce'       => __( 'Your session has expired. Refresh the page.', 'translation-drill' ),
			'quota'            => __( 'You\'ve used today\'s translation limit.', 'translation-drill' ),
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
	 */
	private function render_library() {
		$drills = TBTD_DB::drills_for_owner( get_current_user_id() );
		$groups = array();
		$drafts = array();
		$loose  = array();

		foreach ( $drills as $drill ) {
			if ( TBTD_DB::STATUS_DRAFT === $drill->status ) {
				$drafts[] = $drill;
			} elseif ( $drill->class_id ) {
				$groups[ (int) $drill->class_id ][] = $drill;
			} else {
				$loose[] = $drill;
			}
		}

		$player_ready = '' !== TBTD_Urls::player_page();
		?>
		<div class="tbtd-library" data-role="library">
			<div class="tbtd-library-head">
				<h2 class="tbtd-section-title"><?php esc_html_e( 'Your drills', 'translation-drill' ); ?></h2>
				<span class="tbtd-library-line" aria-hidden="true"></span>
				<a class="tbtd-btn tbtd-btn--primary" href="<?php echo esc_url( TBTD_Urls::new_url() ); ?>"><?php esc_html_e( 'Create a drill', 'translation-drill' ); ?></a>
			</div>

			<div class="tbtd-notice tbtd-notice--error" data-role="error" hidden></div>

			<?php if ( ! $player_ready ) : ?>
				<div class="tbtd-notice tbtd-notice--warn">
					<?php esc_html_e( 'No student page is set yet, so drills cannot be shared. An administrator can choose it under TBT → Translation Drill Settings.', 'translation-drill' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( empty( $drills ) ) : ?>
				<div class="tbtd-empty">
					<p><?php esc_html_e( 'No drills yet. Create your first one: write a few English sentences from your lesson and highlight the expressions you taught.', 'translation-drill' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			if ( $drafts ) {
				$this->render_group( __( 'Drafts', 'translation-drill' ), $drafts );
			}
			foreach ( $groups as $class_id => $list ) {
				$title = TBTD_Classes::class_title( $class_id );
				$this->render_group( '' !== $title ? $title : __( 'Class', 'translation-drill' ), $list );
			}
			if ( $loose ) {
				$this->render_group( __( 'No class', 'translation-drill' ), $loose );
			}
			?>
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
	 * One group of drill rows.
	 *
	 * @param string   $name   Group heading.
	 * @param object[] $drills Rows.
	 */
	private function render_group( $name, array $drills ) {
		$count = count( $drills );
		?>
		<section class="tbtd-group">
			<div class="tbtd-group-head">
				<h3 class="tbtd-group-name"><?php echo esc_html( $name ); ?></h3>
				<span class="tbtd-group-count">
					<?php
					/* translators: %d: number of drills */
					echo esc_html( sprintf( _n( '%d drill', '%d drills', $count, 'translation-drill' ), $count ) );
					?>
				</span>
				<span class="tbtd-group-rule" aria-hidden="true"></span>
			</div>
			<?php foreach ( $drills as $drill ) : ?>
				<?php $this->render_row( $drill ); ?>
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
	 * @param object $drill Row with item_count.
	 */
	private function render_row( $drill ) {
		$is_draft = TBTD_DB::STATUS_DRAFT === $drill->status;
		$url      = TBTD_Urls::drill_url( $drill->slug );
		$class    = $drill->class_id ? TBTD_Classes::class_title( (int) $drill->class_id ) : '';
		$lesson   = $drill->lesson_id ? TBTD_Classes::lesson_title( (int) $drill->lesson_id ) : '';
		$count    = (int) $drill->item_count;
		?>
		<article class="tbtd-drill<?php echo $is_draft ? ' tbtd-drill--draft' : ''; ?>" data-id="<?php echo (int) $drill->id; ?>">
			<div class="tbtd-drill-body">
				<h4 class="tbtd-drill-title"><?php echo esc_html( $drill->title ); ?></h4>
				<div class="tbtd-drill-meta">
					<?php if ( $is_draft ) : ?>
						<span class="tbtd-chip tbtd-chip--draft"><?php esc_html_e( 'Draft', 'translation-drill' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $class ) : ?>
						<span class="tbtd-chip"><?php echo esc_html( '' !== $lesson ? $class . ' · ' . $lesson : $class ); ?></span>
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
	 * The three-stage builder. Rows are drawn by teacher.js from the JSON
	 * below; everything static is here.
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
		?>
		<?php $this->render_backlink(); ?>

		<div class="tbtd-builder" data-role="builder">
			<div class="tbtd-notice tbtd-notice--error" data-role="error" hidden></div>

			<script type="application/json" data-role="drill-data"><?php echo wp_json_encode( $drill, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

			<section class="tbtd-stage" data-stage="1">
				<div class="tbtd-stage-card">
					<div class="tbtd-stage-head">
						<h2 class="tbtd-stage-title"><b>1</b> <?php esc_html_e( 'Details', 'translation-drill' ); ?></h2>
						<p class="tbtd-stage-sub"><?php esc_html_e( 'Name the drill and tell the AI who it is for.', 'translation-drill' ); ?></p>
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
								<label class="tbtd-label" for="tbtd-class"><?php esc_html_e( 'Class', 'translation-drill' ); ?></label>
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

					<div class="tbtd-field">
						<label class="tbtd-label" for="tbtd-context"><?php esc_html_e( 'Context for the AI', 'translation-drill' ); ?> <span class="tbtd-optional"><?php esc_html_e( 'optional', 'translation-drill' ); ?></span></label>
						<input type="text" class="tbtd-input" id="tbtd-context" maxlength="500" data-role="context"
							placeholder="<?php esc_attr_e( 'e.g. School psychologists talking to colleagues and parents', 'translation-drill' ); ?>"
							value="<?php echo esc_attr( $drill ? $drill['context'] : '' ); ?>">
						<p class="tbtd-help"><?php esc_html_e( 'Helps the AI choose the right Polish words and register. Students never see it.', 'translation-drill' ); ?></p>
					</div>
				</div>
			</section>

			<section class="tbtd-stage" data-stage="2">
				<div class="tbtd-stage-card">
					<div class="tbtd-stage-head">
						<h2 class="tbtd-stage-title"><b>2</b> <?php esc_html_e( 'Sentences', 'translation-drill' ); ?></h2>
						<p class="tbtd-stage-sub"><?php esc_html_e( 'Write the English answers, highlight the key expressions, then translate. Check every Polish sentence before you publish.', 'translation-drill' ); ?></p>
					</div>

					<details class="tbtd-paste" data-role="paste-box">
						<summary><?php esc_html_e( 'Paste several sentences at once', 'translation-drill' ); ?></summary>
						<textarea class="tbtd-textarea" id="tbtd-paste" data-role="paste" rows="6"
							placeholder="<?php esc_attr_e( "One English sentence per line.\nYou can mark expressions now as [expression|1], or highlight them below.", 'translation-drill' ); ?>"></textarea>
						<button type="button" class="tbtd-btn tbtd-btn--soft" data-role="paste-add"><?php esc_html_e( 'Add these sentences', 'translation-drill' ); ?></button>
					</details>

					<ol class="tbtd-items" data-role="items"></ol>

					<button type="button" class="tbtd-btn tbtd-btn--ghost tbtd-add" data-role="add"><?php esc_html_e( '+ Add a sentence', 'translation-drill' ); ?></button>

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
			</section>

			<section class="tbtd-stage" data-stage="3">
				<div class="tbtd-stage-card">
					<div class="tbtd-stage-head">
						<h2 class="tbtd-stage-title"><b>3</b> <?php esc_html_e( 'Approve and share', 'translation-drill' ); ?></h2>
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
		</div>
		<?php
	}
}
