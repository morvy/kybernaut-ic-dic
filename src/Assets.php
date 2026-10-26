<?php
/**
 * Scripts and styles: the classic checkout, the lookup UI both checkouts share, and
 * the admin screens. The block checkout loads its script through
 * Checkout\BlocksIntegration, with the data from script_data( true ).
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic;

use KybernautIcDic\Services\Services;
use KybernautIcDic\Settings\Flags;
use KybernautIcDic\Settings\Options;

/**
 * Handles and file names are the 1.x ones, without the .min copies: the scripts
 * are built minified (npm run build, webpack.config.js; npm run start for a
 * readable build), suggest.js bundled into both checkout scripts.
 */
final class Assets {

	/**
	 * Hook the enqueues.
	 */
	public static function register(): void {
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( self::class, 'enqueue_lookup_style' ) );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ) );
		} else {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_classic' ) );
		}
	}

	/**
	 * Register a built script (npm run build, @wordpress/scripts) with the
	 * dependencies and version from its .asset.php.
	 *
	 * @param string              $handle Script handle.
	 * @param string              $name   File name in assets/js without the extension, e.g. 'public'.
	 * @param string[]            $deps   Dependencies the build does not see (globals: jQuery, wc.*).
	 * @param array<string,mixed> $args   wp_register_script() arguments.
	 */
	public static function register_script( string $handle, string $name, array $deps, array $args = array( 'in_footer' => true ) ): void {
		$file  = WOOLAB_IC_DIC_ABSPATH . 'assets/js/' . $name . '.asset.php';
		$asset = is_readable( $file ) ? (array) require $file : array();

		wp_register_script(
			$handle,
			WOOLAB_IC_DIC_URL . 'assets/js/' . $name . '.js',
			array_values( array_unique( array_merge( $deps, (array) ( $asset['dependencies'] ?? array() ) ) ) ),
			(string) ( $asset['version'] ?? WOOLAB_IC_DIC_VERSION ),
			$args
		);
	}

	/**
	 * The lookup spinner, status icons, messages and suggestion list (lookup.css), with the
	 * colours from Settings → IČO DIČ → Appearance. Shared by both checkouts.
	 */
	public static function enqueue_lookup_style(): void {
		if ( Services::countries( 'company_lookup' ) ) {
			wp_enqueue_style( 'woolab-icdic-lookup-css', WOOLAB_IC_DIC_URL . 'assets/css/lookup.css', array(), WOOLAB_IC_DIC_VERSION );
			wp_add_inline_style( 'woolab-icdic-lookup-css', self::lookup_colors_css() );
		}
	}

	/**
	 * Settings the checkout scripts read: public.js as window.woolab, blocks.js as
	 * wcSettings 'woolab-ic-dic_data'.
	 *
	 * @param bool $blocks For the block checkout.
	 * @return array<string,mixed>
	 */
	public static function script_data( bool $blocks ): array {
		$data = array(
			// The lookups (Rest\Routes).
			'rest'              => woolab_icdic_rest_url(),
			'l18n_not_valid'    => __( 'Business ID is invalid.', 'woolab-ic-dic' ),
			'l18n_error'        => __( 'Unexpected error occurred. Try it again.', 'woolab-ic-dic' ),
			'l18n_clear'        => __( 'Clear company details', 'woolab-ic-dic' ),
			'ignore_check_fail' => Flags::ignore_check_fail(),
			'services'          => self::services(),
		);

		if ( $blocks ) {
			// Ask the server to recalculate the exemption as the VAT fields change.
			$data['vat_exempt'] = Flags::vat_exempt();
			// Where the IČO field is kybernaut/ic; elsewhere it is ic_eu, '' when hidden there.
			$data['ic_home'] = woolab_icdic_ic_home_countries();
			$data['fields']  = array(
				'ic'      => 'kybernaut/ic',
				'ic_eu'   => null === woolab_icdic_ic_countries() ? 'kybernaut/ic-eu' : '',
				// Country => field: the DIČ field is one per label.
				'dic'     => woolab_icdic_blocks_dic_fields(),
				'dic_dph' => 'kybernaut/dic-dph',
				// Empty when the company name stays a normal address field, so the
				// autofill knows where to put it.
				'company' => woolab_icdic_blocks_owns_company() ? 'kybernaut/company' : '',
			);
		} else {
			// Field labels by billing country; the block fields carry theirs.
			$data['labels']       = woolab_icdic_labels_by_country();
			$data['placeholders'] = woolab_icdic_labels_by_country( false );
			// Countries the Business ID is shown for, null for all.
			$data['ic_countries'] = woolab_icdic_ic_countries();
		}

		return $data;
	}

	/**
	 * The countries whose company the checkouts look up or search, from
	 * Services::map() ('*' not included): country => 'lookup' (a company_lookup
	 * service), 'search' and 'autofill' (a company_search service), and the status
	 * texts naming the service.
	 *
	 * @return array<string,array<string,bool|string>>
	 */
	public static function services(): array {
		$services = array();

		foreach ( Services::map() as $country => $features ) {
			$service = $features['company_lookup'][0] ?? $features['company_search'][0] ?? null;
			if ( '*' === $country || ! $service ) {
				continue;
			}

			switch ( $service->id() ) {
				case 'ares':
					$validating = __( 'Validating data in ARES.', 'woolab-ic-dic' );
					$ok         = __( 'Information loaded successfully from ARES.', 'woolab-ic-dic' );
					break;
				case 'financna_sprava':
					$validating = __( 'Validating data in Finančná správa.', 'woolab-ic-dic' );
					$ok         = __( 'Information loaded successfully from Finančná správa.', 'woolab-ic-dic' );
					break;
				default:
					/* translators: %s: name of the company register, e.g. "GUS" */
					$validating = sprintf( __( 'Validating data in %s.', 'woolab-ic-dic' ), $service->name() );
					/* translators: %s: name of the company register, e.g. "GUS" */
					$ok = sprintf( __( 'Information loaded successfully from %s.', 'woolab-ic-dic' ), $service->name() );
			}

			$services[ $country ] = array(
				'lookup'     => ! empty( $features['company_lookup'] ),
				'search'     => ! empty( $features['company_search'] ),
				'autofill'   => ! empty( $features['company_search'] ),
				'validating' => $validating,
				'ok'         => $ok,
			);
		}

		return $services;
	}

	/**
	 * The classic checkout's script and styles. Only on the checkout form itself:
	 * the block checkout loads blocks.js through its integration, and the
	 * order-received and pay pages need neither.
	 */
	public static function enqueue_classic(): void {
		if ( ! is_checkout() || is_wc_endpoint_url() || woolab_icdic_blocks_checkout() ) {
			return;
		}

		self::enqueue_lookup_style();

		self::register_script(
			'woolab-icdic-public-js',
			'public',
			array( 'jquery' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script( 'woolab-icdic-public-js' );
		wp_localize_script( 'woolab-icdic-public-js', 'woolab', self::script_data( false ) );

		// In the head: it hides the toggled fields before the page paints.
		if ( Flags::toggle() ) {
			wp_enqueue_style( 'woolab-icdic-public-css', WOOLAB_IC_DIC_URL . 'assets/css/style.css', array(), WOOLAB_IC_DIC_VERSION );
		}
	}

	/**
	 * The colours and the list's corner radius set in Settings → IČO DIČ →
	 * Appearance, as the custom properties lookup.css reads. Empty when none is set.
	 */
	public static function lookup_colors_css(): string {
		$properties = array();
		$colors     = array(
			'color_ok'             => 'ok',
			'color_clear'          => 'clear',
			'color_success'        => 'success',
			'color_error'          => 'error',
			'color_info'           => 'info',
			'color_list_bg'        => 'list-bg',
			'color_list_text'      => 'list-text',
			'color_list_border'    => 'list-border',
			'color_list_highlight' => 'list-highlight',
		);

		foreach ( $colors as $key => $property ) {
			$color = sanitize_hex_color( Options::get( $key ) );
			if ( $color ) {
				$properties[] = '--woolab-ic-dic-color-' . $property . ':' . $color;
			}
		}

		$radius = Options::get( 'list_radius' );
		if ( '' !== $radius && is_numeric( $radius ) ) {
			$properties[] = '--woolab-ic-dic-list-radius:' . min( 50, absint( $radius ) ) . 'px';
		}

		return $properties ? ':root{' . implode( ';', $properties ) . '}' : '';
	}

	/**
	 * The order screens' script, and the settings page's.
	 *
	 * @param string $hook The admin page.
	 */
	public static function enqueue_admin( $hook ): void {
		// 'shop_order' for the legacy post type, the wc-orders page under HPOS. Without
		// the HPOS one the country-based show/hide never loads, so the SK-only IČ DPH
		// field stayed visible on CZ orders under HPOS.
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) ) {
			wp_enqueue_style( 'woolab-ic-dic-admin', WOOLAB_IC_DIC_URL . 'assets/css/admin.css', array(), WOOLAB_IC_DIC_VERSION );
			self::register_script( 'woolab-ic-dic-admin-edit', 'admin-edit', array( 'jquery' ) );
			wp_enqueue_script( 'woolab-ic-dic-admin-edit' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
		if ( 'woocommerce_page_wc-settings' !== $hook || ! isset( $_GET['tab'] ) || 'woolab_icdic' !== $_GET['tab'] ) {
			return;
		}

		self::register_script( 'woolab-ic-dic-admin', 'admin', array( 'jquery' ) );
		wp_enqueue_script( 'woolab-ic-dic-admin' );
		/* translators: %s: name of the setting, e.g. "CZ: Validate Business ID in ARES" */
		$needs = __( 'Turn on "%s" first.', 'woolab-ic-dic' );
		// Why admin.js disables an option: the check it needs is off.
		wp_localize_script(
			'woolab-ic-dic-admin',
			'woolabIcdicAdmin',
			array(
				'requires' => array(
					'ares_fill'         => sprintf( $needs, __( 'CZ: Validate Business ID in ARES', 'woolab-ic-dic' ) ),
					'sk_fill'           => sprintf( $needs, __( 'SK: Validate in Finančná správa', 'woolab-ic-dic' ) ),
					'ignore_check_fail' => __( 'Turn on at least one of the ARES, Finančná správa or VIES checks first.', 'woolab-ic-dic' ),
				),
			)
		);
	}
}
