<?php
/**
 * Tables, schema upgrades and every query the plugin runs.
 *
 * Two tables:
 *   {prefix}tbtd_drills — one row per drill: owner, slug, title, status,
 *                         optional class/lesson, and the context line the
 *                         teacher gave the AI.
 *   {prefix}tbtd_items  — one row per sentence: English and Polish markup
 *                         (see TBTD_Markup) and the language note.
 *
 * Items are saved as a whole set: a save replaces every item of the drill in
 * one transaction-shaped pass, so the order the teacher sees is the order
 * that is stored and there is no per-row reconciliation to get wrong.
 */

defined( 'ABSPATH' ) || exit;

class TBTD_DB {

	const STATUS_DRAFT     = 'draft';
	const STATUS_PUBLISHED = 'published';

	public static function drills_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtd_drills';
	}

	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtd_items';
	}

	/**
	 * Create or upgrade both tables.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$drills  = self::drills_table();
		$items   = self::items_table();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$drills} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(32) NOT NULL,
				owner_id bigint(20) unsigned NOT NULL,
				title varchar(200) NOT NULL DEFAULT '',
				context varchar(500) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'draft',
				class_id bigint(20) unsigned NULL DEFAULT NULL,
				lesson_id bigint(20) unsigned NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				published_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY owner_id (owner_id),
				KEY class_id (class_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				drill_id bigint(20) unsigned NOT NULL,
				position int(11) NOT NULL DEFAULT 0,
				en text NOT NULL,
				pl text NOT NULL,
				note text NOT NULL,
				PRIMARY KEY  (id),
				KEY drill_id (drill_id)
			) {$charset};"
		);

		update_option( 'tbtd_db_version', TBTD_DB_VERSION );
	}

	/**
	 * Run activate() when the stored schema version is behind. Activation
	 * hooks do not fire on an update of an already active plugin.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'tbtd_db_version' ) !== TBTD_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * One drill by ID.
	 *
	 * @param int $id Drill ID.
	 * @return object|null
	 */
	public static function get_drill( $id ) {
		global $wpdb;
		$table = self::drills_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * One drill by ID, only if this user owns it. The frontend never reads a
	 * drill any other way: capability alone does not open someone else's.
	 *
	 * @param int $id      Drill ID.
	 * @param int $user_id Owner.
	 * @return object|null
	 */
	public static function get_owned_drill( $id, $user_id ) {
		global $wpdb;
		$table = self::drills_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND owner_id = %d", (int) $id, (int) $user_id )
		);
	}

	/**
	 * One drill by slug.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_drill_by_slug( $slug ) {
		global $wpdb;
		$table = self::drills_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ) );
	}

	/**
	 * A drill's sentences in order.
	 *
	 * @param int $drill_id Drill ID.
	 * @return object[]
	 */
	public static function get_items( $drill_id ) {
		global $wpdb;
		$table = self::items_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE drill_id = %d ORDER BY position ASC, id ASC", (int) $drill_id )
		);
	}

	/**
	 * Every drill a user owns, newest change first, with its sentence count.
	 *
	 * @param int $user_id Owner.
	 * @return object[]
	 */
	public static function drills_for_owner( $user_id ) {
		global $wpdb;
		$drills = self::drills_table();
		$items  = self::items_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, COUNT(i.id) AS item_count
				FROM {$drills} d
				LEFT JOIN {$items} i ON i.drill_id = d.id
				WHERE d.owner_id = %d
				GROUP BY d.id
				ORDER BY d.updated_at DESC, d.id DESC",
				(int) $user_id
			)
		);
	}

	/**
	 * A class's published drills, newest publish first, with sentence counts,
	 * for the TBT Notes class panel. Published only — enforced here, in SQL,
	 * so a draft cannot reach a student even if something downstream slips.
	 *
	 * @param int $class_id Class.
	 * @return object[]
	 */
	public static function published_drills_for_class( $class_id ) {
		global $wpdb;
		$drills = self::drills_table();
		$items  = self::items_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, COUNT(i.id) AS item_count
				FROM {$drills} d
				LEFT JOIN {$items} i ON i.drill_id = d.id
				WHERE d.class_id = %d AND d.status = %s
				GROUP BY d.id
				ORDER BY d.published_at DESC, d.id DESC",
				(int) $class_id,
				self::STATUS_PUBLISHED
			)
		);
	}

	/**
	 * Every drill on the site, for the wp-admin oversight list.
	 *
	 * @return object[]
	 */
	public static function all_drills() {
		global $wpdb;
		$drills = self::drills_table();
		$items  = self::items_table();
		return (array) $wpdb->get_results(
			"SELECT d.*, COUNT(i.id) AS item_count
			FROM {$drills} d
			LEFT JOIN {$items} i ON i.drill_id = d.id
			GROUP BY d.id
			ORDER BY d.updated_at DESC, d.id DESC"
		);
	}

	/**
	 * Create a drill row. The slug is minted here and never changes after,
	 * so a link or QR code shared once keeps working through every edit.
	 *
	 * @param int   $owner_id Owner.
	 * @param array $fields   title, context, status, class_id, lesson_id.
	 * @return int|false New ID.
	 */
	public static function insert_drill( $owner_id, array $fields ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array(
			'slug'         => self::unique_slug(),
			'owner_id'     => (int) $owner_id,
			'title'        => (string) $fields['title'],
			'context'      => (string) $fields['context'],
			'status'       => (string) $fields['status'],
			'class_id'     => $fields['class_id'],
			'lesson_id'    => $fields['lesson_id'],
			'created_at'   => $now,
			'updated_at'   => $now,
			'published_at' => self::STATUS_PUBLISHED === $fields['status'] ? $now : null,
		);
		$ok = $wpdb->insert( self::drills_table(), $data );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a drill row. published_at records the first publish only.
	 *
	 * @param object $drill  Existing row.
	 * @param array  $fields title, context, status, class_id, lesson_id.
	 * @return bool
	 */
	public static function update_drill( $drill, array $fields ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array(
			'title'      => (string) $fields['title'],
			'context'    => (string) $fields['context'],
			'status'     => (string) $fields['status'],
			'class_id'   => $fields['class_id'],
			'lesson_id'  => $fields['lesson_id'],
			'updated_at' => $now,
		);
		if ( self::STATUS_PUBLISHED === $fields['status'] && empty( $drill->published_at ) ) {
			$data['published_at'] = $now;
		}
		return false !== $wpdb->update( self::drills_table(), $data, array( 'id' => (int) $drill->id ) );
	}

	/**
	 * Replace every sentence of a drill with the given list.
	 *
	 * @param int   $drill_id Drill ID.
	 * @param array $items    Each with en, pl, note — already sanitised.
	 * @return bool
	 */
	public static function replace_items( $drill_id, array $items ) {
		global $wpdb;
		$table = self::items_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE drill_id = %d", (int) $drill_id ) );

		$ok = true;
		foreach ( array_values( $items ) as $position => $item ) {
			$ok = false !== $wpdb->insert(
				$table,
				array(
					'drill_id' => (int) $drill_id,
					'position' => (int) $position,
					'en'       => (string) $item['en'],
					'pl'       => (string) $item['pl'],
					'note'     => (string) $item['note'],
				)
			) && $ok;
		}
		return $ok;
	}

	/**
	 * Delete a drill and its sentences.
	 *
	 * @param int $drill_id Drill ID.
	 */
	public static function delete_drill( $drill_id ) {
		global $wpdb;
		$wpdb->delete( self::items_table(), array( 'drill_id' => (int) $drill_id ) );
		$wpdb->delete( self::drills_table(), array( 'id' => (int) $drill_id ) );
	}

	/**
	 * A slug no drill uses yet.
	 *
	 * @return string
	 */
	private static function unique_slug() {
		do {
			$slug = TBTD_Markup::generate_slug();
		} while ( self::get_drill_by_slug( $slug ) );
		return $slug;
	}
}
