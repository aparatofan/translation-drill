<?php
/**
 * wp-admin overview: every drill on the site for administrators, a teacher's
 * own drills for anyone else holding the capability.
 *
 * Building and editing happen on the teacher page, never here. This screen
 * exists for oversight — who made what, is it live, open it, delete it.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_Admin {

	const SLUG = 'translation-drill';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_delete' ) );
	}

	public function register_menu() {
		if ( defined( 'TBT_HUB_SLUG' ) ) {
			add_submenu_page(
				TBT_HUB_SLUG,
				__( 'Translation Drill', 'translation-drill' ),
				__( 'Translation Drill', 'translation-drill' ),
				TBTD_CAP,
				self::SLUG,
				array( $this, 'render' )
			);
		} else {
			add_menu_page(
				__( 'Translation Drill', 'translation-drill' ),
				__( 'Translation Drill', 'translation-drill' ),
				TBTD_CAP,
				self::SLUG,
				array( $this, 'render' ),
				'dashicons-translation',
				58
			);
		}
	}

	/**
	 * Delete via a nonce-protected link on the list.
	 */
	public function handle_delete() {
		if ( ! isset( $_GET['page'], $_GET['action'], $_GET['drill'] ) || self::SLUG !== $_GET['page'] || 'delete' !== $_GET['action'] ) {
			return;
		}

		$drill_id = absint( $_GET['drill'] );
		check_admin_referer( 'tbtd_delete_' . $drill_id );

		$drill = TBTD_DB::get_drill( $drill_id );
		$may   = $drill && (
			TBTD_Capabilities::user_can_manage_all()
			|| ( TBTD_Capabilities::user_can_manage() && (int) $drill->owner_id === get_current_user_id() )
		);
		if ( ! $may ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'translation-drill' ) );
		}

		TBTD_DB::delete_drill( $drill_id );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render() {
		$all    = TBTD_Capabilities::user_can_manage_all();
		$drills = $all ? TBTD_DB::all_drills() : TBTD_DB::drills_for_owner( get_current_user_id() );
		$player = TBTD_Urls::player_page();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Translation Drill', 'translation-drill' ); ?></h1>
			<?php $new = TBTD_Urls::new_url(); ?>
			<?php if ( '' !== $new && (int) get_option( 'tbtd_builder_page_id', 0 ) > 0 ) : ?>
				<a href="<?php echo esc_url( $new ); ?>" class="page-title-action"><?php esc_html_e( 'Create a drill', 'translation-drill' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Drill deleted.', 'translation-drill' ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' === $player ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'No student page is set yet, so drills have no links. Create a page with the [tbt_drill] shortcode and choose it under Translation Drill Settings.', 'translation-drill' ); ?>
				</p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'translation-drill' ); ?></th>
						<?php if ( $all ) : ?><th><?php esc_html_e( 'Teacher', 'translation-drill' ); ?></th><?php endif; ?>
						<th><?php esc_html_e( 'Class', 'translation-drill' ); ?></th>
						<th><?php esc_html_e( 'Sentences', 'translation-drill' ); ?></th>
						<th><?php esc_html_e( 'Status', 'translation-drill' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'translation-drill' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'translation-drill' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $drills ) ) : ?>
					<tr><td colspan="<?php echo $all ? 7 : 6; ?>"><?php esc_html_e( 'No drills yet.', 'translation-drill' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $drills as $drill ) : ?>
					<?php
					$owner     = get_userdata( (int) $drill->owner_id );
					$link      = TBTD_Urls::drill_url( $drill->slug );
					$is_owner  = (int) $drill->owner_id === get_current_user_id();
					$edit      = $is_owner ? TBTD_Urls::edit_url( (int) $drill->id ) : '';
					$delete    = wp_nonce_url(
						add_query_arg( array( 'page' => self::SLUG, 'action' => 'delete', 'drill' => (int) $drill->id ), admin_url( 'admin.php' ) ),
						'tbtd_delete_' . (int) $drill->id
					);
					$class     = TBTD_Classes::class_title( (int) $drill->class_id );
					?>
					<tr>
						<td><strong><?php echo esc_html( $drill->title ); ?></strong></td>
						<?php if ( $all ) : ?><td><?php echo esc_html( $owner ? $owner->display_name : '—' ); ?></td><?php endif; ?>
						<td><?php echo esc_html( '' !== $class ? $class : '—' ); ?></td>
						<td><?php echo (int) $drill->item_count; ?></td>
						<td><?php echo TBTD_DB::STATUS_PUBLISHED === $drill->status ? esc_html__( 'Published', 'translation-drill' ) : esc_html__( 'Draft', 'translation-drill' ); ?></td>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $drill->updated_at ) ); ?></td>
						<td>
							<?php if ( '' !== $link ) : ?>
								<a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php echo TBTD_DB::STATUS_PUBLISHED === $drill->status ? esc_html__( 'Open', 'translation-drill' ) : esc_html__( 'Preview', 'translation-drill' ); ?></a> |
							<?php endif; ?>
							<?php if ( '' !== $edit && (int) get_option( 'tbtd_builder_page_id', 0 ) > 0 ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'translation-drill' ); ?></a> |
							<?php endif; ?>
							<a href="<?php echo esc_url( $delete ); ?>" style="color:#b32d2e" onclick="return confirm('<?php echo esc_js( __( 'Delete this drill and all its sentences? This cannot be undone.', 'translation-drill' ) ); ?>');"><?php esc_html_e( 'Delete', 'translation-drill' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
