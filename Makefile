.PHONY: build start translate version-bump install test release

# Build all assets (@wordpress/scripts, webpack.config.js)
build:
	npm run build

# Rebuild on change, unminified
start:
	npm run start

# Translations: .pot, .po, .mo, .l10n.php
translate:
	composer i18n

# Install dependencies
install:
	npm install
	composer install

# Run tests
test:
	composer test:unit

# Bump version number
version-bump:
	@bash bin/version-bump.sh

# Create a GitHub release from the current plugin version
release:
	@bash bin/release.sh
