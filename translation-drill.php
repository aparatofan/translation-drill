<?php
/**
 * Plugin Name:       TBT Translation Drill
 * Description:       Polish → English sentence translation drills for live lessons. The teacher writes English sentences and highlights the key expressions, AI prepares the Polish version with matching highlights and a short language note, the teacher approves it, and logged-in students practise one sentence at a time with hints and a reveal.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * Text Domain:       translation-drill
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTD_VERSION', '1.0.0' );
define( 'TBTD_DB_VERSION', '1.0' );
define( 'TBTD_PLUGIN_FILE', __FILE__ );
define( 'TBTD_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTD_URL', plugin_dir_url( __FILE__ ) );

/**
 * The one capability that gates drill CRUD and AI translation. Site
 * configuration (API key, model, pages, role grants, AI limits) stays on
 * manage_options — see TBTD_Capabilities.
 */
define( 'TBTD_CAP', 'tbtd_manage' );

require_once TBTD_DIR . 'includes/class-tbtd-markup.php';
require_once TBTD_DIR . 'includes/class-tbtd-capabilities.php';
require_once TBTD_DIR . 'includes/class-tbtd-classes.php';
require_once TBTD_DIR . 'includes/class-tbtd-db.php';
require_once TBTD_DIR . 'includes/class-tbtd-api.php';
require_once TBTD_DIR . 'includes/class-tbtd-translator.php';
require_once TBTD_DIR . 'includes/class-tbtd-urls.php';
require_once TBTD_DIR . 'includes/class-tbtd-rest.php';
require_once TBTD_DIR . 'includes/class-tbtd-teacher.php';
require_once TBTD_DIR . 'includes/class-tbtd-player.php';
require_once TBTD_DIR . 'includes/class-tbtd-admin.php';
require_once TBTD_DIR . 'includes/class-tbtd-settings.php';

/**
 * Register this plugin on the TBT Hub Overview page.
 *
 * Registered at file scope so it loads regardless of admin context, matching
 * the pattern used by the other TBT plugins.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbtd_register_hub_item( $items ) {
	$items[] = array(
		'slug'        => 'translation-drill',
		'title'       => 'TBT Translation Drill',
		'description' => 'Polish → English sentence drills: highlighted expressions, hints, and a reveal with a language note.',
		'capability'  => TBTD_CAP,
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbtd_register_hub_item' );

/**
 * Create the tables and grant the management capability.
 */
function tbtd_activate() {
	TBTD_DB::activate();
	TBTD_Capabilities::add_caps();
	update_option( 'tbtd_caps_version', TBTD_Capabilities::CAPS_VERSION );
}
register_activation_hook( __FILE__, 'tbtd_activate' );

add_action( 'plugins_loaded', function () {
	TBTD_DB::maybe_upgrade();
	TBTD_Capabilities::maybe_add_caps();

	new TBTD_Rest();
	new TBTD_Teacher();
	new TBTD_Player();

	if ( is_admin() ) {
		new TBTD_Admin();
		new TBTD_Settings();
	}
} );
