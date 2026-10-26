<?php
/**
 * WooCommerce's order classes, empty: Mockery extends them, so a refund's mock
 * passes a WC_Abstract_Order type hint as the real refund does.
 */

abstract class WC_Abstract_Order {}

class WC_Order extends WC_Abstract_Order {}

class WC_Order_Refund extends WC_Abstract_Order {}
