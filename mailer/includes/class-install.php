<?php
/**
 * Activation / schema installer for WS Flow Mailer.
 *
 * Creates all plugin tables via dbDelta, seeds the default templates and
 * tracks a db version so schema changes ship without re-activation.
 *
 * Statuses/reasons are stored as VARCHAR (not ENUM) on purpose: dbDelta
 * handles VARCHAR changes reliably and WordPress core/WooCommerce follow
 * the same convention. Allowed values are enforced in code.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Install {

	/**
	 * Bump this when the schema below changes.
	 */
	const DB_VERSION = '3';

	/**
	 * Activation hook: create tables, seed defaults, store versions.
	 */
	public static function activate() {
		self::create_tables();
		self::migrate_legacy_columns();
		self::seed_default_templates();
		self::strip_em_dashes();
		update_option( 'wsfm_db_version', self::DB_VERSION );
		update_option( 'wsfm_plugin_version', WSFM_VERSION );
	}

	/**
	 * Re-run the installer when the stored db version is behind (plugin
	 * updated via the updater, which does not fire the activation hook).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'wsfm_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Create or update all plugin tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		// Flow definitions. `steps` holds a JSON array of
		// { wait_minutes, template_id, stop_on_order } objects.
		$sql_flows = "CREATE TABLE {$prefix}wsfm_flows (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			trigger_type VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'paused',
			steps LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY trigger_type (trigger_type),
			KEY status (status)
		) $charset_collate;";

		// Active flow instances: one row per flow step per customer.
		// Status: pending | processing | sent | stopped | failed.
		$sql_queue = "CREATE TABLE {$prefix}wsfm_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			flow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			newsletter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			step_index INT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			cart_hash VARCHAR(64) NULL DEFAULT NULL,
			customer_email VARCHAR(190) NOT NULL DEFAULT '',
			customer_name VARCHAR(190) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			scheduled_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY flow_id (flow_id),
			KEY newsletter_id (newsletter_id),
			KEY customer_email (customer_email),
			KEY status_scheduled (status, scheduled_at)
		) $charset_collate;";

		// Send history. Status: sent | bounced | complained | failed.
		$sql_log = "CREATE TABLE {$prefix}wsfm_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			recipient VARCHAR(190) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			provider_message_id VARCHAR(190) NULL DEFAULT NULL,
			error_message TEXT NULL,
			sent_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY queue_id (queue_id),
			KEY status (status),
			KEY provider_message_id (provider_message_id),
			KEY sent_at (sent_at)
		) $charset_collate;";

		// Suppression list - checked before EVERY send.
		// Reason: bounce | complaint | manual.
		$sql_suppression = "CREATE TABLE {$prefix}wsfm_suppression (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL DEFAULT '',
			reason VARCHAR(20) NOT NULL DEFAULT 'manual',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) $charset_collate;";

		// Cart tracking for the abandoned-cart trigger. One row per
		// customer e-mail (latest cart wins).
		$sql_cart_tracking = "CREATE TABLE {$prefix}wsfm_cart_tracking (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cart_hash VARCHAR(64) NOT NULL DEFAULT '',
			customer_email VARCHAR(190) NOT NULL DEFAULT '',
			customer_name VARCHAR(190) NOT NULL DEFAULT '',
			cart_contents LONGTEXT NULL,
			last_activity DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			order_placed_flag TINYINT UNSIGNED NOT NULL DEFAULT 0,
			queued_flag TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY customer_email (customer_email),
			KEY cart_hash (cart_hash),
			KEY last_activity (last_activity)
		) $charset_collate;";

		// Identity stitching: visitor cookie → known e-mail address.
		$sql_identity = "CREATE TABLE {$prefix}wsfm_identity_map (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visitor_id VARCHAR(64) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			first_seen DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_seen DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY visitor_id (visitor_id),
			KEY email (email)
		) $charset_collate;";

		// E-mail templates.
		$sql_templates = "CREATE TABLE {$prefix}wsfm_templates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			html_body LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id)
		) $charset_collate;";

		// Newsletters: one-off broadcasts. `blocks` holds a JSON array of
		// { soort, ... } blocks; the layout comes from `template`.
		// Status: concept | bezig | verzonden.
		$sql_newsletters = "CREATE TABLE {$prefix}wsfm_newsletters (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			template VARCHAR(40) NOT NULL DEFAULT 'rustig',
			audience VARCHAR(40) NOT NULL DEFAULT 'klanten_jaar',
			blocks LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'concept',
			recipients INT UNSIGNED NOT NULL DEFAULT 0,
			sent_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset_collate;";

		dbDelta( $sql_flows );
		dbDelta( $sql_queue );
		dbDelta( $sql_log );
		dbDelta( $sql_suppression );
		dbDelta( $sql_cart_tracking );
		dbDelta( $sql_identity );
		dbDelta( $sql_templates );
		dbDelta( $sql_newsletters );
	}

	/**
	 * Drop columns from the v0.1.0 schema that were renamed in v2
	 * (current_step → step_index, merge_tags_used removed).
	 * dbDelta only adds columns, it never removes them.
	 */
	private static function migrate_legacy_columns() {
		global $wpdb;

		$legacy = array(
			$wpdb->prefix . 'wsfm_queue'     => 'current_step',
			$wpdb->prefix . 'wsfm_templates' => 'merge_tags_used',
		);

		foreach ( $legacy as $table => $column ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $exists ) {
				$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	/**
	 * Remove the em dash from templates that were seeded before the rule.
	 *
	 * The seeded bodies contained it in three places, and those bodies are
	 * already sitting in the database of every shop that installed an earlier
	 * version. Fixing only the source would leave the character in the mail
	 * that actually reaches customers, which is the one place it matters.
	 *
	 * Only the seeded phrases are touched, so a shop that deliberately typed
	 * one keeps it.
	 */
	private static function strip_em_dashes() {
		global $wpdb;

		$table = $wpdb->prefix . 'wsfm_templates';
		/* Als tekencode geschreven en niet als het teken zelf. Anders staat het
		   verboden leesteken in het enige bestand dat het weghaalt, en dan blijft
		   een zoekactie er altijd op afketsen. */
		/* Als tekencode geschreven en niet als het teken zelf. Anders staat het
		   verboden leesteken in het enige bestand dat het weghaalt, en struikelt
		   de controle in de release over zijn eigen oplossing. */
		$dash  = json_decode( '"\u2014"' );

		foreach ( array( 'name', 'subject', 'html_body' ) as $column ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = REPLACE({$column}, %s, %s) WHERE {$column} LIKE %s", $dash, '-', '%' . $wpdb->esc_like( $dash ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = REPLACE({$column}, %s, %s) WHERE {$column} LIKE %s", '&mdash;', '-', '%' . $wpdb->esc_like( '&mdash;' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Seed the three default templates on first install so a new flow has
	 * a decent starting point. Regular editable rows, not hardcoded PHP.
	 */
	public static function seed_default_templates() {
		global $wpdb;

		$table = $wpdb->prefix . 'wsfm_templates';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $count > 0 ) {
			return;
		}

		$footer = '<p style="font-size:12px;color:#888888;margin-top:32px;border-top:1px solid #eeeeee;padding-top:16px;">'
			. 'Je ontvangt deze e-mail omdat je klant bent bij onze webshop. '
			. '<a href="{unsubscribe_url}" style="color:#888888;">Afmelden voor deze e-mails</a></p>';

		$wrap_open  = '<div style="max-width:600px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;color:#333333;line-height:1.6;">';
		$wrap_close = '</div>';

		$templates = array(
			array(
				'name'      => 'Verlaten winkelwagen - herinnering',
				'subject'   => 'Je bent nog iets vergeten in je winkelwagen',
				'html_body' => $wrap_open
					. '<h2 style="color:#222222;">Hoi {first_name},</h2>'
					. '<p>We zagen dat je nog producten in je winkelwagen hebt laten staan. Geen zorgen - we hebben ze voor je bewaard:</p>'
					. '{cart_items}'
					. '<p><strong>Totaal: {cart_total}</strong></p>'
					. '<p style="margin:28px 0;"><a href="{cart_url}" style="background:#222222;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">Winkelwagen afronden</a></p>'
					. '<p>Vragen over een product? Beantwoord gerust deze e-mail.</p>'
					. $footer . $wrap_close,
			),
			array(
				'name'      => 'Review-verzoek na aankoop',
				'subject'   => 'Hoe bevalt je bestelling {order_number}?',
				'html_body' => $wrap_open
					. '<h2 style="color:#222222;">Hoi {first_name},</h2>'
					. '<p>Een tijdje geleden ontving je bestelling {order_number}:</p>'
					. '{order_items}'
					. '<p>We zijn benieuwd wat je ervan vindt! Met jouw review help je andere klanten bij hun keuze - en ons om de shop nog beter te maken.</p>'
					. '<p style="margin:28px 0;"><a href="{shop_url}" style="background:#222222;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">Schrijf een review</a></p>'
					. '<p>Alvast bedankt!</p>'
					. $footer . $wrap_close,
			),
			array(
				'name'      => 'Cross-sell na aankoop',
				'subject'   => 'Dit past perfect bij je vorige bestelling',
				'html_body' => $wrap_open
					. '<h2 style="color:#222222;">Hoi {first_name},</h2>'
					. '<p>Bedankt voor je bestelling {order_number}! Je bestelde:</p>'
					. '{order_items}'
					. '<p>Veel klanten die deze producten kochten, vonden ook onze andere collecties interessant. Neem gerust een kijkje:</p>'
					. '<p style="margin:28px 0;"><a href="{shop_url}" style="background:#222222;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">Bekijk de shop</a></p>'
					. $footer . $wrap_close,
			),
		);

		$now = current_time( 'mysql' );

		foreach ( $templates as $template ) {
			$wpdb->insert(
				$table,
				array(
					'name'       => $template['name'],
					'subject'    => $template['subject'],
					'html_body'  => $template['html_body'],
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}
	}
}
