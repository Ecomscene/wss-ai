<?php
/**
 * Template engine: renders stored templates by replacing merge tags with
 * real data from a context array.
 *
 * Supported tags: {first_name}, {cart_items}, {cart_total}, {cart_url},
 * {order_number}, {order_total}, {order_items}, {coupon_code},
 * {unsubscribe_url}, {shop_name}, {shop_url}.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Template_Engine {

	/** Plek in de voorraadmelding waar het woord voor de wagen komt (zie render_string). */
	const WAGENWOORD = '%%wsfm_wagenwoord%%';

	/**
	 * Supported merge tags with a short Dutch description (used by the
	 * cheatsheet in the template editor).
	 *
	 * @return array tag => description
	 */
	public static function supported_tags() {
		return array(
			'{first_name}'      => __( 'Voornaam van de klant', 'ws-flow-mailer' ),
			'{cart_items}'      => __( 'Winkelwagen-producten als lijst (HTML)', 'ws-flow-mailer' ),
			'{cart_total}'      => __( 'Winkelwagen-totaal incl. valutasymbool', 'ws-flow-mailer' ),
			'{cart_url}'        => __( 'Link naar de winkelwagen. In een verlaten-winkelwagenmail zet die de producten weer voor de klant klaar.', 'ws-flow-mailer' ),
			'{order_number}'    => __( 'Ordernummer', 'ws-flow-mailer' ),
			'{order_total}'     => __( 'Ordertotaal incl. valutasymbool', 'ws-flow-mailer' ),
			'{order_items}'     => __( 'Bestelde producten als lijst (HTML)', 'ws-flow-mailer' ),
			'{coupon_code}'     => __( 'De kortingscode van de inschrijving. Alleen gevuld bij een flow op "Nieuwe inschrijving op de lijst", en alleen als de popup een code heeft uitgegeven.', 'ws-flow-mailer' ),
			'{unsubscribe_url}' => __( 'Afmeldlink (verplicht in elke template)', 'ws-flow-mailer' ),
			'{shop_name}'       => __( 'Naam van de webshop', 'ws-flow-mailer' ),
			'{shop_url}'        => __( 'Link naar de webshop', 'ws-flow-mailer' ),
			'{sender_info}'     => __( 'Naam en adres van de shop op een regel (afzendergegevens)', 'ws-flow-mailer' ),
		);
	}

	/**
	 * Fetch a template row.
	 *
	 * @param int $template_id Template id.
	 * @return object|null
	 */
	public static function get_template( $template_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wsfm_templates';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Render a stored template with the given context.
	 *
	 * @param int   $template_id Template id.
	 * @param array $context     Merge data (keys without braces).
	 * @return array|WP_Error { subject, html_body }
	 */
	public static function render( $template_id, array $context ) {
		$template = self::get_template( $template_id );

		if ( ! $template ) {
			return new WP_Error( 'wsfm_template_missing', sprintf( __( 'Template %d bestaat niet (meer).', 'ws-flow-mailer' ), $template_id ) );
		}

		return self::render_string( $template->subject, $template->html_body, $context );
	}

	/**
	 * Replace merge tags in a subject + body pair.
	 *
	 * Every supported tag is always replaced - missing context values
	 * become an empty string so no raw {tags} ever reach a customer.
	 *
	 * @param string $subject Raw subject.
	 * @param string $body    Raw HTML body.
	 * @param array  $context Merge data (keys without braces).
	 * @return array { subject, html_body }
	 */
	public static function render_string( $subject, $body, array $context ) {
		$subject = (string) $subject;
		$body    = (string) $body;
		$context = array_merge( self::default_context(), $context );

		/* Tags uit Laposta, Mailchimp of Brevo eerst naar de onze omzetten. Wie
		   een mail uit zo'n pakket overneemt heeft ze erin staan, en onvertaald
		   belandt %UNSUBSCRIBELINK% letterlijk in de inbox van zijn klanten. */
		if ( class_exists( 'WSFM_Eigen_Html' ) ) {
			$subject = WSFM_Eigen_Html::vertaal_tags( $subject );
			$body    = WSFM_Eigen_Html::vertaal_tags( $body );
		}

		$search          = array();
		$replace         = array();
		$subject_replace = array();
		$html            = array( 'cart_items', 'order_items' ); // Tags whose value is HTML.

		foreach ( array_keys( self::supported_tags() ) as $tag ) {
			$key       = trim( $tag, '{}' );
			$value     = isset( $context[ $key ] ) ? (string) $context[ $key ] : '';
			$search[]  = $tag;
			$replace[] = $value;

			// Subjects are plain text: strip HTML values there.
			$subject_replace[] = in_array( $key, $html, true ) ? wp_strip_all_tags( $value ) : $value;
		}

		/* De voorraadmelding in {cart_items} gebruikt het woord van de mail
		   zelf: een shop die in zijn mail "winkelmandje" schrijft krijgt geen
		   regel over een "winkelwagen" ertussen. */
		$woord             = ( false !== stripos( $body, 'winkelmandje' ) ) ? 'winkelmandje' : 'winkelwagen';
		$search[]          = self::WAGENWOORD;
		$replace[]         = $woord;
		$subject_replace[] = $woord;

		return array(
			'subject'   => str_replace( $search, $subject_replace, $subject ),
			'html_body' => str_replace( $search, $replace, $body ),
		);
	}

	/**
	 * Context values that are always available.
	 *
	 * @return array
	 */
	public static function default_context() {
		return array(
			'shop_name'   => get_bloginfo( 'name' ),
			'shop_url'    => self::winkel_url(),
			'sender_info' => self::afzenderregel(),
		);
	}

	/**
	 * Naam en adres van de shop op een regel.
	 *
	 * Aangeleverde nieuwsbrieven uit andere pakketten hebben hier een tag voor
	 * staan, omdat een postadres in de voet hoort: het onderscheidt een
	 * nieuwsbrief van spam en filters kijken ernaar. WooCommerce weet het adres
	 * al, dus we vragen het de klant niet nog een keer.
	 *
	 * @return string
	 */
	public static function afzenderregel() {
		$delen = array( get_bloginfo( 'name' ) );

		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
			$landen = WC()->countries;

			foreach ( array( 'get_base_address', 'get_base_postcode', 'get_base_city' ) as $vraag ) {
				if ( method_exists( $landen, $vraag ) ) {
					$waarde = $landen->$vraag();
					if ( is_string( $waarde ) && '' !== trim( $waarde ) ) {
						$delen[] = trim( $waarde );
					}
				}
			}
		}

		return implode( ', ', array_filter( $delen ) );
	}

	/**
	 * Build the HTML for an items array.
	 *
	 * Items that carry a product_id (winkelwagen-regels uit WSFM_Cart_Tracking)
	 * worden een tabel met productfoto, naam, aantal en prijs. Een herinnering
	 * aan een winkelwagen zonder foto's is een lijstje tekst, en juist de foto
	 * is wat iemand terug laat komen. Items zonder product_id (orderregels)
	 * houden de eenvoudige lijst.
	 *
	 * De prijs komt dan uit het product zelf, op de manier waarop de winkel
	 * prijzen toont (incl. of excl. btw). De opgeslagen line_total is altijd
	 * excl. btw en gaf in de mail dus een te lage prijs bij een shop die
	 * prijzen incl. btw toont.
	 *
	 * @param array $items Arrays with keys: name, qty, price (formatted, optional), product_id, variation_id (optional).
	 * @return string
	 */
	public static function items_html( array $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$met_product = false;
		foreach ( $items as $item ) {
			if ( ! empty( $item['product_id'] ) ) {
				$met_product = true;
				break;
			}
		}

		$met_beeld = false;
foreach ( $items as $item ) {
	if ( ! empty( $item['image'] ) ) {
		$met_beeld = true;
		break;
	}
}

if ( ( $met_product && function_exists( 'wc_get_product' ) ) || $met_beeld ) {
			return self::items_table_html( $items );
		}

		$out = '<ul style="padding-left:20px;margin:16px 0;">';
		foreach ( $items as $item ) {
			$name  = isset( $item['name'] ) ? $item['name'] : '';
			$qty   = isset( $item['qty'] ) ? (int) $item['qty'] : 1;
			$price = isset( $item['price'] ) ? $item['price'] : '';

			$out .= '<li style="margin-bottom:6px;">' . $qty . '&times; ' . esc_html( $name );
			if ( '' !== $price ) {
				$out .= ' - ' . esc_html( $price );
			}
			$out .= '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Items as an e-mail-safe table: photo, name + quantity, price.
	 *
	 * Colours are neutral and the text colour is inherited, so the table fits
	 * inside any template.
	 *
	 * @param array $items Items (see items_html).
	 * @return string
	 */
	private static function items_table_html( array $items ) {
		$cel  = 'border-bottom:1px solid #e9e3dc;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;';
		$out  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:12px 0 16px 0;border-collapse:collapse;">';

		foreach ( $items as $item ) {
			$qty     = isset( $item['qty'] ) ? max( 1, (int) $item['qty'] ) : 1;
			$name    = isset( $item['name'] ) ? (string) $item['name'] : '';
			$price   = isset( $item['price'] ) ? (string) $item['price'] : '';
			$foto    = '';
			$link    = '';
			$welk_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : ( isset( $item['product_id'] ) ? (int) $item['product_id'] : 0 );
			$product = ( $welk_id && function_exists( 'wc_get_product' ) ) ? wc_get_product( $welk_id ) : null;

			if ( ! $product && ! empty( $item['product_id'] ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( (int) $item['product_id'] );
			}

			if ( $product ) {
				if ( '' === $name ) {
					$name = $product->get_name();
				}

				$beeld_id = (int) $product->get_image_id();
				if ( ! $beeld_id && $product->get_parent_id() ) {
					$ouder    = wc_get_product( $product->get_parent_id() );
					$beeld_id = $ouder ? (int) $ouder->get_image_id() : 0;
				}
				if ( $beeld_id ) {
					$foto = (string) wp_get_attachment_image_url( $beeld_id, 'woocommerce_thumbnail' );
				}

				$link = (string) $product->get_permalink();

				if ( function_exists( 'wc_get_price_to_display' ) ) {
					$bedrag = (float) wc_get_price_to_display( $product, array( 'qty' => $qty ) );
					if ( $bedrag > 0 ) {
						$price = self::format_price( $bedrag );
					}
				}
			}

			/* Regels van buiten WooCommerce (Shopify) brengen hun eigen foto mee. */
if ( '' === $foto && ! empty( $item['image'] ) ) {
	$foto = (string) $item['image'];
}
if ( '' === $link && ! empty( $item['url'] ) ) {
	$link = (string) $item['url'];
}

$out .= '<tr>';

			$out .= '<td width="84" valign="middle" style="width:84px;padding:12px 14px 12px 0;' . $cel . '">';
			if ( '' !== $foto ) {
				$img = '<img src="' . esc_url( $foto ) . '" alt="' . esc_attr( $name ) . '" width="72" height="72" style="display:block;width:72px;height:72px;object-fit:cover;border:0;border-radius:8px;">';
				$out .= '' !== $link ? '<a href="' . esc_url( $link ) . '" style="text-decoration:none;">' . $img . '</a>' : $img;
			}
			$out .= '</td>';

			$naam_html = '' !== $link
				? '<a href="' . esc_url( $link ) . '" style="color:inherit;text-decoration:none;font-weight:bold;">' . esc_html( $name ) . '</a>'
				: '<strong>' . esc_html( $name ) . '</strong>';

			$aantal_html = sprintf( /* translators: %d: quantity */ esc_html__( 'Aantal: %d', 'ws-flow-mailer' ), $qty );
			if ( ! empty( $item['qty_gevraagd'] ) && (int) $item['qty_gevraagd'] > $qty ) {
				$aantal_html .= ' &middot; ' . sprintf( /* translators: %d: quantity in stock */ esc_html__( 'nog maar %d op voorraad', 'ws-flow-mailer' ), $qty );
			}

			$out .= '<td valign="middle" style="padding:12px 0;' . $cel . '">' . $naam_html . '<br><span style="font-size:13px;opacity:0.75;">' . $aantal_html . '</span></td>';
			$out .= '<td align="right" valign="middle" style="padding:12px 0 12px 12px;white-space:nowrap;' . $cel . '">' . esc_html( $price ) . '</td>';
			$out .= '</tr>';
		}

		return $out . '</table>';
	}

	/**
	 * Format a raw amount as a plain-text price ("€ 49,95") suitable for
	 * both HTML body and subject. Uses WooCommerce settings when present.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function format_price( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * De gewone winkelwagenpagina.
	 *
	 * @return string
	 */
	private static function winkelwagen_url() {
		return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : self::winkel_url();
	}

	/**
	 * Build merge context from a WooCommerce order (for order flows).
	 *
	 * @param WC_Order $order Order object (from wc_get_order, HPOS-safe).
	 * @param string   $unsubscribe_url Unsubscribe URL for the recipient.
	 * @return array
	 */
	public static function build_order_context( $order, $unsubscribe_url = '' ) {
		$items = array();
		foreach ( $order->get_items() as $line_item ) {
			$items[] = array(
				'name'  => $line_item->get_name(),
				'qty'   => $line_item->get_quantity(),
				'price' => self::format_price( (float) $line_item->get_total() ),
			);
		}

		return array(
			'first_name'      => $order->get_billing_first_name(),
			'order_number'    => $order->get_order_number(),
			'order_total'     => self::format_price( (float) $order->get_total() ),
			'order_items'     => self::items_html( $items ),
			'cart_url'        => self::winkelwagen_url(),
			'unsubscribe_url' => $unsubscribe_url,
		);
	}

	/**
	 * Build merge context from a cart-tracking row (for abandoned_cart flows).
	 *
	 * {cart_url} is hier GEEN link naar de winkelwagenpagina maar een
	 * herstellink: die legt de bewaarde producten terug in de wagen van wie
	 * erop klikt. Een link naar /winkelwagen/ zet de klant namelijk op een lege
	 * pagina zodra zijn sessie weg is, en dat is bij een mail van een dag oud
	 * bijna altijd. Zie WSFM_Cart_Recovery.
	 *
	 * @param object $tracking Row from wsfm_cart_tracking (cart_contents = JSON).
	 * @param string $unsubscribe_url Unsubscribe URL for the recipient.
	 * @return array
	 */
	public static function build_cart_context( $tracking, $unsubscribe_url = '' ) {
		$contents = json_decode( (string) $tracking->cart_contents, true );
		$items    = ( is_array( $contents ) && ! empty( $contents['items'] ) ) ? $contents['items'] : array();
		$total    = ( is_array( $contents ) && isset( $contents['total'] ) ) ? (float) $contents['total'] : 0;

		$first_name = $tracking->customer_name ? preg_split( '/\s+/', trim( $tracking->customer_name ) )[0] : '';

		/* Voorraad bekijken op het moment dat de mail eruit gaat, niet op het
		   moment van achterlaten. Een herinnering aan producten die je niet
		   meer kunt kopen is een teleurstelling met een knop erin. Is alles
		   op, dan gaat deze stap niet: de WP_Error laat de wachtrij hem
		   stoppen met de reden in het log. Een latere stap staat los in de
		   wachtrij en kijkt zelf opnieuw; is er dan weer iets, dan gaat die
		   gewoon. */
		$voorraad = self::wagen_voorraad( $items );
		$cart_html = self::items_html( $items );

		if ( ! empty( $items ) && empty( $voorraad['op_voorraad'] ) ) {
			$namen = array();
			foreach ( $voorraad['uitverkocht'] as $weg ) {
				$namen[] = isset( $weg['name'] ) ? (string) $weg['name'] : '';
			}

			return new WP_Error(
				'wsfm_cart_uitverkocht',
				sprintf(
					/* translators: %s: product names */
					__( 'Niet verstuurd: alles in deze winkelwagen is uitverkocht of niet meer te koop (%s).', 'ws-flow-mailer' ),
					implode( ', ', array_filter( $namen ) )
				)
			);
		}

		if ( $voorraad['gewijzigd'] ) {
			$cart_html = self::voorraad_html( $voorraad );
			$total     = $voorraad['totaal'];
		}

		return array(
			'first_name'      => $first_name,
			'cart_items'      => $cart_html,
			'cart_total'      => ( is_array( $contents ) && ! empty( $contents['total_text'] ) && empty( $voorraad['gewijzigd'] ) ) ? (string) $contents['total_text'] : self::format_price( $total ),
			'cart_url'        => ! empty( $tracking->herstel_url ) ? (string) $tracking->herstel_url : ( class_exists( 'WSFM_Cart_Recovery' ) ? WSFM_Cart_Recovery::link( $tracking ) : self::winkelwagen_url() ),
			'unsubscribe_url' => $unsubscribe_url,
		);
	}

	/**
	 * Splits bewaarde winkelwagenregels in wat nu nog te koop is en wat niet.
	 *
	 * Per regel telt het product dat echt in de wagen lag: de variatie als die
	 * er is, anders het product. Te koop betekent: gepubliceerd (bij een
	 * variatie ook het hoofdproduct), koopbaar en op voorraad. Nabestellen
	 * toegestaan telt als op voorraad, want dan kan de klant het gewoon kopen.
	 * Is er minder dan er in de wagen lag, dan gaat het aantal omlaag naar wat
	 * er is en onthoudt de regel het oorspronkelijke aantal in qty_gevraagd.
	 *
	 * Ook gebruikt door de herstellink (WSFM_Cart_Recovery), zodat de mail en
	 * de knop erin precies hetzelfde zeggen.
	 *
	 * @param array $items Regels uit wsfm_cart_tracking.cart_contents.
	 * @return array { op_voorraad: array, uitverkocht: array, gewijzigd: bool, totaal: float }
	 */
	public static function wagen_voorraad( array $items ) {
		$uit = array(
			'op_voorraad' => array(),
			'uitverkocht' => array(),
			'gewijzigd'   => false,
			'totaal'      => 0.0,
		);

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$product = self::regel_product( $item );

			if ( ! $product ) {
				/* Zonder product_id (heel oude rij) kunnen we niets controleren;
				   dan laten we de regel staan zoals hij was. Een product_id
				   waarvan het product weg is, is wel uitverkocht. */
				if ( empty( $item['product_id'] ) || ! function_exists( 'wc_get_product' ) ) {
					$uit['op_voorraad'][] = $item;
				} else {
					$uit['uitverkocht'][] = $item;
					$uit['gewijzigd']     = true;
				}
				continue;
			}

			if ( ! self::is_te_koop( $product ) ) {
				if ( empty( $item['name'] ) ) {
					$item['name'] = $product->get_name();
				}
				$uit['uitverkocht'][] = $item;
				$uit['gewijzigd']     = true;
				continue;
			}

			$qty = isset( $item['qty'] ) ? max( 1, (int) $item['qty'] ) : 1;
			$max = (int) $product->get_max_purchase_quantity(); // -1 = onbeperkt (geen voorraadbeheer of nabestellen mag).

			if ( $max > 0 && $qty > $max ) {
				$item['qty_gevraagd'] = $qty;
				$item['qty']          = $max;
				$qty                  = $max;
				$uit['gewijzigd']     = true;
			}

			if ( function_exists( 'wc_get_price_to_display' ) ) {
				$uit['totaal'] += (float) wc_get_price_to_display( $product, array( 'qty' => $qty ) );
			}

			$uit['op_voorraad'][] = $item;
		}

		return $uit;
	}

	/**
	 * Het product achter een winkelwagenregel: de variatie, anders het product.
	 *
	 * @param array $item Regel.
	 * @return WC_Product|null
	 */
	private static function regel_product( array $item ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : ( isset( $item['product_id'] ) ? (int) $item['product_id'] : 0 );
		if ( $id < 1 ) {
			return null;
		}

		$product = wc_get_product( $id );

		return $product ? $product : null;
	}

	/**
	 * Is dit product nu te koop: gepubliceerd, koopbaar en op voorraad?
	 *
	 * @param WC_Product $product Product of variatie.
	 * @return bool
	 */
	public static function is_te_koop( $product ) {
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( $product->get_parent_id() ) {
			$ouder = wc_get_product( $product->get_parent_id() );
			if ( ! $ouder || 'publish' !== $ouder->get_status() ) {
				return false;
			}
		}

		/* is_in_stock() is ook waar bij 'onbackorder', en dat is precies wat
		   we willen: nabestellen mag, dus de klant kan het kopen. */
		return $product->is_purchasable() && $product->is_in_stock();
	}

	/**
	 * {cart_items} als er iets uitverkocht is of minder op voorraad dan in de wagen lag.
	 *
	 * Bovenaan een korte regel, dan de producten die er nog zijn als gewone
	 * regels met foto en prijs, en onderaan klein en grijs wat op is: zonder
	 * foto, link of knop, want daar kan de klant niets mee.
	 *
	 * @param array $voorraad Uitkomst van wagen_voorraad().
	 * @return string
	 */
	private static function voorraad_html( array $voorraad ) {
		$tekst = 'font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.7;';
		$out   = '';

		if ( ! empty( $voorraad['uitverkocht'] ) ) {
			$out .= '<p style="margin:4px 0 0 0;' . $tekst . '">' . sprintf(
				/* translators: %s: "winkelwagen" or "winkelmandje", whichever word the mail itself uses */
				esc_html__( 'Let op: een paar producten uit je %s zijn inmiddels uitverkocht. Deze zijn nog wel op voorraad:', 'ws-flow-mailer' ),
				self::WAGENWOORD
			) . '</p>';
		} else {
			$out .= '<p style="margin:4px 0 0 0;' . $tekst . '">' . esc_html__( 'Let op: van een paar producten is er nog maar een klein aantal. Dit is er nog voor je:', 'ws-flow-mailer' ) . '</p>';
		}

		$out .= self::items_html( $voorraad['op_voorraad'] );

		if ( ! empty( $voorraad['uitverkocht'] ) ) {
			$grijs = 'font-family:Helvetica,Arial,sans-serif;font-size:13px;line-height:1.5;color:#9a9a9a;padding:4px 0;';
			$out  .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 12px 0;border-collapse:collapse;">';
			foreach ( $voorraad['uitverkocht'] as $item ) {
				$naam = isset( $item['name'] ) ? (string) $item['name'] : '';
				$out .= '<tr><td style="' . $grijs . '">' . esc_html( $naam ) . '</td>'
					. '<td align="right" style="white-space:nowrap;' . $grijs . '">' . esc_html__( 'uitverkocht', 'ws-flow-mailer' ) . '</td></tr>';
			}
			$out .= '</table>';
		}

		return $out;
	}

	/**
	 * De merge-gegevens voor een flow die aan een PERSOON hangt: een nieuwe
	 * inschrijving of een nieuw klantaccount.
	 *
	 * Er is hier geen order en geen winkelwagen, dus {order_items} en
	 * {cart_items} blijven leeg; de motor vervangt elke tag die hij kent, dus er
	 * komt nooit een losse {tag} in de mail terecht.
	 *
	 * WAAROM DE INSCHRIJVING ER TOCH BIJ WORDT GEZOCHT
	 * Voor de kortingscode, en voor de voornaam als die bij het aanmelden niet
	 * is meegekomen. Bij de popup wordt alleen een e-mailadres gevraagd, dus de
	 * naam komt daar uit het vinkje bij het afrekenen of van de winkelier zelf.
	 * Staat er niets, dan blijft {first_name} leeg; dat is beter dan "Hoi
	 * klant" verzinnen.
	 *
	 * @param string $email           E-mailadres van de ontvanger.
	 * @param string $naam            Naam zoals hij in de wachtrij staat.
	 * @param string $unsubscribe_url Afmeldlink voor deze ontvanger.
	 * @return array
	 */
	public static function build_contact_context( $email, $naam = '', $unsubscribe_url = '' ) {
		$first_name = '' !== trim( (string) $naam ) ? preg_split( '/\s+/', trim( (string) $naam ) )[0] : '';
		$code       = '';

		if ( class_exists( 'WSFM_Subscribers' ) ) {
			$rij = WSFM_Subscribers::get( $email );
			if ( $rij ) {
				$code = (string) $rij->coupon_code;
				if ( '' === $first_name ) {
					$first_name = (string) $rij->first_name;
				}
			}
		}

		return array(
			'first_name'      => $first_name,
			'coupon_code'     => $code,
			'cart_url'        => self::winkelwagen_url(),
			'unsubscribe_url' => $unsubscribe_url,
		);
	}

	/**
	 * Fictional context for previews and test mails.
	 *
	 * @return array
	 */
	public static function test_context() {
		$items = array(
			array(
				'name'  => 'Product X',
				'qty'   => 2,
				'price' => self::format_price( 49.95 ),
			),
		);

		return array(
			'first_name'      => 'Jan',
			'cart_items'      => self::items_html( $items ),
			'cart_total'      => self::format_price( 49.95 ),
			'cart_url'        => self::winkelwagen_url(),
			'order_number'    => '#12345',
			'order_total'     => self::format_price( 49.95 ),
			'order_items'     => self::items_html( $items ),
			'coupon_code'     => 'WELKOM-4KP7HQ',
			'unsubscribe_url' => home_url( '/?voorbeeld-afmeldlink' ),
		);
	}

	/**
	 * De webwinkel zelf. Op een nieuwsbrief-WP bij een Shopify-winkel is dat de
	 * Shopify-winkel en niet de WordPress waar de mailer op draait.
	 *
	 * @return string
	 */
	public static function winkel_url() {
		if ( class_exists( 'WSFM_Shopify' ) ) {
			$url = WSFM_Shopify::winkel_url();
			if ( '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Merge-gegevens voor een bestelling uit een webhook (zie WSFM_Ext_Orders).
	 *
	 * @param object $rij             Rij uit wsfm_ext_orders.
	 * @param string $unsubscribe_url Afmeldlink.
	 * @return array
	 */
	public static function build_ext_order_context( $rij, $unsubscribe_url = '' ) {
		$items = json_decode( (string) $rij->items, true );

		return array(
			'first_name'      => (string) $rij->first_name,
			'order_number'    => (string) $rij->order_number,
			'order_total'     => (string) $rij->total_text,
			'order_items'     => self::items_html( is_array( $items ) ? $items : array() ),
			'cart_url'        => self::winkel_url(),
			'unsubscribe_url' => $unsubscribe_url,
		);
	}
}
