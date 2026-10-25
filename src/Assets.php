<?php
/**
 * Scripts and styles: the classic checkout, the lookup UI both checkouts share, and
 * the admin screens. The block checkout loads its script through
 * Checkout\BlocksIntegration, with the data from script_data( true ).
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic;

use KybernautIcDic\Settings\Flags;
use KybernautIcDic\Settings\Options;

/**
 * Handles and file names are the 1.x ones.
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
	 * A built file's URL: the minified one unless SCRIPT_DEBUG.
	 *
	 * @param string $name File name without the extension, e.g. 'public'.
	 */
	private static function js( string $name ): string {
		return WOOLAB_IC_DIC_URL . 'assets/js/' . $name . ( SCRIPT_DEBUG ? '' : '.min' ) . '.js';
	}

	/**
	 * The lookup spinner, status icons and suggestion list (lookup.css), with the
	 * colours from Settings → IČO DIČ → Appearance. Shared by both checkouts.
	 */
	public static function enqueue_lookup_style(): void {
		if ( Flags::ares_check() || Flags::sk_check() ) {
			wp_enqueue_style( 'woolab-icdic-lookup-css', WOOLAB_IC_DIC_URL . 'assets/css/lookup.css', array(), WOOLAB_IC_DIC_VERSION );
			wp_add_inline_style( 'woolab-icdic-lookup-css', self::lookup_colors_css() );
		}
	}

	/**
	 * Register suggest.js, shared by both checkout scripts, when the search runs.
	 *
	 * @return string[] The handle as a dependency, or none.
	 */
	public static function register_suggest_script(): array {
		if ( ! Flags::suggest() ) {
			return array();
		}

		wp_register_script(
			'woolab-icdic-suggest-js',
			self::js( 'suggest' ),
			array(),
			WOOLAB_IC_DIC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		return array( 'woolab-icdic-suggest-js' );
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
			'rest'               => woolab_icdic_rest_url(),
			'l18n_not_valid'     => __( 'Business ID is invalid.', 'woolab-ic-dic' ),
			'l18n_error'         => __( 'Unexpected error occurred. Try it again.', 'woolab-ic-dic' ),
			'l18n_ok'            => __( 'Information loaded succesfully from ARES.', 'woolab-ic-dic' ),
			'l18n_validating'    => __( 'Validating data in ARES.', 'woolab-ic-dic' ),
			'l18n_ok_sk'         => __( 'Information loaded succesfully from Finančná správa.', 'woolab-ic-dic' ),
			'l18n_validating_sk' => __( 'Validating data in Finančná správa.', 'woolab-ic-dic' ),
			'l18n_clear'         => __( 'Clear company details', 'woolab-ic-dic' ),
			'ares_check'         => Flags::ares_check(),
			'ares_fill'          => Flags::ares_fill(),
			'sk_check'           => Flags::sk_check(),
			'sk_fill'            => Flags::sk_fill(),
			'ignore_check_fail'  => Flags::ignore_check_fail(),
		);

		if ( $blocks ) {
			// Ask the server to recalculate the exemption as the VAT fields change.
			$data['vat_exempt'] = Flags::vat_exempt();
			// Where the IČO field is kybernaut/ic, the one blocks.js checks.
			$data['ic_home'] = woolab_icdic_ic_home_countries();
			$data['fields']  = array(
				'ic'      => 'kybernaut/ic',
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
	 * The classic checkout's script and styles. Only on the checkout form itself:
	 * the block checkout loads blocks.js through its integration, and the
	 * order-received and pay pages need neither.
	 */
	public static function enqueue_classic(): void {
		if ( ! is_checkout() || is_wc_endpoint_url() || woolab_icdic_blocks_checkout() ) {
			return;
		}

		self::enqueue_lookup_style();

		wp_enqueue_script(
			'woolab-icdic-public-js',
			self::js( 'public' ),
			array_merge( array( 'jquery' ), self::register_suggest_script() ),
			WOOLAB_IC_DIC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
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
			wp_enqueue_script( 'woolab-ic-dic-admin-edit', self::js( 'admin-edit' ), array( 'jquery' ), WOOLAB_IC_DIC_VERSION, true );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
		if ( 'woocommerce_page_wc-settings' !== $hook || ! isset( $_GET['tab'] ) || 'woolab_icdic' !== $_GET['tab'] ) {
			return;
		}

		wp_enqueue_script( 'woolab-ic-dic-admin', self::js( 'admin' ), array( 'jquery' ), WOOLAB_IC_DIC_VERSION, true );
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
