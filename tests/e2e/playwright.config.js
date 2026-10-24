// End-to-end tests of the block checkout on the oldest supported stack, in
// WordPress Playground: `npm run test:e2e`. The versions are pinned in
// blueprint.json (preferredVersions wins over the CLI's --wp / --php).
const { defineConfig } = require( '@playwright/test' );

const port = 9411;

module.exports = defineConfig( {
	testDir: __dirname,
	timeout: 90000,
	expect: { timeout: 15000 },
	workers: 1,
	use: { baseURL: `http://127.0.0.1:${ port }` },
	webServer: {
		command: `npx wp-playground-cli server --port=${ port } --mount=.:/wordpress/wp-content/plugins/woolab-ic-dic --blueprint=tests/e2e/blueprint.json`,
		cwd: `${ __dirname }/../..`,
		// Printed once the blueprint has run; any URL answers earlier (static files),
		// and PHP ones redirect to themselves to set Playground's cookie.
		wait: { stdout: /Ready!/ },
		timeout: 300000,
		reuseExistingServer: ! process.env.CI,
	},
} );
