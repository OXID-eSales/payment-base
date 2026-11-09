#!/bin/sh
#
# Copyright © OXID eSales AG. All rights reserved.
# See LICENSE file for license details.
#
# Repairs the layout the unified-namespace generator writes on OXID 7.1-7.3 (OXDEV-10249).
#
# With symfony/filesystem >= 6.4.48 (older releases are blocked by advisory
# PKSA-g499-cm4q-v35y) `Generator::createUnifiedNamespaceSubDirectory()` no longer turns
# "OxidEsales\Eshop\Core" into the nested OxidEsales/Eshop/Core/ hierarchy: the 500+
# `Eshop\` shim files land in ONE directory literally named `OxidEsales\Eshop\Core`, PSR-4
# cannot find them, and every `oe-console` call dies with
# `Class "OxidEsales\Eshop\Core\ConfigFile" not found`. OXID fixed the generator only on
# b-7.4.x (v5.2.1), which needs twig ^3.14 that the 7.1/7.2 lines cannot take, so the shops
# below 7.4 are repaired after the fact: a pure rename of the backslash-named directories
# into the forward-slash hierarchy. No generator invocation, no autoload manipulation, a
# no-op when the layout is already correct.
#
# The generator runs on composer's post-install-cmd / post-update-cmd, so this must follow
# EVERY shop-level `composer install` / `composer update`. Runs inside the php container:
#
#   docker compose exec -T php sh /var/www/test-module/.github/scripts/fix-unified-namespace-dirs.sh
#
# Usage: fix-unified-namespace-dirs.sh [generated-dir]

set -eu

GENERATED="${1:-/var/www/vendor/oxid-esales/oxideshop-unified-namespace-generator/generated}"
cd "$GENERATED"

find . -mindepth 1 -maxdepth 1 -type d -name '*\\*' | sort -r | while read -r src; do
    target=$(printf '%s' "${src#./}" | sed 's|\\|/|g')
    echo "renaming '$src' -> '$target'"
    mkdir -p "$(dirname "$target")"
    if [ -d "$target" ]; then
        cp -rT "$src" "$target" && rm -rf "$src"
    else
        mv "$src" "$target"
    fi
done

if [ ! -f OxidEsales/Eshop/Core/ConfigFile.php ]; then
    echo "unified namespace layout still broken: OxidEsales/Eshop/Core/ConfigFile.php missing in $GENERATED" >&2
    exit 1
fi
echo "unified namespace layout OK: $(find OxidEsales -name '*.php' | wc -l) shim files under $GENERATED/OxidEsales"
