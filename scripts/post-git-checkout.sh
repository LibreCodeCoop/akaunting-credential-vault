#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Post-checkout hook for independently cloned Akaunting modules.
# Uses native Akaunting commands; no archives, curl installers or core edits.
set -euo pipefail
umask 077

usage() {
    echo 'Usage: post-git-checkout.sh --app-root /absolute/akaunting --company ID --with banking-connectors|nfse|all [--locale en-GB] [--apply]' >&2
    exit 2
}
app_root=''
company=''
stack=''
locale='en-GB'
apply=no
while (( $# > 0 )); do
    case "$1" in
        --app-root)
            (( $# >= 2 )) || usage
            app_root="$2"; shift 2 ;;
        --company)
            (( $# >= 2 )) || usage
            company="$2"; shift 2 ;;
        --with)
            (( $# >= 2 )) || usage
            stack="$2"; shift 2 ;;
        --locale)
            (( $# >= 2 )) || usage
            locale="$2"; shift 2 ;;
        --apply)
            apply=yes; shift ;;
        *) usage ;;
    esac
done

[[ "$app_root" == /* && "$company" =~ ^[1-9][0-9]*$ ]] || usage
[[ "$locale" =~ ^[a-z]{2}-[A-Z]{2}$ ]] || usage
case "$stack" in banking-connectors|nfse|all) ;; *) usage ;; esac
if [[ -L "$app_root" || ! -f "$app_root/bootstrap/app.php" ||
      ! -f "$app_root/artisan" || ! -f "$app_root/.env" ||
      ! -d "$app_root/modules" || -L "$app_root/modules" ]]; then
    echo 'Invalid Akaunting app root (no directories created)' >&2
    exit 1
fi

app_root="$(cd "$app_root" && pwd -P)"
if ! grep -Eq '^APP_ENV=(local|testing|staging|production)$' "$app_root/.env"; then
    echo 'Unknown Akaunting environment; deployment refused' >&2
    exit 1
fi
if [[ "$apply" == yes ]] && grep -qx APP_ENV=production "$app_root/.env" &&
   [[ "${CREDENTIAL_VAULT_ALLOW_PRODUCTION_GIT_DEPLOY:-}" != yes ]]; then
    echo 'Production deployment requires a separate explicit approval' >&2
    exit 1
fi
if [[ "$apply" == yes && "${CREDENTIAL_VAULT_ALLOW_MODULE_INSTALL:-}" != yes ]]; then
    echo 'Module installation requires CREDENTIAL_VAULT_ALLOW_MODULE_INSTALL=yes' >&2
    exit 1
fi

declare -a requested=(CredentialVault)
case "$stack" in
    banking-connectors) requested+=(BankingConnectors) ;;
    nfse) requested+=(Nfse) ;;
    all) requested+=(Nfse BankingConnectors) ;;
esac

# Only normal Git clones from the intended LibreCodeCoop repositories.
# No symlink escapes, arbitrary remote hosts or dirty worktrees.
check_checkout() {
    local dir="$1" repo="$2" alias="$3" checkout root remote sha
    checkout="$app_root/modules/$dir"
    if [[ -L "$checkout" || ! -d "$checkout" ||
          ! -f "$checkout/module.json" || -L "$checkout/module.json" ]]; then
        echo "Missing Git checkout for module: $alias" >&2
        return 1
    fi
    root="$(git -C "$checkout" rev-parse --show-toplevel 2>/dev/null)" || {
        echo "Not a Git checkout: $alias" >&2; return 1;
    }
    if [[ "$root" != "$checkout" ]]; then
        echo "Unexpected Git worktree root: $alias" >&2; return 1
    fi
    remote="$(git -C "$checkout" remote get-url origin 2>/dev/null)" || {
        echo "Git origin missing: $alias" >&2; return 1;
    }
    case "$remote" in
        "https://github.com/LibreCodeCoop/$repo.git"|"git@github.com:LibreCodeCoop/$repo.git"|"https://github.com/LibreCodeCoop/$repo")
            ;;
        *)
            echo "Git origin not approved for module: $alias" >&2
            return 1 ;;
    esac
    if [[ -n "$(git -C "$checkout" status --porcelain --untracked-files=normal)" ]]; then
        echo "Git checkout contains uncommitted modifications: $alias" >&2
        return 1
    fi
    sha="$(git -C "$checkout" rev-parse HEAD)" || return 1
    if [[ ! "$sha" =~ ^[a-f0-9]{40}$ ]]; then
        echo "Git HEAD missing or invalid: $alias" >&2; return 1
    fi
    php -r '
        $m=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
        if (($m["alias"]??null)!==$argv[2] ||
            empty($m["providers"]) || !is_array($m["providers"])) exit(1);
    ' "$checkout/module.json" "$alias" || {
        echo "Module manifest does not match checked-out code: $alias" >&2
        return 1
    }
    echo "verified_checkout=$alias revision=$sha"
}

for module in "${requested[@]}"; do
    case "$module" in
        CredentialVault) check_checkout "$module" akaunting-credential-vault credential-vault ;;
        BankingConnectors) check_checkout "$module" banking-connectors banking-connectors ;;
        Nfse)
            check_checkout "$module" akaunting-nfse nfse
            if [[ ! -f "$app_root/modules/Nfse/3rdparty/scoped/autoload.php" ]]; then
                echo 'NFS-e runtime missing: run composer thirdparty:build:prod inside the Nfse checkout' >&2
                exit 1
            fi ;;
    esac
done

cd "$app_root"
echo '== Native Akaunting preflight (no change) =='
php artisan credential-vault:install-stack "$company" --with="$stack" \
    --locale="$locale" --no-interaction --no-ansi

if [[ "$apply" != yes ]]; then
    echo 'DRY_RUN_ONLY: checkout verification completed; no migrations, downloads or code changes'
    exit 0
fi

echo '== Native module registration =='
php artisan credential-vault:install-stack "$company" --with="$stack" \
    --locale="$locale" --apply --no-interaction --no-ansi

# Target only these modules; never run migrate:fresh or global rollback.
for module in "${requested[@]}"; do
    case "$module" in
        CredentialVault) alias=credential-vault ;;
        BankingConnectors) alias=banking-connectors ;;
        Nfse) alias=nfse ;;
    esac
    php artisan module:migrate "$alias" --force --no-interaction --no-ansi
done

php artisan optimize:clear --no-interaction --no-ansi
if [[ "$stack" != nfse ]]; then
    php artisan banking-connectors:health --strict --no-interaction --no-ansi
fi
echo 'GIT_CHECKOUT_DEPLOYMENT_COMPLETE: bank polling and fiscal issuance not auto-enabled'
