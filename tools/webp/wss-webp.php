<?php
/**
 * Plugin Name: WSS WebP Converter
 * Description: Zet bestaande media om naar WebP, serveert WebP op de frontend en converteert nieuwe uploads. Te bedienen via wp-admin en via WP-CLI (wp wss-webp).
 * Version:     1.0.0
 * Author:      Ecomscene
 *
 * Herbruikbare versie van Joeys webp-snippet, bedoeld om bij elke klant identiek
 * uit te rollen als plugin in wp-content/plugins/wss-webp/wss-webp.php.
 *
 * Wat er anders is dan het snippet:
 *   - Prefix wss_webp_ in plaats van wic_, zodat hij naast het oude snippet kan
 *     staan zonder "cannot redeclare". De oude status (_wic_webp_status) wordt
 *     eenmalig overgenomen, dus werk dat al gedaan is wordt niet overgedaan.
 *   - WP-CLI: wp wss-webp status | convert | reset | serve | auto.
 *     Daarmee draait de bulkconversie headless, zonder browser.
 *   - Auto-convert bij upload gaat standaard NIET in het upload-request maar via
 *     een losse cron-taak; converteren in het request maakt uploaden traag.
 *   - Een .webp die groter is dan het origineel wordt weggegooid en de
 *     afbeelding krijgt status 'skipped'. Zo'n bestand serveren kost bandbreedte
 *     in plaats van dat het wat oplevert.
 *   - Instelbaar per site: WSS_WEBP_QUALITY en WSS_WEBP_PER_BATCH in wp-config.php.
 *
 * Originelen worden NOOIT verwijderd. De .webp komt naast het origineel te staan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/* ─── Instellingen ─────────────────────────────────────────────────── */

function wss_webp_quality() {
	$q = defined( 'WSS_WEBP_QUALITY' ) ? (int) WSS_WEBP_QUALITY : 80;
	return max( 1, min( 100, (int) apply_filters( 'wss_webp_quality', $q ) ) );
}

function wss_webp_per_batch() {
	$n = defined( 'WSS_WEBP_PER_BATCH' ) ? (int) WSS_WEBP_PER_BATCH : 3;
	return max( 1, (int) apply_filters( 'wss_webp_per_batch', $n ) );
}

function wss_webp_mimes() {
	return [ 'image/jpeg', 'image/png' ];
}

/* ─── Eenmalig: status van het oude snippet overnemen ──────────────── */

function wss_webp_migrate_legacy() {
	if ( get_option( 'wss_webp_legacy_migrated' ) === '1' ) {
		return;
	}

	global $wpdb;
	$wpdb->query(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT old.post_id, '_wss_webp_status', old.meta_value
		 FROM {$wpdb->postmeta} old
		 LEFT JOIN {$wpdb->postmeta} new
		        ON new.post_id = old.post_id AND new.meta_key = '_wss_webp_status'
		 WHERE old.meta_key = '_wic_webp_status'
		   AND new.meta_id IS NULL"
	);

	update_option( 'wss_webp_legacy_migrated', '1', false );
}
add_action( 'admin_init', 'wss_webp_migrate_legacy', 1 );

/* ─── Instellingen registreren ─────────────────────────────────────── */

add_action( 'admin_init', function () {
	foreach ( [ 'wss_webp_serve', 'wss_webp_auto' ] as $key ) {
		register_setting( 'wss_webp_settings_group', $key, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'no',
		] );
	}
} );

add_action( 'admin_menu', function () {
	add_options_page( 'WebP Converter', 'WebP Converter', 'manage_options', 'wss-webp', 'wss_webp_render_settings_page' );
} );

/* ─── Tellers ──────────────────────────────────────────────────────── */

function wss_webp_count_convertible() {
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_type = 'attachment'
		 AND post_mime_type IN ('image/jpeg','image/png')"
	);
}

function wss_webp_count_status( $status ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_wss_webp_status' AND meta_value = %s",
		$status
	) );
}

function wss_webp_count_pending() {
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wss_webp_status'
		 WHERE p.post_type = 'attachment'
		 AND p.post_mime_type IN ('image/jpeg','image/png')
		 AND pm.meta_id IS NULL"
	);
}

function wss_webp_pending_ids( $limit ) {
	global $wpdb;
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wss_webp_status'
		 WHERE p.post_type = 'attachment'
		 AND p.post_mime_type IN ('image/jpeg','image/png')
		 AND pm.meta_id IS NULL
		 ORDER BY p.ID ASC
		 LIMIT %d",
		(int) $limit
	) ) );
}

function wss_webp_totals() {
	return [
		'total'     => wss_webp_count_convertible(),
		'done'      => wss_webp_count_status( 'done' ),
		'failed'    => wss_webp_count_status( 'failed' ),
		'skipped'   => wss_webp_count_status( 'skipped' ),
		'remaining' => wss_webp_count_pending(),
	];
}

function wss_webp_label( $res ) {
	if ( $res === true )      return 'ok';
	if ( $res === 'exists' )  return 'bestond al';
	if ( $res === 'bigger' )  return 'groter dan origineel, overgeslagen';
	return 'mislukt';
}

/* ─── Kern: een bestand omzetten ───────────────────────────────────── */

function wss_webp_convert_file( $file_path ) {
	if ( ! $file_path || ! file_exists( $file_path ) ) {
		return false;
	}

	$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );
	if ( $webp_path === $file_path ) {
		return false;
	}

	if ( file_exists( $webp_path ) && filemtime( $webp_path ) >= filemtime( $file_path ) ) {
		return 'exists';
	}

	$check = wp_check_filetype( $file_path );
	$mime  = isset( $check['type'] ) ? $check['type'] : '';
	$made  = false;

	// GD eerst; dat zit op vrijwel elke host.
	if ( function_exists( 'imagewebp' ) ) {
		$image = null;

		if ( $mime === 'image/jpeg' && function_exists( 'imagecreatefromjpeg' ) ) {
			$image = @imagecreatefromjpeg( $file_path );
		} elseif ( $mime === 'image/png' && function_exists( 'imagecreatefrompng' ) ) {
			$image = @imagecreatefrompng( $file_path );
			if ( $image ) {
				@imagepalettetotruecolor( $image );
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
			}
		}

		if ( $image ) {
			$ok = @imagewebp( $image, $webp_path, wss_webp_quality() );
			imagedestroy( $image );

			// GD schrijft soms een kapot bestandje van 0 bytes.
			if ( $ok && file_exists( $webp_path ) && filesize( $webp_path ) > 0 ) {
				$made = true;
			} elseif ( file_exists( $webp_path ) && filesize( $webp_path ) === 0 ) {
				@unlink( $webp_path );
			}
		}
	}

	// Terugval: Imagick.
	if ( ! $made && class_exists( 'Imagick' ) ) {
		try {
			$im = new Imagick( $file_path );
			$im->setImageFormat( 'webp' );
			$im->setImageCompressionQuality( wss_webp_quality() );

			if ( $mime === 'image/png' ) {
				$im->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE );
			}

			$im->writeImage( $webp_path );
			$im->clear();
			$im->destroy();

			if ( file_exists( $webp_path ) && filesize( $webp_path ) > 0 ) {
				$made = true;
			}
		} catch ( Exception $e ) {
			// door naar de foutmelding hieronder
		}
	}

	if ( ! $made ) {
		return false;
	}

	// Groter dan het origineel? Dan heeft serveren geen zin.
	$src_size  = (int) filesize( $file_path );
	$webp_size = (int) filesize( $webp_path );
	if ( $src_size > 0 && $webp_size >= $src_size ) {
		@unlink( $webp_path );
		return 'bigger';
	}

	return true;
}

/* ─── Kern: attachment + alle formaten ─────────────────────────────── */

function wss_webp_convert_attachment( $attachment_id ) {
	$attachment_id = (int) $attachment_id;

	// Pessimistisch vooraf op 'failed'. Klapt het request eruit op een zware
	// afbeelding, dan blijft hij 'failed' en wordt hij overgeslagen in plaats
	// van dat de hele run in een kringetje blijft draaien.
	update_post_meta( $attachment_id, '_wss_webp_status', 'failed' );

	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return [ 'status' => 'failed', 'log' => [ 'bronbestand niet gevonden' ] ];
	}

	$log = [];
	$dir = dirname( $file );

	$res   = wss_webp_convert_file( $file );
	$log[] = basename( $file ) . ': ' . wss_webp_label( $res );

	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $info ) {
			if ( empty( $info['file'] ) ) {
				continue;
			}
			$r     = wss_webp_convert_file( $dir . '/' . $info['file'] );
			$log[] = $info['file'] . ': ' . wss_webp_label( $r );
		}
	}

	if ( $res === true || $res === 'exists' ) {
		$status = 'done';
	} elseif ( $res === 'bigger' ) {
		$status = 'skipped';
	} else {
		$status = 'failed';
	}

	update_post_meta( $attachment_id, '_wss_webp_status', $status );

	return [ 'status' => $status, 'log' => $log ];
}

/* ─── Een ronde draaien (gedeeld door AJAX en CLI) ─────────────────── */

function wss_webp_run_batch( $limit = null ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 );
	}
	@ini_set( 'memory_limit', '512M' );

	$limit = $limit ? (int) $limit : wss_webp_per_batch();
	$ids   = wss_webp_pending_ids( $limit );
	$lines = [];

	foreach ( $ids as $id ) {
		$r       = wss_webp_convert_attachment( $id );
		$lines[] = [
			'id'     => $id,
			'status' => $r['status'],
			'log'    => implode( ' | ', $r['log'] ),
		];
	}

	return [ 'handled' => count( $ids ), 'lines' => $lines ];
}

/* ─── Auto-convert bij upload ──────────────────────────────────────── */

add_filter( 'wp_generate_attachment_metadata', function ( $metadata, $attachment_id ) {
	if ( get_option( 'wss_webp_auto', 'no' ) !== 'yes' ) {
		return $metadata;
	}
	if ( ! in_array( get_post_mime_type( $attachment_id ), wss_webp_mimes(), true ) ) {
		return $metadata;
	}

	// Standaard buiten het upload-request om: converteren tijdens de upload
	// maakt het uploaden merkbaar traag, zeker bij variatieproducten.
	if ( apply_filters( 'wss_webp_convert_during_upload', false ) ) {
		wss_webp_convert_attachment( $attachment_id );
	} else {
		wp_schedule_single_event( time() + 30, 'wss_webp_convert_event', [ (int) $attachment_id ] );
	}

	return $metadata;
}, 99, 2 );

add_action( 'wss_webp_convert_event', function ( $attachment_id ) {
	wss_webp_convert_attachment( (int) $attachment_id );
} );

/* ─── Frontend: WebP serveren ──────────────────────────────────────── */

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_embed() ) {
		return;
	}
	if ( get_option( 'wss_webp_serve', 'no' ) !== 'yes' ) {
		return;
	}

	$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '';
	if ( strpos( $accept, 'image/webp' ) === false ) {
		return;
	}

	ob_start( 'wss_webp_rewrite_html' );
} );

function wss_webp_rewrite_html( $html ) {
	if ( empty( $html ) ) {
		return $html;
	}

	$upload_dir  = wp_get_upload_dir();
	$upload_url  = $upload_dir['baseurl'];
	$upload_path = $upload_dir['basedir'];

	// De negatieve lookahead zorgt dat .jpg/.png alleen op een echte
	// bestandsgrens matcht. Zonder dat werd de .jpg in een bestaande
	// "plaatje.jpg.webp" opnieuw gepakt en kreeg je .jpg.webp.webp.
	$pattern = '#(' . preg_quote( $upload_url, '#' ) . '/[^\s\'"\\)]+?)\.(jpe?g|png)(?![A-Za-z0-9.])#i';

	return preg_replace_callback( $pattern, function ( $m ) use ( $upload_url, $upload_path ) {
		if ( stripos( $m[0], '.webp' ) !== false ) {
			return $m[0];
		}

		$webp_url  = $m[1] . '.webp';
		$relative  = str_replace( $upload_url, '', $webp_url );
		$webp_file = $upload_path . $relative;

		return file_exists( $webp_file ) ? $webp_url : $m[0];
	}, $html );
}

/* ─── Kolom in de mediabibliotheek ─────────────────────────────────── */

add_filter( 'manage_media_columns', function ( $columns ) {
	$columns['wss_webp'] = 'WebP';
	return $columns;
} );

add_action( 'manage_media_custom_column', function ( $column, $post_id ) {
	if ( $column !== 'wss_webp' ) {
		return;
	}
	if ( ! in_array( get_post_mime_type( $post_id ), wss_webp_mimes(), true ) ) {
		echo '&mdash;';
		return;
	}

	$status = get_post_meta( $post_id, '_wss_webp_status', true );
	if ( $status === 'done' ) {
		echo '<span style="color:#00a32a;">omgezet</span>';
	} elseif ( $status === 'failed' ) {
		echo '<span style="color:#b32d2e;">mislukt</span>';
	} elseif ( $status === 'skipped' ) {
		echo '<span style="color:#996800;">overgeslagen</span>';
	} else {
		echo '<span style="color:#999;">nog niet</span>';
	}
}, 10, 2 );

/* ─── AJAX ─────────────────────────────────────────────────────────── */

add_action( 'wp_ajax_wss_webp_bulk', function () {
	check_ajax_referer( 'wss_webp_bulk' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Geen rechten' );
	}

	$batch  = wss_webp_run_batch();
	$totals = wss_webp_totals();
	$log    = [];

	foreach ( $batch['lines'] as $line ) {
		$mark  = $line['status'] === 'done' ? '' : ' [' . $line['status'] . ']';
		$log[] = '<strong>#' . $line['id'] . '</strong>' . $mark . ' - ' . esc_html( $line['log'] );
	}

	wp_send_json_success( array_merge( $totals, [
		'batch_count' => $batch['handled'],
		'klaar'       => ( $totals['remaining'] === 0 ),
		'log'         => $log,
	] ) );
} );

add_action( 'wp_ajax_wss_webp_reset', function () {
	check_ajax_referer( 'wss_webp_reset' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Geen rechten' );
	}

	wss_webp_reset_status();
	wp_send_json_success( true );
} );

function wss_webp_reset_status( $only_failed = false ) {
	global $wpdb;

	if ( $only_failed ) {
		return (int) $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_wss_webp_status', 'meta_value' => 'failed' ] );
	}

	$n = (int) $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_wss_webp_status' ] );
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_wic_webp_status' ] );
	update_option( 'wss_webp_legacy_migrated', '1', false );

	return $n;
}

/* ─── Instellingenpagina ───────────────────────────────────────────── */

function wss_webp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$serve  = get_option( 'wss_webp_serve', 'no' );
	$auto   = get_option( 'wss_webp_auto', 'no' );
	$totals = wss_webp_totals();
	?>
	<div class="wrap">
		<h1>WebP Converter</h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'wss_webp_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">WebP serveren op de site</th>
					<td>
						<label>
							<input type="checkbox" name="wss_webp_serve" value="yes" <?php checked( $serve, 'yes' ); ?>>
							Vervang afbeeldings-URL's door hun <code>.webp</code>-versie, alleen als dat bestand er is.
						</label>
						<p class="description">Aanzetten <strong>nadat</strong> de conversie is gedraaid.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Nieuwe uploads omzetten</th>
					<td>
						<label>
							<input type="checkbox" name="wss_webp_auto" value="yes" <?php checked( $auto, 'yes' ); ?>>
							Maak automatisch een <code>.webp</code> bij elke nieuwe JPG of PNG.
						</label>
						<p class="description">Gebeurt kort na de upload via cron, zodat het uploaden zelf snel blijft.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Opslaan' ); ?>
		</form>

		<hr>

		<h2>Bestaande afbeeldingen omzetten</h2>
		<p>
			Gevonden: <strong id="wss-webp-total"><?php echo (int) $totals['total']; ?></strong> JPG/PNG.
			Omgezet: <strong id="wss-webp-done"><?php echo (int) $totals['done']; ?></strong>.
			Overgeslagen: <strong id="wss-webp-skipped"><?php echo (int) $totals['skipped']; ?></strong>.
			Mislukt: <strong id="wss-webp-failed" style="color:#b32d2e;"><?php echo (int) $totals['failed']; ?></strong>.
		</p>
		<p class="description">Originelen worden nooit verwijderd. Een <code>.webp</code> die groter uitvalt dan het origineel wordt weggegooid; die afbeelding telt als overgeslagen.</p>

		<div id="wss-webp-wrap" style="display:none; margin:16px 0;">
			<div style="background:#e0e0e0; border-radius:4px; overflow:hidden; height:24px; max-width:600px;">
				<div id="wss-webp-bar" style="background:#2271b1; height:100%; width:0%; transition:width .3s; display:flex; align-items:center; justify-content:center; color:#fff; font-size:12px; font-weight:600;">0%</div>
			</div>
			<p id="wss-webp-status" style="margin-top:8px;">Bezig...</p>
		</div>

		<button type="button" class="button button-primary" id="wss-webp-start">Alles omzetten</button>
		<button type="button" class="button" id="wss-webp-stop" style="display:none; margin-left:8px;">Stoppen</button>
		<button type="button" class="button" id="wss-webp-reset" style="margin-left:8px;">Opnieuw inlezen</button>

		<div id="wss-webp-log" style="margin-top:16px; max-height:300px; overflow-y:auto; background:#f6f6f6; padding:10px; border:1px solid #ddd; border-radius:4px; display:none; font-family:monospace; font-size:12px; line-height:1.6;"></div>
	</div>

	<script>
	(function(){
		var startBtn = document.getElementById('wss-webp-start');
		var stopBtn  = document.getElementById('wss-webp-stop');
		var resetBtn = document.getElementById('wss-webp-reset');
		var wrap     = document.getElementById('wss-webp-wrap');
		var bar      = document.getElementById('wss-webp-bar');
		var statusEl = document.getElementById('wss-webp-status');
		var logEl    = document.getElementById('wss-webp-log');

		var nonce      = '<?php echo esc_js( wp_create_nonce( 'wss_webp_bulk' ) ); ?>';
		var resetNonce = '<?php echo esc_js( wp_create_nonce( 'wss_webp_reset' ) ); ?>';

		var running = false, stopped = false;

		function finish(){
			running = false;
			startBtn.disabled = false;
			resetBtn.disabled = false;
			stopBtn.style.display = 'none';
		}

		function setCount(id, val){
			var el = document.getElementById(id);
			if(el) el.textContent = val;
		}

		function run(retry){
			if(stopped){ finish(); return; }

			var data = new FormData();
			data.append('action', 'wss_webp_bulk');
			data.append('_wpnonce', nonce);

			fetch(ajaxurl, { method:'POST', body:data })
			.then(function(r){ return r.text(); })
			.then(function(text){
				var res;
				try { res = JSON.parse(text); }
				catch(e){
					// Waarschijnlijk een fatal op een zware afbeelding. Die staat al
					// op 'failed', dus een nieuwe poging slaat hem over.
					if(!retry){ statusEl.textContent = 'Foutje op deze ronde, ik ga voorbij die afbeelding verder...'; return run(true); }
					statusEl.textContent = 'Serverfout, gestopt. Druk opnieuw op starten om verder te gaan.';
					finish();
					return;
				}

				if(!res.success){
					statusEl.textContent = 'Fout: ' + (res.data || 'onbekend');
					finish();
					return;
				}

				var d = res.data;
				setCount('wss-webp-total', d.total);
				setCount('wss-webp-done', d.done);
				setCount('wss-webp-skipped', d.skipped);
				setCount('wss-webp-failed', d.failed);

				var behandeld = d.done + d.failed + d.skipped;
				var pct = d.total > 0 ? Math.round(behandeld / d.total * 100) : 100;
				bar.style.width = pct + '%';
				bar.textContent = pct + '%';
				statusEl.textContent = d.done + ' omgezet, ' + d.skipped + ' overgeslagen, ' + d.failed + ' mislukt, ' + d.remaining + ' te gaan...';

				if(d.log && d.log.length){
					d.log.forEach(function(entry){ logEl.innerHTML += entry + '<br>'; });
					logEl.scrollTop = logEl.scrollHeight;
				}

				if(d.klaar || d.batch_count === 0){
					statusEl.textContent = 'Klaar. ' + d.done + ' omgezet, ' + d.skipped + ' overgeslagen, ' + d.failed + ' mislukt van ' + d.total + '.';
					finish();
				} else {
					run(false);
				}
			})
			.catch(function(err){
				if(!retry){ statusEl.textContent = 'Verbinding haperde, nieuwe poging...'; return run(true); }
				statusEl.textContent = 'Fout: ' + err;
				finish();
			});
		}

		startBtn.addEventListener('click', function(){
			if(running) return;
			running = true; stopped = false;
			wrap.style.display = 'block';
			logEl.style.display = 'block';
			logEl.innerHTML = '';
			startBtn.disabled = true;
			resetBtn.disabled = true;
			stopBtn.style.display = 'inline-block';
			run(false);
		});

		stopBtn.addEventListener('click', function(){
			stopped = true;
			stopBtn.style.display = 'none';
			statusEl.textContent = 'Gestopt.';
			finish();
		});

		resetBtn.addEventListener('click', function(){
			if(running) return;
			if(!confirm('Status van alle afbeeldingen wissen zodat ze opnieuw worden ingelezen? Er worden geen bestanden verwijderd.')) return;
			resetBtn.disabled = true;
			var data = new FormData();
			data.append('action', 'wss_webp_reset');
			data.append('_wpnonce', resetNonce);
			fetch(ajaxurl, { method:'POST', body:data })
				.then(function(r){ return r.json(); })
				.then(function(){ location.reload(); })
				.catch(function(){ resetBtn.disabled = false; alert('Wissen mislukt.'); });
		});
	})();
	</script>
	<?php
}

/* ─── WP-CLI ───────────────────────────────────────────────────────── */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class WSS_WebP_CLI {

		/**
		 * Laat zien hoe het ervoor staat.
		 *
		 * ## EXAMPLES
		 *     wp wss-webp status
		 */
		public function status() {
			wss_webp_migrate_legacy();
			$t = wss_webp_totals();

			WP_CLI::log( 'JPG/PNG in de bibliotheek : ' . $t['total'] );
			WP_CLI::log( 'Omgezet                   : ' . $t['done'] );
			WP_CLI::log( 'Overgeslagen (niet kleiner): ' . $t['skipped'] );
			WP_CLI::log( 'Mislukt                   : ' . $t['failed'] );
			WP_CLI::log( 'Nog te doen               : ' . $t['remaining'] );
			WP_CLI::log( 'WebP serveren             : ' . get_option( 'wss_webp_serve', 'no' ) );
			WP_CLI::log( 'Nieuwe uploads omzetten   : ' . get_option( 'wss_webp_auto', 'no' ) );
			WP_CLI::log( 'GD imagewebp              : ' . ( function_exists( 'imagewebp' ) ? 'ja' : 'nee' ) );
			WP_CLI::log( 'Imagick                   : ' . ( class_exists( 'Imagick' ) ? 'ja' : 'nee' ) );
		}

		/**
		 * Zet afbeeldingen om naar WebP.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<n>]
		 * : Hooguit zoveel afbeeldingen. Zonder dit gaat hij door tot alles gehad is.
		 *
		 * [--retry-failed]
		 * : Zet de mislukte afbeeldingen eerst terug op onbehandeld en probeer ze opnieuw.
		 *
		 * ## EXAMPLES
		 *     wp wss-webp convert
		 *     wp wss-webp convert --limit=200
		 */
		public function convert( $args, $assoc ) {
			wss_webp_migrate_legacy();

			if ( ! function_exists( 'imagewebp' ) && ! class_exists( 'Imagick' ) ) {
				WP_CLI::error( 'Deze server kan geen WebP maken: geen GD met imagewebp en geen Imagick.' );
			}

			if ( ! empty( $assoc['retry-failed'] ) ) {
				$n = wss_webp_reset_status( true );
				WP_CLI::log( $n . ' mislukte afbeeldingen weer op onbehandeld gezet.' );
			}

			$limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0;
			$start = wss_webp_totals();
			$doel  = $limit > 0 ? min( $limit, $start['remaining'] ) : $start['remaining'];

			if ( $doel === 0 ) {
				WP_CLI::success( 'Niets te doen, alles is al behandeld.' );
				return;
			}

			WP_CLI::log( $doel . ' afbeeldingen te gaan (van ' . $start['total'] . ' in totaal).' );

			$gedaan = 0;
			$balk   = class_exists( 'WP_CLI\Utils\ProgressBar' ) || function_exists( 'WP_CLI\Utils\make_progress_bar' )
				? \WP_CLI\Utils\make_progress_bar( 'Omzetten', $doel )
				: null;

			while ( $gedaan < $doel ) {
				$ronde = min( wss_webp_per_batch(), $doel - $gedaan );
				$res   = wss_webp_run_batch( $ronde );

				if ( $res['handled'] === 0 ) {
					break;
				}

				foreach ( $res['lines'] as $line ) {
					if ( $line['status'] === 'failed' ) {
						WP_CLI::warning( '#' . $line['id'] . ' mislukt - ' . $line['log'] );
					}
					if ( $balk ) {
						$balk->tick();
					}
				}

				$gedaan += $res['handled'];
			}

			if ( $balk ) {
				$balk->finish();
			}

			$eind = wss_webp_totals();
			WP_CLI::success( sprintf(
				'%d behandeld. Nu: %d omgezet, %d overgeslagen, %d mislukt, %d te gaan.',
				$gedaan, $eind['done'], $eind['skipped'], $eind['failed'], $eind['remaining']
			) );
		}

		/**
		 * Wis de conversiestatus zodat alles opnieuw wordt ingelezen. Verwijdert geen bestanden.
		 *
		 * ## OPTIONS
		 *
		 * [--failed-only]
		 * : Alleen de mislukte afbeeldingen.
		 */
		public function reset( $args, $assoc ) {
			$only = ! empty( $assoc['failed-only'] );
			$n    = wss_webp_reset_status( $only );
			WP_CLI::success( $n . ' statusregels gewist.' . ( $only ? ' (alleen mislukte)' : '' ) );
		}

		/**
		 * Zet het serveren van WebP op de site aan of uit.
		 *
		 * ## OPTIONS
		 *
		 * <stand>
		 * : on of off
		 */
		public function serve( $args ) {
			$aan = isset( $args[0] ) && in_array( strtolower( $args[0] ), [ 'on', 'aan', 'yes', '1' ], true );
			update_option( 'wss_webp_serve', $aan ? 'yes' : 'no' );
			WP_CLI::success( 'WebP serveren staat nu ' . ( $aan ? 'aan' : 'uit' ) . '.' );
		}

		/**
		 * Zet het omzetten van nieuwe uploads aan of uit.
		 *
		 * ## OPTIONS
		 *
		 * <stand>
		 * : on of off
		 */
		public function auto( $args ) {
			$aan = isset( $args[0] ) && in_array( strtolower( $args[0] ), [ 'on', 'aan', 'yes', '1' ], true );
			update_option( 'wss_webp_auto', $aan ? 'yes' : 'no' );
			WP_CLI::success( 'Nieuwe uploads omzetten staat nu ' . ( $aan ? 'aan' : 'uit' ) . '.' );
		}
	}

	WP_CLI::add_command( 'wss-webp', 'WSS_WebP_CLI' );
}
