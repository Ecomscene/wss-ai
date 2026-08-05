<?php
/**
 * Wat je bij Webshopschool kunt bijbestellen.
 *
 * DE LIJST STAAT NIET IN DEZE PLUGIN
 * Hij komt van onze server. Een prijs die verandert of een dienst die erbij
 * komt zou anders langs elke webshop moeten die dan eerst moet bijwerken, en
 * ondertussen zien klanten verschillende dingen. Nu is het één plek.
 *
 * WAT ER GEBEURT ALS IEMAND AANVRAAGT
 * Er gaat een bericht naar Webshopschool. Er wordt niets besteld, niets
 * afgerekend en niets aangezet: het is een aanvraag, en dat staat er ook zo bij.
 * Wat er precies is aangevraagd en tegen welke prijs zoekt de server zelf op in
 * zijn eigen lijst, zodat er in die mail staat wat wij aanbieden en niet wat een
 * browser toevallig meestuurde.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Upgrades {

	const SLUG = 'wss-ai-upgrades';

	public static function init() {
		add_action( 'admin_post_wss_ai_upgrade', array( __CLASS__, 'aanvragen' ) );
		/* De opmaak van het menu-item hoort op elk beheerscherm te staan, want
		   het menu staat overal. Vandaar hier en niet bij onze eigen stijlen. */
		add_action( 'admin_head', array( __CLASS__, 'menustijl' ) );
	}

	/**
	 * De titel van het menu-item.
	 *
	 * WordPress zet een submenu-titel ongefilterd in de link (zie menu-header.php:
	 * hij gaat alleen door wptexturize), dus HTML mag. Zo valt dit ene item op
	 * zonder dat het uit de toon valt bij de rest van wp-admin.
	 */
	public static function menutitel() {
		return '<span class="wss-ai-upgrade-item">' . esc_html__( 'Upgrades', 'wss-ai' ) . '</span>';
	}

	public static function menustijl() {
		echo '<style>#adminmenu .wss-ai-upgrade-item{color:#68de78;font-weight:700}'
			. '#adminmenu a:hover .wss-ai-upgrade-item,#adminmenu .current .wss-ai-upgrade-item{color:#8ef29b}</style>';
	}

	/* ---------------- de lijst ophalen ---------------- */

	/**
	 * De upgrades, kort onthouden.
	 *
	 * Kunnen we ze niet ophalen, dan tonen we dat en niet een lege lijst. "Er is
	 * niets" en "we konden het niet ophalen" zijn twee verschillende dingen, en
	 * het eerste tonen terwijl het tweede waar is verkoopt niets en verklaart
	 * niets.
	 *
	 * WAAROM EEN LEGE LIJST BIJNA NIET ONTHOUDEN WORDT
	 * Dit stond eerst een uur, ook als er niets terugkwam. Gevolg: een webshop
	 * die toevallig keek voordat er een upgrade bestond, bleef een uur lang "er
	 * staat niets klaar" tonen terwijl er net iets was aangemaakt. Juist die
	 * lege stand verandert het vaakst en is het goedkoopst opnieuw op te halen,
	 * dus die bewaren we maar een minuut. Een gevulde lijst tien minuten: lang
	 * genoeg om niet bij elke schermopening te bellen, kort genoeg om een
	 * prijswijziging dezelfde ochtend te zien.
	 */
	public static function lijst() {
		$onthouden = get_transient( 'wss_ai_upgrades' );
		if ( is_array( $onthouden ) ) {
			return $onthouden;
		}

		$uit = WSS_AI_Koppeling::vraag_get( '/upgrades' );
		if ( is_wp_error( $uit ) ) {
			return $uit;
		}

		$lijst = isset( $uit['upgrades'] ) && is_array( $uit['upgrades'] ) ? $uit['upgrades'] : array();
		set_transient(
			'wss_ai_upgrades',
			$lijst,
			$lijst ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS
		);
		return $lijst;
	}

	/* ---------------- aanvragen ---------------- */

	public static function aanvragen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_ai_upgrade' );

		$id      = isset( $_POST['upgrade'] ) ? sanitize_text_field( wp_unslash( $_POST['upgrade'] ) ) : '';
		$bericht = isset( $_POST['bericht'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bericht'] ) ) : '';
		$terug   = admin_url( 'admin.php?page=' . self::SLUG );

		if ( ! $id ) {
			wp_safe_redirect( add_query_arg( 'wss_ai_upgrade', 'leeg', $terug ) );
			exit;
		}

		$uit = WSS_AI_Koppeling::vraag(
			'/upgrades/' . rawurlencode( $id ) . '/aanvragen',
			array(
				'bericht'      => $bericht,
				'antwoordNaar' => get_option( 'admin_email' ),
				'naam'         => get_bloginfo( 'name' ),
			),
			45
		);

		if ( is_wp_error( $uit ) ) {
			set_transient( 'wss_ai_upgrade_fout_' . get_current_user_id(), $uit->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'wss_ai_upgrade', 'mislukt', $terug ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'wss_ai_upgrade', 'gelukt', $terug ) );
		exit;
	}

	/* ---------------- de pagina ---------------- */

	public static function pagina() {
		$stand = isset( $_GET['wss_ai_upgrade'] ) ? sanitize_key( wp_unslash( $_GET['wss_ai_upgrade'] ) ) : '';

		if ( 'gelukt' === $stand ) {
			echo '<div class="notice notice-success"><p>'
				. esc_html__( 'Je aanvraag is binnen. We nemen contact met je op; er is nog niets besteld of in rekening gebracht.', 'wss-ai' )
				. '</p></div>';
		} elseif ( 'mislukt' === $stand ) {
			$fout = get_transient( 'wss_ai_upgrade_fout_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>'
				. esc_html( $fout ? $fout : __( 'Het versturen lukte niet.', 'wss-ai' ) ) . ' '
				. esc_html__( 'Mail ons anders rechtstreeks op beheer@webshopschool.nl.', 'wss-ai' )
				. '</p></div>';
		}
		?>
		<p class="wss-ai-inleiding">
			<?php esc_html_e( 'Dingen die we voor je kunnen doen naast wat er in deze plugin zit. Je vraagt ze hier aan; wij nemen contact met je op. Er wordt niets besteld en niets in rekening gebracht door op een knop te drukken.', 'wss-ai' ); ?>
		</p>
		<?php

		$lijst = self::lijst();

		if ( is_wp_error( $lijst ) ) {
			/* Eerlijk zeggen dat we het niet konden ophalen. Een leeg scherm zou
			   lezen als "er is niets", en dat is iets anders. */
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'We konden de lijst even niet ophalen.', 'wss-ai' ) . ' '
				. esc_html( $lijst->get_error_message() )
				. '</p></div>';
			return;
		}

		if ( ! $lijst ) {
			echo '<div class="wss-ai-kaart"><p>'
				. esc_html__( 'Er staat op dit moment niets klaar. Heb je een wens? Stuur ons een bericht via Tickets & verzoeken.', 'wss-ai' )
				. '</p></div>';
			return;
		}

		echo '<div class="wss-ai-upgrades">';
		foreach ( $lijst as $u ) {
			$titel = isset( $u['titel'] ) ? (string) $u['titel'] : '';
			if ( ! $titel ) {
				continue;
			}
			$prijs = ( ! isset( $u['prijs'] ) || null === $u['prijs'] )
				? __( 'Op aanvraag', 'wss-ai' )
				: ( ! empty( $u['vanaf'] ) ? __( 'vanaf', 'wss-ai' ) . ' ' : '' )
					. '€ ' . number_format_i18n( (float) $u['prijs'], 2 );
			?>
			<?php
			/* sanitize_html_class laat alleen door wat een klassenaam mag zijn.
			   De server kiest uit een vaste lijst, maar wat hier in de pagina
			   van een klant belandt hoort niet op vertrouwen te rusten. */
			$icoon = ! empty( $u['icoon'] ) ? sanitize_html_class( (string) $u['icoon'] ) : 'star-filled';
			$id    = isset( $u['id'] ) ? (string) $u['id'] : '';
			?>
			<div class="wss-ai-upgrade">
				<div class="wss-ai-upgrade-kop">
					<span class="wss-ai-upgrade-icoon">
						<span class="dashicons dashicons-<?php echo esc_attr( $icoon ); ?>"></span>
					</span>
					<div class="wss-ai-upgrade-titels">
						<h2><?php echo esc_html( $titel ); ?></h2>
						<span class="wss-ai-prijs"><?php echo esc_html( $prijs ); ?></span>
					</div>
				</div>

				<?php if ( ! empty( $u['tekst'] ) ) : ?>
					<p class="wss-ai-upgrade-tekst"><?php echo nl2br( esc_html( (string) $u['tekst'] ) ); ?></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wss-ai-upgrade-voet">
					<input type="hidden" name="action" value="wss_ai_upgrade">
					<input type="hidden" name="upgrade" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'wss_ai_upgrade' ); ?>

					<label class="wss-ai-upgrade-veld" for="bericht-<?php echo esc_attr( $id ); ?>">
						<span><?php esc_html_e( 'Wil je er iets bij zeggen?', 'wss-ai' ); ?></span>
						<textarea id="bericht-<?php echo esc_attr( $id ); ?>" name="bericht" rows="2"
							placeholder="<?php esc_attr_e( 'Niet verplicht', 'wss-ai' ); ?>"></textarea>
					</label>

					<button type="submit" class="wss-ai-upgrade-knop">
						<?php echo esc_html( ! empty( $u['knop'] ) ? (string) $u['knop'] : __( 'Aanvragen', 'wss-ai' ) ); ?>
					</button>
				</form>
			</div>
			<?php
		}
		echo '</div>';
	}
}
