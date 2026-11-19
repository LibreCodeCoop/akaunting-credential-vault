#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Fail-closed checks without database access or a real Git deployment.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
SCRIPT="$ROOT/scripts/post-git-checkout.sh"

TEMP="$(mktemp -d /tmp/credential-vault-git-deploy.XXXXXXXX)"
cleanup() {
    if [[ "$TEMP" == /tmp/credential-vault-git-deploy.* && -d "$TEMP" && ! -L "$TEMP" ]]; then
        rm -rf -- "$TEMP"
    fi
}
trap cleanup EXIT

APP="$TEMP/akaunting"
mkdir -p "$APP/bootstrap" "$APP/modules/CredentialVault"
echo '<?php' > "$APP/bootstrap/app.php"
echo '#!/bin/sh' > "$APP/artisan"
echo 'exit 92' >> "$APP/artisan"
chmod 0700 "$APP/artisan"
echo 'APP_ENV=local' > "$APP/.env"
echo '{"alias":"credential-vault","providers":["Modules\\CredentialVault\\Providers\\Main"]}' \
    > "$APP/modules/CredentialVault/module.json"

expect_failure() {
    local expected="$1"; shift
    if "$@" > "$TEMP/output" 2>&1; then
        echo "FAIL accepted unsafe case: $expected" >&2
        exit 1
    fi
    if ! grep -Fq "$expected" "$TEMP/output"; then
        echo "FAIL wrong failure reason: $expected" >&2
        exit 1
    fi
    echo "PASS safe refusal: $expected"
}
expect_failure 'Invalid Akaunting app root' \
    bash "$SCRIPT" --app-root "$TEMP/missing" --company 2 --with banking-connectors
expect_failure 'Module installation requires CREDENTIAL_VAULT_ALLOW_MODULE_INSTALL=yes' \
    bash "$SCRIPT" --app-root "$APP" --company 2 --with banking-connectors --apply
expect_failure 'Not a Git checkout: credential-vault' \
    bash "$SCRIPT" --app-root "$APP" --company 2 --with banking-connectors

echo APP_ENV=production > "$APP/.env"
expect_failure 'Production deployment requires a separate explicit approval' \
    env CREDENTIAL_VAULT_ALLOW_MODULE_INSTALL=yes bash "$SCRIPT" \
       --app-root "$APP" --company 2 --with banking-connectors --apply

# Ensure the synthetic artisan stub was never executed.
test ! -f "$TEMP/artisan-was-run"
echo 'PASS rejected deploy plans before any application command or DB mutation'
