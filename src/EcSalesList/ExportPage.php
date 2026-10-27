<?php
/**
 * WooCommerce → EC Sales List: the statement of a month or quarter, previewed, and
 * downloaded as CSV, the Slovak XML or the Czech XML.
 *
 * A page of its own rather than a section of the settings tab: the settings tab is a
 * form that saves, this one only reads (a period to pick, files to download). The
 * shop's details the files need are settings: Settings → IČO DIČ → EC Sales List.
 *
 * @package KybernautIcDic
 * @since 2.0.0
 */

namespace KybernautIcDic\EcSalesList;

use Automattic\WooCommerce\Utilities\OrderUtil;
use KybernautIcDic\Settings\Fields;

/**
 * The admin page and the downloads (admin-post.php).
 */
final class ExportPage {

	/**
	 * The page's slug.
	 */
	public const PAGE = 'woolab-icdic-ec-sales-list';

	/**
	 * The download's admin-post action, also its nonce action.
	 */
	public const ACTION = 'woolab_icdic_ec_sales_list';

	/**
	 * Hook the page, the downloads, and the period date kept on a status change.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 60 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'download' ) );
		add_action( 'woocommerce_order_status_changed', array( Report::class, 'status_changed' ), 10, 4 );
	}

	/**
	 * Add the page under WooCommerce.
	 */
	public static function menu(): void {
		$title = __( 'EC Sales List', 'woolab-ic-dic' ) . ' ' . __( '(beta)', 'woolab-ic-dic' );
		add_submenu_page( 'woocommerce', $title, $title, 'manage_woocommerce', self::PAGE, array( self::class, 'render' ) );
	}

	/**
	 * The period asked for: 'period' m1–m12 or q1–q4, and 'year'; last month by default.
	 *
	 * @return array{0:string,1:int,2:int} Type ('month' or 'quarter'), number, year.
	 */
	public static function requested_period(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only; the downloads check their nonce first.
		$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '';
		$year   = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : 0;
		// phpcs:enable

		if ( preg_match( '/^(m|q)(\d{1,2})$/', $period, $m ) && $year >= 2000 && $year <= 2100 ) {
			$type   = 'q' === $m[1] ? 'quarter' : 'month';
			$number = (int) $m[2];

			if ( $number >= 1 && $number <= ( 'quarter' === $type ? 4 : 12 ) ) {
				return array( $type, $number, $year );
			}
		}

		$last = new \DateTimeImmutable( 'first day of last month', wp_timezone() );

		return array( 'month', (int) $last->format( 'n' ), (int) $last->format( 'Y' ) );
	}

	/**
	 * A download's URL.
	 *
	 * @param string $format 'csv', 'sk' or 'cz'.
	 * @param string $type   'month' or 'quarter'.
	 * @param int    $number Month or quarter.
	 * @param int    $year   Year.
	 */
	public static function download_url( string $format, string $type, int $number, int $year ): string {
		$args = array(
			'action' => self::ACTION,
			'format' => $format,
			'period' => ( 'quarter' === $type ? 'q' : 'm' ) . $number,
			'year'   => $year,
		);

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * Send a file: the CSV, or an XML when the shop's details are filled in.
	 */
	public static function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export the EC Sales List.', 'woolab-ic-dic' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		if ( ! in_array( $format, array( 'csv', 'sk', 'cz' ), true ) ) {
			wp_die( esc_html__( 'Unknown file format.', 'woolab-ic-dic' ), 400 );
		}

		list( $type, $number, $year ) = self::requested_period();
		list( $start, $end )          = Report::period( $type, $number, $year );
		$rows                         = Report::rows( Report::entries( $start, $end ), $format )['rows'];
		$period                       = sprintf( 'quarter' === $type ? '%d-Q%d' : '%d-%02d', $year, $number );

		if ( 'csv' === $format ) {
			$body = self::csv( $rows );
		} else {
			$shop    = Xml::shop();
			$missing = Xml::missing( $format, $shop );
			if ( $missing ) {
				/* translators: %s: list of settings */
				wp_die( esc_html( sprintf( __( 'Fill in the shop\'s details first: %s.', 'woolab-ic-dic' ), implode( ', ', $missing ) ) ), 400 );
			}
			$body = 'sk' === $format
				? Xml::sk( $rows, $shop, $type, $number, $year, wp_date( 'Y-m-d' ) )
				: Xml::cz( $rows, $shop, $type, $number, $year );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv' : 'application/xml' ) . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ec-sales-list-' . $format . '-' . $period . '.' . ( 'csv' === $format ? 'csv' : 'xml' ) . '"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file, not HTML.
		exit;
	}

	/**
	 * The CSV: every currency, values in cents, UTF-8 with a BOM (Excel).
	 *
	 * @param array<int,array{currency:string,country:string,vat:string,kind:string,value:float,orders:int,unverified:bool}> $rows Report::rows() for 'csv'.
	 */
	public static function csv( array $rows ): string {
		$lines = array(
			array(
				__( 'Country', 'woolab-ic-dic' ),
				__( 'VAT number', 'woolab-ic-dic' ),
				__( 'Supply', 'woolab-ic-dic' ),
				__( 'SK code', 'woolab-ic-dic' ),
				__( 'CZ code', 'woolab-ic-dic' ),
				__( 'Value', 'woolab-ic-dic' ),
				__( 'Currency', 'woolab-ic-dic' ),
				__( 'Orders', 'woolab-ic-dic' ),
				__( 'VAT ID not verified', 'woolab-ic-dic' ),
			),
		);

		foreach ( $rows as $row ) {
			$lines[] = array(
				$row['country'],
				$row['vat'],
				$row['kind'],
				Report::CODES['sk'][ $row['kind'] ],
				Report::CODES['cz'][ $row['kind'] ],
				number_format( $row['value'], 2, '.', '' ),
				$row['currency'],
				$row['orders'],
				$row['unverified'] ? 'yes' : '',
			);
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- A memory stream, not a file.
		$stream = fopen( 'php://temp', 'r+' );
		foreach ( $lines as $line ) {
			fputcsv( $stream, $line, ',', '"', '' );
		}
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		fclose( $stream );
		// phpcs:enable

		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * The page: the period, the rows, what the files leave out, the downloads.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		list( $type, $number, $year ) = self::requested_period();
		list( $start, $end )          = Report::period( $type, $number, $year );
		$entries                      = Report::entries( $start, $end );
		$report                       = Report::rows( $entries, 'csv' );
		$shop                         = Xml::shop();
		$kinds                        = array(
			'goods'    => __( 'Goods', 'woolab-ic-dic' ),
			'services' => __( 'Services', 'woolab-ic-dic' ),
		);
		$order_link                   = static fn ( int $id ) => '<a href="' . esc_url( OrderUtil::get_order_admin_edit_url( $id ) ) . '">#' . esc_html( (string) $id ) . '</a>';

		?>
		<div class="wrap woolab-icdic-ec-sales-list">
			<h1><?php echo esc_html( __( 'EC Sales List', 'woolab-ic-dic' ) . ' ' . __( '(beta)', 'woolab-ic-dic' ) ); ?></h1>
			<p><?php esc_html_e( 'The reverse-charge sales to companies in other EU countries: the orders whose VAT ID was valid in VIES, net of discounts and refunds, per customer VAT number, goods (shipping included) and services (virtual products). An order counts in the month it was first processed (paid or sent for delivery), a refund in the month it was made.', 'woolab-ic-dic' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<label for="woolab-icdic-ec-period"><?php esc_html_e( 'Period', 'woolab-ic-dic' ); ?></label>
				<select id="woolab-icdic-ec-period" name="period">
					<optgroup label="<?php esc_attr_e( 'Month', 'woolab-ic-dic' ); ?>">
						<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
							<option value="m<?php echo esc_attr( (string) $i ); ?>" <?php selected( 'month' === $type && $number === $i ); ?>><?php echo esc_html( $GLOBALS['wp_locale']->get_month( $i ) ); ?></option>
						<?php endfor; ?>
					</optgroup>
					<optgroup label="<?php esc_attr_e( 'Quarter', 'woolab-ic-dic' ); ?>">
						<?php for ( $i = 1; $i <= 4; $i++ ) : ?>
							<?php /* translators: %d: quarter, 1 to 4 */ ?>
							<option value="q<?php echo esc_attr( (string) $i ); ?>" <?php selected( 'quarter' === $type && $number === $i ); ?>><?php echo esc_html( sprintf( __( 'Quarter %d', 'woolab-ic-dic' ), $i ) ); ?></option>
						<?php endfor; ?>
					</optgroup>
				</select>
				<label for="woolab-icdic-ec-year"><?php esc_html_e( 'Year', 'woolab-ic-dic' ); ?></label>
				<input id="woolab-icdic-ec-year" type="number" name="year" min="2000" max="2100" value="<?php echo esc_attr( (string) $year ); ?>" style="width:6em">
				<?php submit_button( __( 'Show', 'woolab-ic-dic' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped" style="margin:1em 0">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Country', 'woolab-ic-dic' ); ?></th>
						<th><?php esc_html_e( 'VAT number', 'woolab-ic-dic' ); ?></th>
						<th><?php esc_html_e( 'Supply', 'woolab-ic-dic' ); ?></th>
						<th><?php esc_html_e( 'Code SK / CZ', 'woolab-ic-dic' ); ?></th>
						<th style="text-align:right"><?php esc_html_e( 'Value', 'woolab-ic-dic' ); ?></th>
						<th><?php esc_html_e( 'Currency', 'woolab-ic-dic' ); ?></th>
						<th style="text-align:right"><?php esc_html_e( 'Orders', 'woolab-ic-dic' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $report['rows'] ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No reverse-charge sales in this period.', 'woolab-ic-dic' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['country'] ); ?></td>
							<td>
								<?php echo esc_html( $row['vat'] ); ?>
								<?php if ( $row['unverified'] ) : ?>
									<br><span style="color:#ca4a1f"><?php esc_html_e( 'Not confirmed in VIES for an order: check the VAT ID', 'woolab-ic-dic' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $kinds[ $row['kind'] ] ); ?></td>
							<td><?php echo esc_html( Report::CODES['sk'][ $row['kind'] ] . ' / ' . Report::CODES['cz'][ $row['kind'] ] ); ?></td>
							<td style="text-align:right"><?php echo esc_html( number_format_i18n( $row['value'], 2 ) ); ?></td>
							<td><?php echo esc_html( $row['currency'] ); ?></td>
							<td style="text-align:right"><?php echo esc_html( (string) $row['orders'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$flagged = array(
				'unverified'  => __( 'Sold without VAT, but VIES did not confirm the VAT ID (e.g. it did not answer, "Ignore when a check fails"): check these VAT IDs before filing.', 'woolab-ic-dic' ),
				'no_evidence' => __( 'Sold without VAT with no VIES record (created in the admin, or before version 2.0): check these VAT IDs before filing.', 'woolab-ic-dic' ),
			);
			foreach ( $flagged as $flag => $message ) {
				$ids = array_unique( array_column( array_filter( $entries, static fn ( $entry ) => $flag === $entry['flag'] ), 'order_id' ) );
				if ( $ids ) {
					echo '<p>' . esc_html( $message ) . ' ' . wp_kses_post( implode( ', ', array_map( $order_link, $ids ) ) ) . '</p>';
				}
			}
			?>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::download_url( 'csv', $type, $number, $year ) ); ?>"><?php esc_html_e( 'Download CSV', 'woolab-ic-dic' ); ?></a>
				<?php
				$files = array(
					'sk' => __( 'Download SK XML (eDane)', 'woolab-ic-dic' ),
					'cz' => __( 'Download CZ XML (EPO)', 'woolab-ic-dic' ),
				);
				foreach ( $files as $format => $label ) {
					if ( ! Xml::missing( $format, $shop ) ) {
						echo ' <a class="button" href="' . esc_url( self::download_url( $format, $type, $number, $year ) ) . '">' . esc_html( $label ) . '</a>';
					}
				}
				?>
			</p>

			<?php
			$names = array(
				/* translators: %s: currency code */
				'sk' => __( 'SK XML (in %s only)', 'woolab-ic-dic' ),
				/* translators: %s: currency code */
				'cz' => __( 'CZ XML (in %s only)', 'woolab-ic-dic' ),
			);
			foreach ( $names as $format => $name ) {
				$name    = sprintf( $name, Report::CURRENCIES[ $format ] );
				$missing = Xml::missing( $format, $shop );
				$skipped = Report::rows( $entries, $format )['skipped'];

				// A file the shop has no details for is not one it files: what it
				// leaves out is shown once it does.
				if ( $missing ) {
					printf(
						/* translators: 1: file, e.g. "SK XML (in EUR only)", 2: list of settings, 3: link to the settings */
						'<p>' . esc_html__( '%1$s: fill in %2$s in %3$s.', 'woolab-ic-dic' ) . '</p>',
						esc_html( $name ),
						esc_html( implode( ', ', $missing ) ),
						'<a href="' . esc_url( Fields::url( 'ec_sales_list' ) ) . '">' . esc_html__( 'Settings → IČO DIČ', 'woolab-ic-dic' ) . '</a>'
					);
				} elseif ( $skipped ) {
					printf(
						/* translators: 1: file, e.g. "SK XML (in EUR only)", 2: number of orders, 3: list of order links */
						'<p>' . esc_html( _n( '%1$s leaves out %2$d order in another currency: %3$s', '%1$s leaves out %2$d orders in another currency: %3$s', count( $skipped ), 'woolab-ic-dic' ) ) . '</p>',
						esc_html( $name ),
						count( $skipped ),
						wp_kses_post( implode( ', ', array_map( $order_link, array_keys( $skipped ) ) ) )
					);
				}
			}
			?>
			<?php if ( '' !== $shop['phone'] && '' === Xml::cz_phone( $shop['phone'] ) && ! Xml::missing( 'cz', $shop ) ) : ?>
				<p><?php esc_html_e( 'CZ XML: the phone has more than 14 characters without spaces, it is left out.', 'woolab-ic-dic' ); ?></p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'The XML files are regular statements (no corrections, no call-off stock) to load into the Finančná správa eForm or EPO, check and sign there. Values are not converted between currencies.', 'woolab-ic-dic' ); ?></p>
		</div>
		<?php
	}
}
