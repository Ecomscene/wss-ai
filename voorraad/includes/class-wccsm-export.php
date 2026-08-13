<?php
/**
 * Producten en voorraad naar een CSV-bestand.
 *
 * WAT ERIN KOMT
 * Precies de selectie die op het scherm staat. De filters gaan mee in de link,
 * dus zoek je op een leverancier en op "niet op voorraad", dan krijg je díé
 * regels. Een export die stiekem alles meeneemt is de snelste manier om iemand
 * op de verkeerde cijfers te laten bestellen.
 *
 * WAAROM PUNTKOMMA'S EN EEN BOM
 * Deze bestanden gaan in Excel open, meestal op een Nederlandse instelling.
 * Excel splitst daar op puntkomma's en niet op komma's, en zonder byte order
 * mark maakt hij van elke é een Ã©. Dat zijn twee kleine dingen die het verschil
 * maken tussen "werkt" en "wat is dit".
 *
 * WAAROM IN PORTIES
 * Bij duizenden producten past het geheel niet in het geheugen, zeker niet met
 * de varianten erbij. Er wordt daarom per honderd opgehaald en meteen naar de
 * uitvoer geschreven. Wat verstuurd is hoeft niet onthouden te worden.
 *
 * @package WCCSM
 */

defined( 'ABSPATH' ) || exit;

class WCCSM_Export {

	/** Per hoeveel producten er wordt opgehaald en weggeschreven. */
	private const PORTIE = 100;

	public function __construct() {
		add_action( 'admin_post_wccsm_export', [ $this, 'download' ] );
	}

	/**
	 * De link naar de export, met de huidige filters erin.
	 *
	 * @return string
	 */
	public static function url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wccsm_export' ),
			'wccsm_export',
			'wccsm_nonce'
		);
	}

	/**
	 * De kolommen. Eén plek, zodat de kop en de regels niet uit de pas lopen.
	 *
	 * @return array
	 */
	public static function kolommen(): array {
		return [
			'id'             => __( 'ID', 'wccsm' ),
			'soort'          => __( 'Soort', 'wccsm' ),
			'product'        => __( 'Product', 'wccsm' ),
			'variatie'       => __( 'Variatie', 'wccsm' ),
			'sku'            => __( 'SKU', 'wccsm' ),
			'gtin'           => __( 'GTIN / EAN', 'wccsm' ),
			'leverancier'    => __( 'Leverancier', 'wccsm' ),
			'inkoopprijs'    => __( 'Inkoopprijs', 'wccsm' ),
			'prijs'          => __( 'Normale prijs', 'wccsm' ),
			'actieprijs'     => __( 'Actieprijs', 'wccsm' ),
			'voorraad'       => __( 'Voorraad', 'wccsm' ),
			'voorraadbeheer' => __( 'Voorraadbeheer', 'wccsm' ),
			'voorraadstatus' => __( 'Voorraadstatus', 'wccsm' ),
			'samengesteld'   => __( 'Samengesteld uit', 'wccsm' ),
		];
	}

	/**
	 * Het bestand opbouwen en versturen.
	 */
	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze export te maken.', 'wccsm' ) );
		}

		check_admin_referer( 'wccsm_export', 'wccsm_nonce' );

		$filters = [
			'search'       => sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) ),
			'supplier'     => sanitize_text_field( wp_unslash( $_GET['supplier'] ?? '' ) ),
			'product_type' => sanitize_text_field( wp_unslash( $_GET['product_type'] ?? '' ) ),
			'stock_status' => sanitize_text_field( wp_unslash( $_GET['stock_status'] ?? '' ) ),
		];

		$ids = WCCSM_Admin_Overview::zoek_ids( $filters );

		/* Een export van duizenden producten duurt langer dan een gewone pagina.
		   Waar het mag zetten we de grens hoger; mag het niet, dan valt hij alsnog
		   om, maar dan ligt het aan de hosting en niet aan een grens die wij zelf
		   gezet hebben. */
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->bestandsnaam( $filters ) . '"' );

		$uit = fopen( 'php://output', 'w' );

		// De byte order mark, zodat Excel de accenten goed leest.
		fwrite( $uit, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $uit, array_values( self::kolommen() ), ';' );

		foreach ( array_chunk( $ids, self::PORTIE ) as $portie ) {
			foreach ( $portie as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				if ( $product->is_type( 'variable' ) ) {
					// De ouder erbij, want daar staan de naam en vaak de leverancier.
					fputcsv( $uit, $this->regel( $product ), ';' );

					foreach ( $product->get_children() as $var_id ) {
						$variatie = wc_get_product( $var_id );
						if ( ! $variatie ) {
							continue;
						}
						if ( ! $this->past_bij_voorraad( $variatie, $filters['stock_status'] ) ) {
							continue;
						}
						fputcsv( $uit, $this->regel( $variatie, $product ), ';' );
					}

					continue;
				}

				fputcsv( $uit, $this->regel( $product ), ';' );
			}

			/* Wegschrijven en het geheugen weer vrijgeven. Zonder dit groeit een
			   export van drieduizend producten net zo hard als het geheugen dat
			   PHP mag gebruiken. */
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			flush();
		}

		fclose( $uit );
		exit;
	}

	/**
	 * Eén regel voor het bestand.
	 *
	 * @param WC_Product      $product Het product of de variatie.
	 * @param WC_Product|null $ouder   Bij een variatie: het bovenliggende product.
	 * @return array
	 */
	private function regel( $product, $ouder = null ): array {
		$is_variatie = 'variation' === $product->get_type();

		/* Leverancier en GTIN mogen bij een variatie leeg zijn; dan geldt wat er
		   bij de ouder staat. Dat is ook hoe het overzicht het toont. */
		$leverancier = $product->get_meta( '_wccsm_supplier' );
		if ( ! $leverancier && $ouder ) {
			$leverancier = $ouder->get_meta( '_wccsm_supplier' );
		}

		$gtin = $product->get_global_unique_id();
		if ( ! $gtin && $ouder ) {
			$gtin = $ouder->get_global_unique_id();
		}

		$variatie = '';
		if ( $is_variatie ) {
			$delen = [];
			foreach ( $product->get_attributes() as $waarde ) {
				if ( $waarde ) {
					$delen[] = ucfirst( $waarde );
				}
			}
			$variatie = implode( ' / ', $delen );
		}

		$samengesteld = '';
		if ( WCCSM_Components::has_components( $product->get_id() ) ) {
			$namen = [];
			foreach ( WCCSM_Components::get_components_with_stock( $product->get_id() ) as $c ) {
				$namen[] = $c['name'] . ' x' . $c['qty'];
			}
			$samengesteld = implode( ', ', $namen );
		}

		return [
			$product->get_id(),
			$is_variatie ? __( 'Variatie', 'wccsm' ) : ucfirst( $product->get_type() ),
			$ouder ? $ouder->get_name() : $product->get_name(),
			$variatie,
			$product->get_sku(),
			/* Als tekst forceren: een EAN als 0642023317084 is geen getal maar een
			   code, en Excel maakt er anders 6,42023E+11 van of gooit de nul aan
			   het begin weg. */
			'' === (string) $gtin ? '' : "'" . $gtin,
			$leverancier,
			$this->getal( $product->get_meta( '_wccsm_purchase_price' ) ),
			$this->getal( $product->get_regular_price() ),
			$this->getal( $product->get_sale_price() ),
			$product->get_manage_stock() ? $product->get_stock_quantity() : '',
			$product->get_manage_stock() ? __( 'Ja', 'wccsm' ) : __( 'Nee', 'wccsm' ),
			$product->get_stock_status(),
			$samengesteld,
		];
	}

	/**
	 * Een bedrag zoals Nederlands Excel het verwacht: met een komma.
	 *
	 * @param mixed $waarde Bedrag.
	 * @return string
	 */
	private function getal( $waarde ): string {
		if ( '' === $waarde || null === $waarde ) {
			return '';
		}
		return str_replace( '.', ',', (string) $waarde );
	}

	/**
	 * Past deze variatie bij het gekozen voorraadfilter?
	 *
	 * Dezelfde regels als in het overzicht: zonder voorraadbeheer telt hij nergens
	 * in mee, want dan is er geen aantal om op te filteren.
	 *
	 * @param WC_Product $variatie De variatie.
	 * @param string     $status   Het gekozen filter, of leeg.
	 * @return bool
	 */
	private function past_bij_voorraad( $variatie, string $status ): bool {
		if ( '' === $status ) {
			return true;
		}

		$aantal = $variatie->get_stock_quantity();

		if ( null === $aantal ) {
			return false;
		}
		if ( 'outofstock' === $status ) {
			return $aantal <= 0;
		}
		if ( 'lowstock' === $status ) {
			return $aantal <= absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		}

		return $aantal > 0;
	}

	/**
	 * Een naam waar je later nog aan ziet wat erin zit.
	 *
	 * @param array $filters De gebruikte filters.
	 * @return string
	 */
	private function bestandsnaam( array $filters ): string {
		$delen = [ 'voorraad' ];

		if ( $filters['supplier'] ) {
			$delen[] = sanitize_title( $filters['supplier'] );
		}
		if ( $filters['stock_status'] ) {
			$delen[] = sanitize_title( $filters['stock_status'] );
		}
		if ( $filters['product_type'] ) {
			$delen[] = sanitize_title( $filters['product_type'] );
		}
		if ( $filters['search'] ) {
			$delen[] = sanitize_title( $filters['search'] );
		}

		$delen[] = gmdate( 'Y-m-d' );

		return implode( '-', $delen ) . '.csv';
	}
}
