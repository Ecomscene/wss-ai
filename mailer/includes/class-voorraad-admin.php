<?php
/**
 * Het beheerscherm van "Weer op voorraad".
 *
 * WAAROM DIT EEN EIGEN KLASSE IS EN NIET IN WSFM_Flow_Admin_UI STAAT
 * Die klasse is al ruim duizend regels en bedient zeven schermen. Er een achtste
 * aan vastknopen betekent dat elke wijziging aan de voorraadkant een bestand
 * raakt waar ook de nieuwsbrieven, de flows en de sjablonen in zitten. Dit staat
 * er dus los van en hangt zich met add_submenu_page onder hetzelfde menu-item.
 * Eén onderdeel, één bestand, en een storing hier raakt de rest niet.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Voorraad_Admin {

	const SLUG = 'ws-flow-mailer-voorraad';

	/**
	 * Aanhaken.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_post_wsfm_save_voorraad', array( $this, 'opslaan' ) );
		add_action( 'admin_post_wsfm_voorraad_voorbeeld', array( $this, 'voorbeeld' ) );
	}

	/**
	 * Het menu-item, onder hetzelfde kopje als de flows.
	 *
	 * Positie 5, dus direct na Flows: dit is ook iets dat automatisch post
	 * verstuurt, en dan horen ze bij elkaar te staan in plaats van dat dit
	 * onderaan verdwijnt achter de sjablonen en het logboek.
	 */
	public function menu() {
		add_submenu_page(
			WSFM_Flow_Admin_UI::SLUG_DASHBOARD,
			__( 'Weer op voorraad', 'ws-flow-mailer' ),
			__( 'Weer op voorraad', 'ws-flow-mailer' ),
			WSFM_Flow_Admin_UI::CAPABILITY,
			self::SLUG,
			array( $this, 'scherm' ),
			5
		);
	}

	/**
	 * Mag deze gebruiker hier zijn?
	 */
	private function mag() {
		if ( ! current_user_can( WSFM_Flow_Admin_UI::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze pagina te bekijken.', 'ws-flow-mailer' ) );
		}
	}

	/**
	 * Het scherm.
	 */
	public function scherm() {
		$this->mag();

		$i          = WSFM_Voorraadmail::instellingen();
		$sjablonen  = WSFM_Newsletter_Render::templates();
		$lijsten    = class_exists( 'WSFM_Lijsten' ) ? WSFM_Lijsten::alles() : array();
		$wachtlijst = WSFM_Voorraadmail::wachtlijst();
		$laatste    = WSFM_Voorraadmail::laatste();
		$aantallen  = WSFM_Voorraadmail::aantallen();

		include WSFM_PLUGIN_DIR . 'admin/voorraad-page.php';
	}

	/**
	 * De instellingen opslaan.
	 */
	public function opslaan() {
		$this->mag();
		check_admin_referer( 'wsfm_save_voorraad' );

		WSFM_Voorraadmail::opslaan( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- veld voor veld opgeschoond in opslaan().

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					'wsfm-saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * De mail bekijken zoals hij verstuurd wordt.
	 *
	 * WAAROM DIT EEN GEWONE PAGINA IS EN GEEN PROEFMAIL
	 * Een proefmail versturen betekent dat er post de deur uit gaat om te kijken
	 * hoe post eruitziet, en op een shop waar de afzender nog niet rond is
	 * mislukt dat zonder dat je de mail hebt gezien. Dit toont precies dezelfde
	 * opbouw, met een echt product uit deze winkel, en verstuurt niets.
	 *
	 * Er wordt met opzet een UITVERKOCHT product gekozen als dat er is: dat is
	 * het product waar deze mail straks over gaat, dus dan ziet de winkelier
	 * meteen of zijn tekst klopt bij wat er in het plaatje staat.
	 */
	public function voorbeeld() {
		$this->mag();
		check_admin_referer( 'wsfm_voorraad_voorbeeld' );

		$product_id = $this->voorbeeldproduct();

		if ( ! $product_id ) {
			wp_die( esc_html__( 'Er staat nog geen product in je winkel, dus er is ook niets om de mail mee te laten zien.', 'ws-flow-mailer' ) );
		}

		/* Een rij die op een echte lijkt maar niet in de database staat. De
		   stoplink heeft een id nodig; die van een voorbeeld wijst nergens heen
		   en dat is precies goed, want een voorbeeld hoort niemand af te melden. */
		$nep = (object) array(
			'id'           => 0,
			'product_id'   => $product_id,
			'variation_id' => 0,
			'email'        => get_option( 'admin_email' ),
			'naam'         => '',
			'status'       => 'wacht',
			'created_at'   => current_time( 'mysql' ),
		);

		$mail = WSFM_Voorraadmail::mail_html(
			$nep,
			array(
				'first_name'      => '',
				'unsubscribe_url' => home_url( '/?voorbeeld-afmeldlink' ),
			)
		);

		if ( is_wp_error( $mail ) ) {
			wp_die( esc_html( $mail->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<p style="font:14px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;background:#fffbe5;border:1px solid #f0e2a0;padding:10px 14px;margin:0;">'
			. esc_html__( 'Voorbeeld. Dit is niet verstuurd.', 'ws-flow-mailer' ) . ' '
			. '<strong>' . esc_html__( 'Onderwerp:', 'ws-flow-mailer' ) . '</strong> ' . esc_html( $mail['subject'] )
			. '</p>';

		echo $mail['html_body']; // phpcs:ignore WordPress.Security.EscapingOutput -- dit IS de mail-HTML, opgebouwd door de opmaakmotor.
		exit;
	}

	/**
	 * Een product om de mail mee te laten zien: het liefst een uitverkochte.
	 *
	 * @return int Product-id, of 0.
	 */
	private function voorbeeldproduct() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		foreach ( array( 'outofstock', '' ) as $stand ) {
			$args = array(
				'limit'   => 1,
				'status'  => 'publish',
				'return'  => 'ids',
				'orderby' => 'date',
				'order'   => 'DESC',
			);

			if ( '' !== $stand ) {
				$args['stock_status'] = $stand;
			}

			$ids = wc_get_products( $args );
			if ( ! empty( $ids ) ) {
				return (int) $ids[0];
			}
		}

		return 0;
	}
}
