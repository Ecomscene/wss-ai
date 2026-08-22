<?php
/**
 * Versturen via Webshopschool in plaats van rechtstreeks naar Amazon.
 *
 * WAAROM DEZE ER IS
 * De andere providers hebben sleutels nodig die in de database van de klant
 * staan. Dat betekende een AWS-gebruiker per webshop aanmaken, en die sleutel
 * per webshop kwijt zijn zodra zo'n site gehackt wordt. Het geverifieerde
 * domein staat toch al in het account van Webshopschool, dus hoort het
 * versturen daar ook. Deze shop heeft nu toestemming nodig, geen gereedschap.
 *
 * WAT DIT NIET DOET
 * Niets rekenen, niets ondertekenen, niets onthouden. Het is een doorgeefluik
 * naar dezelfde server die de plugin toch al belt. Precies de bedoeling: alles
 * wat geld kost of stuk kan hoort daar te staan en niet hier.
 *
 * DE AFMELDLIJST BLIJFT WERKEN
 * Amazon houdt zelf bij welke adressen ooit hard zijn gebounced en weigert die.
 * Die weigering is het enige bouncesignaal dat we zonder SNS krijgen, dus als
 * hij binnenkomt zetten we dat adres meteen op de afmeldlijst van deze shop.
 * Anders zou de wachtrij het adres blijven proberen en loopt het bouncecijfer
 * op zonder dat iemand het ziet.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Provider_Hub implements WSFM_Mail_Provider {

	/** @var string */
	private $last_error = '';

	/**
	 * Neemt Webshopschool het versturen voor deze webshop over?
	 *
	 * Alleen als het afzenderdomein daar echt rond is. Zolang dat niet zo is
	 * blijft alles staan zoals het stond, inclusief een eigen SES-instelling als
	 * die er is. Een halve overname is erger dan geen overname.
	 *
	 * @return bool
	 */
	public static function beschikbaar() {
		if ( ! class_exists( 'WSS_AI_Koppeling' ) ) {
			return false;
		}
		$a = WSS_AI_Koppeling::afzender();
		return isset( $a['stand'] ) && 'gelukt' === $a['stand'];
	}

	/**
	 * Het adres waar de mail vandaan komt, voor op het scherm.
	 *
	 * @return string Leeg als we het niet weten.
	 */
	public static function afzenderadres() {
		if ( ! class_exists( 'WSS_AI_Koppeling' ) ) {
			return '';
		}
		$a = WSS_AI_Koppeling::afzender();
		if ( ! empty( $a['afzender'] ) ) {
			return $a['afzender'];
		}
		return empty( $a['domein'] ) ? '' : 'nieuwsbrief@' . $a['domein'];
	}

	/**
	 * Eén mail versturen.
	 *
	 * @param string $to         Ontvanger.
	 * @param string $subject    Onderwerp.
	 * @param string $html_body  Inhoud.
	 * @param array  $merge_data Merge-gegevens (hier niet gebruikt).
	 * @return WSFM_Send_Result
	 */
	public function send( $to, $subject, $html_body, array $merge_data ) {
		/* Ruim de tijd: dit is één HTTPS-verzoek naar onze eigen server, die er
		   daarna nog een naar Amazon doet. Dertig seconden is genoeg voor allebei
		   en kort genoeg om de wachtrij niet op te houden als er iets hangt. */
		$uit = WSS_AI_Koppeling::vraag(
			'/mail',
			array(
				'naar'      => $to,
				'onderwerp' => $subject,
				'html'      => $html_body,
				/* Antwoorden horen bij de winkelier te belanden en niet bij ons. */
				'antwoordNaar' => get_option( 'admin_email' ),
			),
			30
		);

		if ( is_wp_error( $uit ) ) {
			$this->last_error = $uit->get_error_message();

			$extra = $uit->get_error_data();
			if ( is_array( $extra ) && ! empty( $extra['onderdrukt'] ) && class_exists( 'WSFM_Suppression' ) ) {
				/* Amazon kent dit adres als een harde bounce. Dat is een feit dat
				   we hier vasthouden, anders blijft de wachtrij het proberen. */
				WSFM_Suppression::add( $to, 'bounce' );
			}

			return new WSFM_Send_Result( false, '', $this->last_error );
		}

		return new WSFM_Send_Result( true, isset( $uit['messageId'] ) ? (string) $uit['messageId'] : '', '' );
	}

	/**
	 * Werkt de koppeling?
	 *
	 * Geen proefmail vanuit hier: die verstuurt het scherm er zelf achteraan.
	 * Dit controleert alleen of Webshopschool namens dit domein mág versturen,
	 * en dat is precies wat er mis kan zijn.
	 *
	 * @return bool
	 */
	public function test_connection() {
		if ( self::beschikbaar() ) {
			return true;
		}
		$this->last_error = __( 'Het afzenderadres van je webshop is nog niet klaar. Webshopschool regelt dat voor je.', 'ws-flow-mailer' );
		return false;
	}

	/**
	 * De laatste fout, voor op het scherm.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}
}
