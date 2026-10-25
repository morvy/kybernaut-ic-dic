<?php
/**
 * Rows of the Finančná správa lists.
 *
 * @package KybernautIcDic
 */

namespace KybernautIcDic\Validation;

/**
 * Comparing a listed number's IČO with the one entered.
 */
final class SkLists {

	/**
	 * Whether a row belongs to another IČO than the one entered. Rows without an
	 * IČO, or no IČO entered, never count as a mismatch.
	 *
	 * @param array<string,mixed> $row ['ico' => string] from the lists.
	 * @param string              $ico Entered IČO, whitespace stripped.
	 */
	public static function ico_differs( array $row, string $ico ): bool {
		return '' !== $row['ico'] && '' !== $ico && str_pad( $ico, 8, '0', STR_PAD_LEFT ) !== $row['ico'];
	}
}
