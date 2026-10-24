=== Kybernaut IČO DIČ ===
Contributors: vyskoczilova
Tags: DIČ, IČO, IČ, IČ DPH, VAT number
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 8.1
WC requires at least: 11.0
WC tested up to: 11.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Donate link: https://www.paypal.me/KarolinaVyskocilova

Adds Company & VAT numbers (IČO & DIČ & IČ DPH) to WooCommerce billing fields and verifies if data are correct.

== Description ==

Adds Company & VAT numbers (IČO & DIČ & IČ DPH) to WooCommerce billing fields and verifies if data are correct. Verification is based either on ARES and VIES database or only on mathematics. When billing to Czech republic, you can autofill fields Company, VAT number, Address, City, and Postcode based on IČO.

Works with both the block Checkout and the classic (shortcode) one. In the block checkout the fields are registered through WooCommerce's Additional Checkout Fields API and appear in their own section, revealed by the "Buying as a company" toggle; the values are stored in the same `_billing_ic` / `_billing_dic` / `_billing_dic_dph` order meta as before, so invoicing plugins and exports need no change.

Requires WooCommerce 11.0, WordPress 6.9 and PHP 8.1. For older stacks use the 1.11.x branch.

=== Main functionality ===

* for CZ as billing country
    * ARES and VIES verification (or mathematically verifies IČO and DIČ)
    * ARES autofill (fields Company, VAT number, Address, City, and Postcode) based on IČO, or on the company name picked from search suggestions
* for SK as billing country
    * IČO, DIČ and IČ DPH validated against the Finančná správa lists (imported daily into a local SQLite file, no API key), with VIES as a fallback for IČ DPH
    * autofill (Company, DIČ, IČ DPH, Address, City, Postcode) based on IČO or on the company name picked from search suggestions
    * or just validate the format of values
* for EU countries as billing country
    * VIES DIČ validation
* VAT exempt feature
* adds fields to IČO & DIČ & IČ DPH WooCommerce frontend: Checkout (block and classic) and My Account page
* allows edits from administration (backend):
  * `Users -> Joe Doe (Edit) -> Billing address of the customer`
  * `E-shop-WooCommerce -> Orders-> Order (show(edit)) -> Billing Information (edit)`
* Enable toggle switch to show/hide input fields ("Buying as a company?")
* Move Country field above the "Buying as a company?" toggle
* Option to allow on order if if ARES or VIES check fails for technical reasons - an alert will be shown in the admin mail and order details

=== Compatibility ===
* [Kybernaut Mailstep](https://kybernaut.cz/pluginy/kybernaut-mailstep/) by Karolína Vyskočilová
* [WooCommerce SuperFaktura](https://wordpress.org/plugins/woocommerce-superfaktura/) by 2day.sk & Webikon
* [WooCommerce PDF Invoices & Packing Slips for WooCommerce](https://cs.wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/) by WP Overnight
* WooCommerce PDF Invoices and Packing Slips for WooCommerce PRO by Acowebs
* [WooCommerce Sequential Order Numbers](https://cs.wordpress.org/plugins/woocommerce-sequential-order-numbers/) by SkyVerge
* [Woo Checkout Field Editor Pro](https://cs.wordpress.org/plugins/woo-checkout-field-editor-pro/) by ThemeHigh
* [Fluid Checkout for WooCommerce - Lite](https://wordpress.org/plugins/fluid-checkout/) by Fluid Checkout - only partial support
* Woo iDoklad, Woo Doprava, Woo GoPay etc. by [Toret](https://toret.cz/obchod/ref/106/) (affiliate link)

=== Requirements ===
* WooCommerce 11.0 or newer, PHP 8.1 or newer
* For the Slovak Finančná správa check: the zip, xmlreader and pdo_sqlite (with FTS5) PHP extensions, and about 190 MB in the uploads folder

=== Credits ===

* 10up and their [WordPress.org Plugin Deploy](https://github.com/10up/action-wordpress-plugin-deploy) and [WordPress.org Plugin Readme/Assets Update](https://github.com/10up/action-wordpress-plugin-asset-update) Github Actions
* [ibericode/vat](https://github.com/ibericode/vat)


If you want to help, join the [Github](https://github.com/vyskoczilova/kybernaut-ic-dic).


== Installation ==

1. Just follow the standard [WordPress plugin installation procedure](http://codex.wordpress.org/Managing_Plugins).
1. Go to `WooCommerce->Settings->General` and scroll down for `Kybernaut IČO DIČ options`.


== Frequently asked questions ==

= I want to display values in Woo iDoklad by Vladislav Musílek (Toret) =

Go to `Toret plugins -> Woo iDoklad` and scroll to `Přiřazení polí pro IČ a DIČ` and fill following values:
IČ: `_billing_ic`
DIČ: `_billing_dic`
SK DIČ: `_billing_dic_dph`

= I want to style ARES verified fields =
U can use css selectors `.kbnt-validating`, `.kbnt-ok`, and `.kbnt-wrong` for example:
`.kbnt-wrong input {
    color: #e2401c;
}
.kbnt-ok input {
    color: #0f834d;
}
.kbnt-validating input{
    color: #3d9cd2;
}`

= I want to display fields in the same row, one besides other (half width) =

You can use this snippet to modify the classes of outputed fileds, just add them to your functions.php

`add_filter( 'woolab_icdic_class_billing_ic', 'my_theme_class_billing_ic', 10, 1 );
function my_theme_class_billing_ic ( $class ) {
	return array('form-row-first');
}

add_filter( 'woolab_icdic_class_billing_dic', 'my_theme_class_billing_dic', 10, 1 );
function my_theme_class_billing_dic ( $class ) {
	return array('form-row-last');
}`

= I want to update customers meta when I change IČO or DIČ value within order edit. =

You can use this snippet to update customers data when you edit an order, just add them to your functions.php

`add_filter( 'woolab_icdic_update_user_meta', '__return_true' );`

= I want to make the company name and Business ID (IČO) required for companies. =

Every Czech and Slovak business entity has a Business ID, so you can require the company name and IČO from customers buying as a company, just add this to your functions.php

`add_filter( 'woolab_icdic_required_ic_for_company', '__return_true' );`

With the "Buying as a company" toggle enabled, both Company and IČO are required once the toggle is checked, and the toggle is pre-checked only for customers with a stored IČO. With the toggle disabled, a filled-in Company field is what makes the customer a company, so IČO is required once it is filled in. Works in both the classic checkout and the Checkout block, where the fields also lose their "(optional)" label, so `woolab_icdic_fake_required` is not needed there.

= How can I report security bugs? =

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team help validate, triage and handle any security vulnerabilities. [Report a security vulnerability.](https://patchstack.com/database/vdp/woolab-ic-dic)


= I found a bug. Where should I post it? =

I personally prefer GitHub, to keep things straight. The plugin code is here: [GitHub](https://github.com/vyskoczilova/kybernaut-ic-dic)
But you may use the WordPress Forum as well.

= I found a bug and fixed it. How can I contribute? =

Either post it on [GitHub](https://github.com/vyskoczilova/kybernaut-ic-dic) or—if you are working on a cloned repository—send me a pull request.


== Screenshots ==

1. Checkout validation of IČO.


== Changelog ==

= 2.0.0 (2026-09-01) =

**Breaking:** requires WooCommerce 11.0, WordPress 6.9 and PHP 8.1. Older stacks stay on 1.11.x.

* Feature: full support for the **Cart & Checkout Blocks**. IČO, DIČ and IČ DPH are registered through WooCommerce's Additional Checkout Fields API and rendered in their own section; the "Buying as a company" toggle and the SK-only IČ DPH field are conditional rules WooCommerce evaluates in the browser. Validation reuses the same code path as the classic checkout, so both behave identically, and ARES and VIES keep their result caches.
* Tweak: on the front-end order details (thank-you page, My Account → order) IČO, DIČ and IČ DPH are printed as their own block, `p.woolab-ic-dic-customer-details`, under the billing address instead of as address lines, so a theme can style them. E-mails, the admin order screen and invoices keep them in the address.
* Feature: new opt-in filter `woolab_icdic_required_ic_for_company` makes the company name and IČO required for a company — both when "Buying as a company" is checked, or, with the toggle disabled, IČO when the company name is filled in. Enforced in the classic checkout and, as conditional `required` rules, in the Checkout block (browser and Store API). With the filter on, the toggle is pre-checked only for a customer with a stored IČO.
* Feature: the values are mirrored onto the existing `_billing_ic` / `_billing_dic` / `_billing_dic_dph` order meta, so invoicing plugins, exports and the admin screens keep working unchanged.
* Fix: `{billing_ic}`, `{billing_dic}` and `{billing_dic_dph}` no longer print literally in the block checkout's address preview. The Checkout block hands the address formats to JavaScript, which substitutes from a fixed token table, so the tokens are dropped from that one payload and kept everywhere PHP renders an address.
* Removed: the WooCommerce 2.7 fallbacks (`woocommerce_found_customer_details` and direct `$order->billing_*` property access).
* Removed: `woolab_icdic_add_after_company()`. Field order comes from the `priority` key WooCommerce has honoured since 3.5.1.
* Removed: the "this plugin has settings now" admin notice and its `woolab_icdic_notice_settings` option. The option is left in the database; delete it if you want to be tidy.
* Removed: the self-deactivation guard for a missing WooCommerce. The `Requires Plugins` header lets WordPress enforce that.
* Feature: **Slovak validation and autofill without an API key.** New options "SK: Validate in Finančná správa" and "SK: Validate and autofill based on Finančná správa". The Finančná správa lists of subjects registered for income tax (DIČ) and VAT (IČ DPH) are downloaded nightly by WP-Cron (or `wp woolab-icdic sk-import`) into a SQLite file in uploads (about 190 MB, outside the database); IČO lookup, autofill and the DIČ / IČ DPH checks run locally, so the checkout no longer depends on VIES being up. IČ DPH that is not "SK" + DIČ (VAT groups) is accepted when both belong to the same IČO. An IČO not in the lists yet (new, or not registered for tax) is accepted without autofill. Requires the zip, xmlreader and pdo_sqlite (with FTS5) PHP extensions.
* Tweak: field labels follow the billing country ([#75](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/75)): the DIČ field is "Tax ID" in Slovakia and "VAT ID" everywhere else, IČ DPH is "VAT ID" ("Business ID", "Tax ID" and "VAT reg. no." before). In Slovak a foreign VAT number reads "IČ DPH", in Czech "DIČ". The classic checkout relabels the fields when the country changes; the block checkout has one DIČ field per label (`kybernaut/dic-sk`, `kybernaut/dic-cz`, `kybernaut/dic`), all saved to `_billing_dic`. Addresses in e-mails, invoices and the admin follow the same labels. New filter `woolab_icdic_labels`.
* Feature: option "Business ID only where companies have one" (Validation section, off by default) hides the Business ID for billing countries other than Slovakia and the Czech Republic; the list is filterable with `woolab_icdic_ic_countries`.
* Feature: option "Hide \"(optional)\" on the company fields" (General section): Company, Business ID and the tax numbers lose WooCommerce's "(optional)" in the classic and block checkout, and the VAT ID field reads "VAT ID (enter only if you are a VAT payer)" instead. The `woolab_icdic_fake_required` filter still works and implies it.
* Tweak: VAT error messages name the field as the form labels it ("Enter a valid VAT ID.", "Enter a valid Tax ID.").
* Performance: the VAT exemption from the customer's saved details is set on cart and checkout page loads only, instead of on `init` of every request (a VIES call each time the result was not cached).
* Fix: block checkout VAT exemption. Since WooCommerce 10.9 no draft order exists while the form is filled, so the exemption was never recalculated live, and on placing the order it was set after the totals. It is now recalculated through `extensionCartUpdate()` as the VAT number, the country or the toggle changes, and set before the totals on placing the order.
* Fix: block checkout with "use same address for billing" (the default): the billing country stayed what it was until the order was placed, so the country-dependent fields and the VAT exemption followed the wrong country. It now follows the shipping country.
* Fix: an exemption the plugin set was kept when the shopper switched to the store's own country or a non-EU one (classic and block checkout). Exemptions set by other plugins are left alone.
* Fix: the VAT exemption on page load never applied, and "Buying as a company" was not pre-ticked for a customer with a stored company: the billing country and company were read with `get_meta()`, which returns nothing for core customer properties.
* Fix: "Move Country to top" had no effect: WooCommerce's address script re-sorted the form by the country locale, which still had Country at priority 40, after the company fields. The option now moves it on the default address fields, so the server order and the script agree (also in the shipping and My Account address forms).
* Fix: Slovak and Czech translations: "IČ DPH" used for the DIČ field, trailing spaces in the field labels, "IČ DIČ" for "IČO DIČ", typos.
* Performance: scripts load only where they are used: the block checkout script through WooCommerce's Checkout block integration (settings via `wcSettings`), the classic one on the checkout form only (not on the order-received or pay pages), deferred in the footer; the order screen script only on order screens. The classic checkout no longer adds another set of IČO handlers, and another lookup per keystroke, every time the country changes.
* Tweak: VAT numbers are checked through the VIES REST API instead of SOAP, so the PHP SoapClient extension is no longer needed ("turn on Soap Client" is gone from the settings). ibericode/vat is still used for the number formats.
* Tweak: the IČO lookup and the company name search are REST routes (`GET /wp-json/woolab-ic-dic/v1/lookup`, `/search`) instead of admin-ajax, rate limited per client with WooCommerce's Store API limiter (`RateLimits`, 25 requests per 10 s by default; limit, window and proxy support from `woocommerce_store_api_rate_limit_options`, off with `woolab_icdic_rest_rate_limit`). They answer only for the registers that are switched on. Removed: the `ajaxAres` admin-ajax action.
* Feature: **company name search** in the classic and block checkout. Typing at least 3 letters of the company name lists matching subjects from ARES (CZ) or the Finančná správa lists (SK); picking one fills the IČO and the rest is autofilled. Enabled together with the autofill options.
* Feature: bundled Slovak and Czech translations, loaded on top of the wordpress.org language packs so new strings are translated before the packs catch up.
* Tweak: postcodes are compared without spaces when checking autofilled data.
* Tweak: the settings moved from WooCommerce → Settings → General to their own tab, WooCommerce → Settings → IČO DIČ, with General, Validation, Appearance and Finančná správa sections. The Finančná správa section shows the import status (last import, row counts, database size, next run, last error, PHP extensions); Appearance sets the colours of the status icons and of the company name suggestions.
* Tweak: settings are stored in one autoloaded option, `woolab_icdic_settings`, instead of ten. They are migrated automatically; the old options are kept, unused, so a downgrade to 1.11 still finds them. They will be removed in the next release. `woolab_icdic_get_option()` is deprecated, use `woolab_icdic_setting()`.
* Tweak: `uninstall.php` removes the plugin settings (including the 1.x options) and the Slovak lists.

= 1.11.2 (2026-08-07) =

* Compatibility: Declared compatibility with WordPress 7.0.3 and WooCommerce 11.0.0.
* Fix: The admin order-save handler no longer performs a full `$order->save()` on every order save. IČO/DIČ/IČ DPH meta is now written only when the submitted values actually changed, and persisted via `save_meta_data()` instead of a full order save. This keeps the plugin out of the multi-plugin save cascade on `woocommerce_process_shop_order_meta` that — combined with other plugins calling `$order->save()` on the same hook — could inflate order totals recalculated by WooCommerce core.

= 1.11.1 (2026-07-27) =

* Performance: ARES and VIES lookup results are now cached for 24 hours, so a checkout no longer queries ARES twice for the same IČO and logged-in EU B2B customers no longer trigger a VIES request on every page load. Only definitive answers are cached — registry outages are retried on the next request. Cache duration is filterable via `woolab_icdic_ares_cache_ttl` and `woolab_icdic_vies_cache_ttl`.
* Security: The admin order-save handler now requires the `edit_shop_orders` capability and properly unslashes submitted values before saving order/user meta.
* Security: Company identifiers (IČO, DIČ, IČ DPH) are now HTML-escaped in formatted addresses and PDF invoice output.
* Fix: Slovak DIČ validation now returns a proper boolean — previously an all-zero DIČ passing the format check was wrongly treated as invalid.
* Fix: ARES address parsing no longer causes errors when address fields (street, postcode, city) are missing in the registry response.
* Fix: The legacy WooCommerce < 2.7 AJAX customer-details handler now sanitizes the `user_id` parameter.
* Fix: SuperFaktura compatibility (thanks @lukasprelovsky)

= 1.11.0 (2026-06-29) =

* Maintenance: Internal code refactoring and added automated tests. No change to checkout behaviour, validation messages, or settings.
* Fix: The SK-only "VAT reg. no." (IČ DPH) field now shows only for Slovak orders on the admin order edit screen. Two issues were fixed: an off-by-one country comparison (`$country[0]` only checked the first character) in the field definition, and the country-based show/hide script not loading on HPOS order edit screens, which left the field visible on Czech orders.
* Fix: Fatal error on every page load for logged-in EU B2B customers when VIES was unavailable — `ViesException` was re-thrown from the `init` hook and the `update_order_review` AJAX call; the exception is now caught, logged, and treated as "not exempt" instead.
* Fix: ARES mismatch notice for Tax ID field incorrectly reported the field as "Business ID".
* Fix: Leftover debug `get_user_meta()` call appended stray text to the Settings link in the admin notice.
* Fix: Admin script/style enqueue arguments — `wp_enqueue_style()` received the plugin URL as its dependencies parameter; `admin-edit.js` and `admin.js` shared the same handle so one silently failed to load; version parameter was missing from all admin enqueues.
* Fix: ARES autofill checkbox setting default changed from the invalid `'false'` to the correct `'no'`.
* Security: `ajaxAres` endpoint now verifies a nonce and sanitizes the `ico` request parameter.
* Security: Notice-dismiss AJAX endpoint is restricted to authenticated users with the `manage_woocommerce` capability and now requires a nonce.
* Security: Raw `$_POST` reads in checkout validation wrapped in `wc_clean( wp_unslash() )`, consistent with the rest of the codebase.

= 1.10.5 (2026-03-25) =

* Feature: Added a setting to disable the DIČ / IČ DPH match validation for Slovakia. Useful for non-profit organizations where IČ DPH does not match "SK" + DIČ. Go to WooCommerce > Settings > General > Kybernaut IČO DIČ options.

= 1.10.4 (2026-03-19) =

* Fix: VAT exemption not applied when "Buying as a company" toggle is disabled. When the toggle was off (fields always visible), the VAT exempt check silently failed because it required a non-existent checkbox to be checked.

= 1.10.3 (2026-03-18) =

* Fix: "Trying to access array offset on null" error on checkout when `shipping_country` or `ship_to_different_address` POST fields are not set (e.g. when shipping to billing address).
* Fix: Added missing `isset()` checks in VAT exempt validation for parsed POST data.

= 1.10.2 (2025-11-28) =

* Fix: Don't autofill DIC (Tax ID) for companies with terminated VAT registration (`ZANIKLY`). The plugin now checks VAT registration status from ARES and only autofills DIC when the status is `AKTIVNI` (active). See [support forum](https://wordpress.org/support/topic/dic-v-pripade-zanikle-registrace-k-dph/).

= 1.10.1 (2025-05-13) =

* Added a new filter `woolab_icdic_enable_dic_dicdph_match_check` to disable the DIČ and IČ DPH match validation. For more details, see the [support thread](https://wordpress.org/support/topic/ic-dph-validacia/#post-18440969).

= 1.10.0 (2025-04-24) = 

* Performance improvement: function donetyping is replaced by debounce function by [@morvy](https://github.com/morvy), [#74](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/74) fixing issue [#73](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/73)
* Fix: Shipping country and VAT country prefix must match (paid by a supporter) (before only billing & VAT number matched).
* Feature: Add basic logger.

= 1.9.1 (2024-11-14) =

* Fix: Greek VAT number validation - VAT country prefix (EL) doesn't match WooCommerce coutry code (GR).

= 1.9.0 (2024-06-28) =

* Feature: New checkbox "Ignore when ARES or VIES check fails for technical reasons" in plugin settings. If this option is enabled and validation of business ID or VAT number fails for technical reason (not because it is invalid), an order is placed. For orders at which validation fails, there is an alert at admin edit screen and in admin e-mail. Big thanks to [@PavelVybiral](https://github.com/PavelVybiral) [#72](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/72)

= 1.8.2 (2024-01-09) =

* Feature: added support for [WooCommerce PDF Invoices and Packing Slips for WooCommerce](https://wordpress.org/plugins/pdf-invoices-and-packing-slips-for-woocommerce/) by Acowebs (sponsored by [tuningmotocyklov.sk](https://www.tuningmotocyklov.sk))
* Fixed compatibility with Fluid Checkout (using the JS they provided me)

= 1.8.1 (2024-01-05) =

* Fix: Bump minimum PHP compatibility to 7.3 (will be increased soon to 8.0).

= 1.8.0 (2024-01-05) = 

* Fix: VAT exempt checkbox default to off.
* Fix: Work with new ARES API (the old in previous versions has been discontinued by the end of 2023). For initial solution and pointing to the thanks to [@lukas-tomoszek](https://github.com/lukas-tomoszek).
* Feature: Prefix dependencies to avoid conflicts (using [wpify/scoper](https://packagist.org/packages/wpify/scoper))
* Add test validating Ares REST API check and processing.

= 1.7.5 (2023-12-19) =

* Fix: Accidentally broken toggle switch in 1.7.4.
* Declare incompatibility with checkout blocks.

= 1.7.4 (2023-11-20) =

* Fix: Additional check - billing country and VAT country prefix must match for SK IC DPH.
* Feature: Added compatibility with [Fluid Checkout for WooCommerce - Lite](https://wordpress.org/plugins/fluid-checkout/).
* Feature: Added a filter `woolab_icdic_check_billing_country_and_dic` allowing to disable the feature introduced in 1.7.3.

= 1.7.3 (2023-10-08) =

* Feature: Localize address format for all EU countries.
* Feature: Additional check - billing country and VAT country prefix must match (paid by a supporter).

= 1.7.2 (2023-08-02) =

* Fix: non HPOS WooCommerce edit order - load IC DIC values [#60](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/60)
* Fix: ICO - load the city district (NCO) if the street (NO) is not filled in (case when the street = city name) [#62](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/62)
* Several code updates and cleanup [#61](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/61)

= 1.7.1 (2023-07-26) =

* Fix: Fatal error while updating manually order status [#59](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/59)

= 1.7.0 (2023-07-25) =

* Feature: HPOS support - together with [@morvy](https://github.com/morvy), [#55](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/55)
* Feature: VAT extempt - thanks to [@morvy](https://github.com/morvy), [#48](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/48)
* Feature: Math validation for Slovak IC DPH - thanks to [@morvy](https://github.com/morvy), [#56](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/56)
* Fix: Checked "Buying as a company" while any of Company related fields is filled - thanks to [@morvy](https://github.com/morvy), [#48](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/48)
* Fix: Validation of CZ DIC with different length than 10 numbers  - thanks to [@morvy](https://github.com/morvy), [#56](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/56)

= 1.6.7 (2022-01-12) =

* Fix: Checkbox buy as a company - data are sent even when unchecked - thanks to [@morvy](https://github.com/morvy)

= 1.6.6 (2021-11-05) =

* Fix: jQuery 3.x compatibility [#41](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/41) - thanks to [@morvy](https://github.com/morvy)
* Fix: Notice: Undefined variable: dic [#43](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/43) - thanks to [@morvy](https://github.com/morvy)

= 1.6.5 (2021-10-01) =

* Fix: VAT label CSS bug [#40](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/40) - thanks to [@morvy](https://github.com/morvy)

= 1.6.4 (2021-04-05) =
* Fix: prefix toggle CSS class.
* Fix: call an old function in SK DIČ validation.
* Improve: remove duplicate class on IČ DPH.
* Improve: validate ARES only when CZ selected (when the country is re-selected again).
* Load toggle CSS only when used.

= 1.6.3 (2021-02-24) =
* Feature: Add compatibility with PHP 8

= 1.6.2 (2021-02-24) =
* Fix: VAT Validation error.

= 1.6.1 (2021-02-24) =
* Bump the version after SVN issues with "vendor" file

= 1.6.0 (2021-02-24) =
* Fix: Add correct classes on checkbox input for "Buy as Company"
* Update dependencies: VAT composer library to 2.0.5
* Bump minimum requirements to 7.1 (due to Composer dependency)

= 1.5.4 (2020-12-02) =
* Fix: Don't validate without billing country [#27](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/27) - thanks to [@morvy](https://github.com/morvy)

= 1.5.3 (2020-11-09) =
* Compatibility with [WooCommerce SuperFaktura](https://wordpress.org/plugins/woocommerce-superfaktura/)
* Compatibility [Kybernaut Mailstep](https://kybernaut.cz/pluginy/kybernaut-mailstep/)

= 1.5.2 (2020-10-13) =
* Fix wrong assets path.
* Use more general CSS selectors for better compatibility.

= 1.5.1 (2020-09-17) =
* Fix issues with quotes - [#25](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/25)
* Add custom filter for disabling required DIC when ICO filled for SK `add_filter( 'woolab_icdic_sk_required_ic_and_dic', '__return_false' );` - [#26](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/26)

= 1.5.0 (2020-07-20) =
* Fix: Slovak DIC validation fix, [#22](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/22) - thanks to [@morvy](https://github.com/morvy)
* Feature: Show/Hide toggle functionality, [#24](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/24) - [@morvy](https://github.com/morvy)
* Maintenance: Update language files and dependencies

= 1.4.0 (2019-09-05) =
* Fix: Strip spaces from ICO, DIC, DIC DPH fields ([#8](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/8))
* Fix: Validation of Slovak DIČ in Vies ([#9](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/9))
* Fix: Trigger update_checkout JS when address loaded
* Performance: Don't validate IČO in ARES when the value has not been changed, called after 'donetyping'
* Maintenance: Update node_modules

= 1.3.3 (2018-11-18) =
* Compatibility with WC 3.5.1 which has "fixed" old ordering of checkout fields [#21763](https://github.com/woocommerce/woocommerce/pull/21763)

= 1.3.2 (2018-08-14) =
* Fix: Use number for address in ARES when no correct land registry and hous number are not filled ([@pryx](https://github.com/vyskoczilova/kybernaut-ic-dic/pull/6))

= 1.3.1 (2018-06-03) =
* Fix: Show "IČ DPH" on "Manually add new order" screen when "Slovakia" selected
* Fix: Correct validation "IČ DPH" (with SK prefix) and "DIČ" (without SK prefix)
* Fix: Remove the WC nonce check (already checked in WC itself)
* Fix: Problem with loading plugin options
* Added "How to" for Woo iDoklad to readme.txt
* Update .pot source file.

= 1.3.0 (2018-02-21) =
* Fix: Display "VAT reg. no." field in Order Billing-edit.
* Fix: Compatibility with WooCommerce Sequential Order Numbers ([#3](https://github.com/vyskoczilova/kybernaut-ic-dic/issues/3))
* Performance: CSS in admin.
* Feature: Validation for Czech Business ID (via ARES)
* Feature: Autofill for fields such as Company, VAT number, Address, City, and Postcode based on Czech Business ID (via ARES)
* Feature: Validation of VAT (via [VIES](https://github.com/dannyvankooten/vat.php))
* Added: Plugin settings to `WooCommerce->Settings->General`
* Fields moved after "company" field.

= 1.2.0 (2018-02-08) =
* Fixed: Editing Business ID and VAT values within order in admin backend
* Feature: Texts in plugin only in English (Czech as a translation)
* Feature: Added `woolab_icdic_class_{field_name}` filters to customize class of added billing input fields
* Feature: Added `woolab_icdic_update_user_meta` filter to enable updating user meta on order details edit
* Added: Links to GitHub and Write a review to plugins page.
* Added: Basic SK support (based on this [article](https://podnikam.webnoviny.sk/ico-dic-ic-dph-co-znamenaju-tieto-skratky-a-kde-ich-hladat/))

= 1.1.0 (2017-04-05) =
* Fix: "Order properties should not be accessed directly." Added support for WooCommerce 3.0.

= 1.0.3 (2017-02-1) =
* Fix: display fields in My Account frontend page.
* Add: support for WooCommerce PDF Invoices & Packing Slips.

= 1.0.2.1 (2016-12-15) =
* Feature: Created the GIT repository

= 1.0.2 (2016-09-05) =
* Fix: the term "IČ" to "IČO".

= 1.0.1 (2016-07-18) =
* Feature: Checks billing country and validates IC & DIC if is set to CZ

= 1.0.0 (2016-07-16) =
* Initial release
