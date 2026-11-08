<?php
/**
 * WooCommerce's order classes, empty: Mockery extends them, so a refund's mock
 * passes a WC_Abstract_Order type hint as the real refund does. And OrderUtil,
 * whether orders are in WooCommerce's own tables (HPOS): a test sets $hpos.
 */

namespace {
	abstract class WC_Abstract_Order {}

	class WC_Order extends WC_Abstract_Order {}

	class WC_Order_Refund extends WC_Abstract_Order {}
}

namespace Automattic\WooCommerce\Utilities {
	class OrderUtil {
		public static $hpos = true;

		public static function custom_orders_table_usage_is_enabled() {
			return self::$hpos;
		}
	}
}
