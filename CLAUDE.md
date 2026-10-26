# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Kybernaut IC DIC** is a WordPress/WooCommerce plugin that adds Czech and Slovak company identification fields (Business ID, Tax ID, VAT registration number) to WooCommerce checkout forms. It validates these numbers against official registries (ARES for Czech Republic, VIES for EU VAT) and supports automatic VAT exemption for valid intra-EU B2B transactions.

- **Main plugin file**: `woolab-ic-dic.php`
- **PHP compatibility**: 8.1+
- **WooCommerce compatibility**: 10.0+ (WordPress 6.7+) (HPOS and Cart & Checkout Blocks compatible)

## Build and Development Commands

### Building assets
```bash
# Install dependencies
npm install

# Build the JS and CSS (@wordpress/scripts, webpack.config.js) into assets/
npm run build    # minified, with a .asset.php per script (dependencies, version)
npm run start    # unminified, rebuilt on change

# Translations
composer i18n    # .pot, .po, .mo, .l10n.php
```

### Running tests
```bash
# Install PHP dependencies for testing
composer install

# Run unit tests
composer test:unit
# or directly:
vendor/bin/phpunit --testsuite Unit
```

The unit tests run on PHPUnit 12 (PHP 8.3+) with Brain Monkey for the WordPress functions, without a WordPress installation. Every test extends `tests/TestCase.php`, which sets Brain Monkey up and stubs translation, escaping and a few helpers; `AresTest` reads live ARES.

End-to-end tests run the checkouts in WordPress Playground with live ARES (VIES is
answered by `tests/e2e/mu-plugin.php`: its registers are too often busy), on two
stacks that `tests/e2e/stack.js` resolves into `tests/e2e/.blueprint.json`
(`setup.php` fails the boot if Playground serves anything else):
- `min`: the plugin header's minimums (WC requires at least → its newest patch,
  Requires at least, Requires PHP);
- `latest`: the newest WordPress and WooCommerce from the WordPress.org APIs, on the
  newest PHP Playground supports. CI also runs it weekly.
```bash
npx playwright install chromium   # once
npm run test:e2e                  # both; or test:e2e:min / test:e2e:latest (port 9411)
```

The same specs run against any site with WooCommerce and this plugin, set up once
with `wp eval-file wp-content/plugins/woolab-ic-dic/tests/e2e/setup.php` (it can run
again): a Slovak shop, a classic checkout at /classic-checkout/ next to the block one,
and an mu-plugin that switches between them by cookie. The local one is the ddev
project `woolab-test` (`~/_DEV/ideas/woolab-test`, WordPress + WooCommerce + Blocksy,
this checkout mounted as the plugin, admin / password):
```bash
E2E_BASE_URL=https://woolab-test.local npm run test:e2e:site
```
Specs: `block-checkout`, `classic-checkout` (fields, labels, ARES fill, VIES exemption,
placed order), `rest` (arguments, rate limit), `settings` (every section saves, settings
reach the checkout). Not covered: the Finančná správa import (190 MB download).

### Static analysis
```bash
composer lint            # PHPCS, PHPStan and Rector (dry run)
composer lint:phpcs      # WooCommerce coding standards (phpcs.xml.dist)
composer lint:phpstan    # level 6, WordPress / WooCommerce / WP-CLI stubs, WooCommerce feature checks (phpstan.neon.dist)
composer lint:rector     # PHP 8.1, WordPress up to 6.7 and WooCommerce up to 10.0 deprecations (rector.php)
```

The whole codebase passes all three, without baselines: fix a finding, or skip a rule in
the tool's config with the reason (rector.php skips three, see there).

## Architecture

### Code Organization

**PHP Structure** (namespace `KybernautIcDic\`, PSR-4 from `src/`, Composer autoload):
- `woolab-ic-dic.php` - constants, `vendor/autoload.php`, `Plugin::boot()`
- `src/Plugin.php` - loads the translations, then calls the static `register()` of every class in `PARTS` (each hooks itself); HPOS / blocks compatibility declaration
- `src/functions.php` - the public `woolab_icdic_*` functions, thin wrappers over the classes (autoload "files"); keep them, themes and plugins call them
- `src/Settings/` - `Options` (the one `woolab_icdic_settings` option, 1.x migration), `Flags` (each switch: setting + its 1.x filter), `SettingsPage`, `Fields` (the settings sections), `FilterOverrides` (a setting a filter decides over)
- `src/Fields/` - `Labels` (labels by billing country), `Countries` (base country, IČO countries, fields mode)
- `src/Validation/` - `Validator` runs the checks in `Checks/` (one class per rule, `Check` interface, `AbstractCheck` base); `Context` (the checkout input, its country's services, lookups memoised), `Result` (errors / notices per field), `Errors` (code → field and message), `SkLists`
- `src/Services/` - one class per external service: `Ares/Ares.php`, `Vies/Vies.php`, `FinancnaSprava/` (`FinancnaSprava`, `Importer`: daily WP-Cron import + WP-CLI `wp woolab-icdic sk-import`, `Storage`: the SQLite file in uploads); `Service` interface, `AbstractService`, `CallbackService` (closures), `Services` (the country → feature → services map)
- `src/Numbers/Checksum.php` - mathematical IČO / DIČ / RČ / NIP / adószám checks
- `src/Checkout/` - `Classic` (shortcode checkout fields, `checkout_field_process`), `Blocks` (Additional Checkout Fields API, Store API validation), `BlocksIntegration`, `CartExtension`, `VatExempt`
- `src/Order/` - `Meta` (order meta), `AdminScreen` (admin billing fields, check-failed notices), `Display` (addresses, order details, e-mails), `Customer` (user meta)
- `src/Rest/Routes.php` - `woolab-ic-dic/v1/lookup` and `/search`
- `src/Assets.php` - enqueues, script data (per-country `services`), colour CSS variables
- `src/Admin/PluginLinks.php`, `src/Logger.php`
- `src/Compat/` - `SuperFaktura`, `PdfInvoices`, `FluidCheckout` (not registered by Plugin)
- `uninstall.php` - removes the settings and the Slovak lists

**Frontend Assets:**
- `resources/` - Source files (unminified)
  - `js/classic/checkout.js` - Classic checkout (jQuery): IČO lookup and autofill → `assets/js/public.js`
  - `js/blocks/checkout.js` - Block checkout: same checks against the WooCommerce Blocks data stores → `assets/js/blocks.js`
  - `js/shared/` - Bundled into both: `lookup.js` (REST /lookup and /search, the per-country `services` of the script data), `suggest.js` (company name suggestions), `status.js` (spinner, debounce)
  - `js/admin/settings.js` - Admin settings page → `assets/js/admin.js`
  - `js/admin/order.js` - Order edit page → `assets/js/admin-edit.js`
  - `css/` - lookup.css (status icons, suggestions), style.css (toggle), admin.css → `assets/css/`
- `assets/` - Built files (npm run build)
- `languages/` - .pot and the bundled translations (`composer i18n`)

**Dependencies:**
- No bundled third-party code: the EU country list is WooCommerce's (`Fields\Countries::in_eu()`), the VAT number formats `Numbers\Checksum::vat_format()`, VIES is called over REST (`src/Services/Vies/Vies.php`)

### Key Architectural Patterns

**Field Registration Flow:**
1. Classic: `Checkout\Classic` adds the fields on `woocommerce_billing_fields` / `woocommerce_checkout_fields`, validates on `woocommerce_checkout_process` (`checkout_field_process`), `Order\Meta` saves them
2. Blocks: `Checkout\Blocks` registers `kybernaut/*` fields (Additional Checkout Fields API), validates on `woocommerce_blocks_validate_location_order_fields`, mirrors them to the `_billing_ic` / `_billing_dic` / `_billing_dic_dph` meta
3. Both build a `Validation\Context` and run `Validation\Validator::run()`; `Errors` puts each finding under its field
4. Admin: `Order\AdminScreen` on `woocommerce_admin_billing_fields`

**Checks** (`src/Validation/Checks/`, order in `Validator::default_checks()`):
- Each implements `Check`: `id()`, `field()` ('company', 'ic', 'dic', 'dic_dph'), `applies( Context )`, `run( Context, Result )`
- Filters: `woolab_icdic_checks` (add / replace by id / remove), `woolab_icdic_check_applies`, `woolab_icdic_check_result`, `woolab_icdic_validation_result`
- Checks ask registers only through `Context` (`company()`, `tax_id()`, `vat_id()`), never a service directly; `Checks\TaxId::route()` picks a DIČ's one route (domestic tax number, VIES, CZ format, SK)

**Services** (`src/Services/`):
- Features: `company_search`, `company_lookup`, `tax_id`, `vat_id` (`Service` methods `search_companies()`, `lookup_company()`, `validate_tax_id()`, `validate_vat_id()`; null = not supported)
- `Services::map()`: country ('*' = every other) => feature => services asked in order; defaults follow the settings (CZ: ARES, SK: Finančná správa then VIES for the VAT ID, '*': VIES), `woolab_icdic_services` replaces or adds
- `Services::autofill( $country )` = a `company_search` service is assigned; the script data lists lookup / search / autofill per country, so a store's own service autofills and suggests in both checkouts
- `Services::uses( 'financna_sprava' )` gates the daily import

**Validation Strategy:**
- **Czech IC (Business ID)**: `BusinessIdFormat` (checksum) + `BusinessIdRegistry` (ARES when `ares_check` is on; with `ares_fill`, the entered company is compared)
- **Czech DIC (Tax ID)**: RČ (birth number) or IČO-based, `TaxIdFormat`; a CZ DIČ VIES does not list is a non-payer, not an error
- **Slovak IC**: Finančná správa lists when `sk_check` is on (an IČO not listed is accepted, without autofill), otherwise the 8-digit modulo-11 check
- **Slovak DIC**: Optional (non-profits have an IČO but no DIČ; "Require DIČ" for the shop's own country, `woolab_icdic_sk_required_ic_and_dic` decides for a Slovak shop), `TaxIdFormat`, then `TaxIdRegistry` (listed, belongs to the IČO)
- **Slovak DIC DPH (VAT)**: `IcDphVerify` (lists first, VIES for what they lack), `IcDphTaxIdMatch` (SK + DIČ unless the lists tie both to the same IČO: VAT groups)
- **EU VAT numbers**: `VatIdVies` (format, VIES REST); `DomesticTaxNumber` for a PL / HU number without prefix
- **Other countries' Business ID**: checked by any `company_lookup` service a store assigns

**ARES Integration** (`src/Services/Ares/Ares.php`):
- REST endpoint `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}`, answers cached (`woolab_icdic_ares_cache_ttl`)
- `lookup_company()` returns `error` false + `spolecnost`, `ico`, `dic`, `adresa`, `psc`, `mesto` (the shape every service returns); `search_companies()` for the name suggestions
- `Rest\Routes` `woolab-ic-dic/v1/lookup` and `/search` answer for any country with a service, public, rate limited with WooCommerce's Store API `RateLimits` (`woolab_icdic_rest_rate_limit`)
- Graceful degradation: `internal_error` + "Ignore when a check fails" (`woolab_icdic_ignore_check_fail`) lets the order through with a notice in the admin

**VAT Exemption Logic:**
- Automatically sets `WC_Customer->set_is_vat_exempt()` for valid intra-EU B2B transactions
- Only applies when:
  - Base country differs from billing country
  - Both countries are in EU VAT area
  - Valid VAT number (`Services::vat_state()`: the country's `vat_id` services, VIES when none)
- `Checkout\VatExempt`; the `vat_exempt` setting and `woolab_icdic_vat_exempt_enabled`

**HPOS Compatibility:**
- Plugin declares compatibility via `FeaturesUtil::declare_compatibility()`
- Different hooks for legacy vs HPOS: `manage_shop_order_posts_custom_column` vs `woocommerce_shop_order_list_table_custom_column`
- Order retrieval works with both CPT and HPOS via `wc_get_order()`

### Country-Specific Behavior

**Czech Republic (CZ):**
- IC (Business ID): 8 digits, validated against ARES
- DIC (Tax ID): CZ + (IC or RC), optional
- ARES autofill can populate company name, address, postcode, city

**Slovakia (SK):**
- IC (Business ID): 8 digits, Finančná správa lists or the checksum
- DIC (Tax ID): 10 digits, optional (non-profits have none; not a VAT number)
- DIC DPH (VAT registration): SK prefix + 10 digits, optional, the lists then VIES
- Special validation: DIC DPH must match DIC with SK prefix

**Other EU Countries:**
- Only DIC field shown (relabeled as VAT number)
- Validated via VIES

### Important Filters for Customization

- `woolab_icdic_checks`, `woolab_icdic_check_applies`, `woolab_icdic_check_result`, `woolab_icdic_validation_result` - the checks (`Validation\Validator`)
- `woolab_icdic_services` - which service answers which feature per country (`Services\Services`)
- `woolab_icdic_class_billing_{field}` - Modify field CSS classes
- `woolab_icdic_ares_check`, `woolab_icdic_ares_fill`, `woolab_icdic_sk_check`, `woolab_icdic_sk_fill`, `woolab_icdic_vies_check` - the registers' settings (`Settings\Flags`)
- `woolab_icdic_ignore_check_fail` - Allow orders even if a register cannot answer
- `woolab_icdic_vat_exempt_enabled` - Control VAT exemption feature
- `woolab_icdic_base_country` - Override base country for VAT exemption logic
- `woolab_icdic_ic_countries` - countries where every company has an IČO (CZ, SK)
- `woolab_icdic_labels` - field labels by country
- `woolab_icdic_sk_required_ic_and_dic` - Require the DIC for a Slovak shop (default: the "Require DIČ" setting, off)
- `woolab_icdic_rest_rate_limit` - rate limit of the REST routes
- `woolab_icdic_update_user_meta` - Update user profile when editing orders

A filter that decides over a setting is shown on the settings page (`Settings\FilterOverrides`).

### Known Limitations

- Fluid Checkout compatibility exists but is not registered: call `\KybernautIcDic\Compat\FluidCheckout::register()` from the theme or a plugin
- Hook callbacks are class methods: `remove_action()` needs `array( 'KybernautIcDic\…\Class', 'method' )`, the 1.x function names no longer match

## Common Development Patterns

### Adding a check
A class in `src/Validation/Checks/` extending `AbstractCheck` (set `$field`, implement `id()`, `applies()`, `run()`), added to `Validator::default_checks()` where its errors should be listed. Error codes need a message and field in `Validation\Errors` (or pass the message to `Result::add_error()`). Number rules (checksums) go in `Numbers\Checksum` as static methods: boolean, clean input (no whitespace). Ask registers through `Context`.

### Adding a service
A class extending `Services\AbstractService` (`id()`, `name()`, the features it supports, returning the shapes documented in `Services\Service`), assigned in `Services::map()` behind its setting (`Settings\Flags`). The checks, both checkouts, the REST routes and the script data pick it up from the map. Store-side services go through `woolab_icdic_services` (readme FAQ has a Polish example).

### Modifying ARES response parsing
`src/Services/Ares/Ares.php`. The API returns JSON with `obchodniJmeno` (company name), `ico`, `dic`, and the `sidlo` object with the address.

### Tests
Unit tests go in `tests/unit/` and extend `KybernautIcDic\Test\TestCase` (`tests/TestCase.php`). Mock WordPress functions with Brain Monkey: `Functions\when( 'fn' )->justReturn( … )` for a stub, `Functions\expect( 'fn' )->once()` for an expectation (see `tests/unit/ProcessShopOrderTest.php`); settings via `get_option` returning the `woolab_icdic_settings` array. Data providers are `#[DataProvider( 'name' )]` attributes on public static methods. Checks and filters: `ValidatorTest`, the 1.x validation cases: `ValidationTest`, `ValidationSkTest`. End-to-end specs in `tests/e2e/` (`mu-plugin.php` fakes VIES and, by cookie, a store's check or Polish service).
