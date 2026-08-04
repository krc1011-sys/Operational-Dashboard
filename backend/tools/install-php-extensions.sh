#!/usr/bin/env bash
#
# Installs the PHP extensions OperON needs to read spreadsheets.
#
# The Codespace image ships PHP without ext-zip, which PhpSpreadsheet requires to open
# .xlsx files (an .xlsx is a zip archive). Run this once after creating or rebuilding a
# Codespace; it is safe to run again.
#
#   bash backend/tools/install-php-extensions.sh
#
# ext-gd is NOT installed. PhpSpreadsheet only uses it for embedded images, which we
# never read (all workbooks are opened in data-only mode), so composer.json declares it
# satisfied via config.platform.

set -euo pipefail

if php -r 'exit(extension_loaded("zip") ? 0 : 1);'; then
    echo "✅ ext-zip is already enabled — nothing to do."
    exit 0
fi

PHP_PREFIX="$(php -r 'echo dirname(dirname(php_ini_loaded_file()));')"
CONF_D="${PHP_PREFIX}/ini/conf.d"

echo "→ Installing libzip headers…"
sudo apt-get update -qq
sudo apt-get install -y -qq libzip-dev

echo "→ Building the PHP zip extension…"
printf "\n" | pecl install zip

echo "→ Enabling it in ${CONF_D}/zip.ini…"
sudo mkdir -p "${CONF_D}"
printf "extension=zip.so\n" | sudo tee "${CONF_D}/zip.ini" > /dev/null

if php -r 'exit(extension_loaded("zip") ? 0 : 1);'; then
    echo "✅ ext-zip installed and enabled."
else
    echo "❌ ext-zip still is not loading. Check ${CONF_D}/zip.ini and the extension_dir setting." >&2
    exit 1
fi
