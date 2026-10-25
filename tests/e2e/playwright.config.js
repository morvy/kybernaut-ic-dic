// End-to-end tests of the classic and block checkout, the order and the settings.
//
// In WordPress Playground, on the blueprint tests/e2e/stack.js wrote:
// `npm run test:e2e:min`, `test:e2e:latest`, or both with `npm run test:e2e`.
//
// On any other WordPress with WooCommerce and this plugin, set up once with
// tests/e2e/setup.php: E2E_BASE_URL=https://woolab-test.local npm run test:e2e:site
// (E2E_ADMIN_USER / E2E_ADMIN_PASSWORD for the settings tests, admin / password by
// default, as in Playground).
const { defineConfig } = require( '@playwright/test' );

const port = 9411;
const site = process.env.E2E_BASE_URL;

module.exports = defineConfig( {
	testDir: __dirname,
	timeout: 90000,
	expect: { timeout: 15000 },
	// One shop: the specs share its settings and the rate limit.
	workers: 1,
	use: {
		baseURL: site || `http://127.0.0.1:${ port }`,
		// Local sites run on self-signed certificates.
		ignoreHTTPSErrors: true,
	},
	webServer: site ? undefined : {
		command: `npx wp-playground-cli server --port=${ port } --mount=.:/wordpress/wp-content/plugins/woolab-ic-dic --blueprint=tests/e2e/.blueprint.json`,
		cwd: `${ __dirname }/../..`,
		// Printed once the blueprint has run; any URL answers earlier (static files),
		// and PHP ones redirect to themselves to set Playground's cookie.
		wait: { stdout: /Ready!/ },
		timeout: 300000,
		// A server left running may be the other stack.
		reuseExistingServer: false,
	},
} );
