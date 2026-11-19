# Deploy LibreCode Akaunting modules directly from Git

Each module is an independent Git repository. No tarball, copy from a
developer machine, proprietary marketplace or Akaunting core edit is needed.

| Akaunting directory | GitHub repository | Module alias |
| --- | --- | --- |
| modules/CredentialVault | LibreCodeCoop/akaunting-credential-vault | credential-vault |
| modules/BankingConnectors | LibreCodeCoop/banking-connectors | banking-connectors |
| modules/Nfse | LibreCodeCoop/akaunting-nfse | nfse |

A repository must have published, reviewed commits before a clone can
supply the module. Empty GitHub repositories cannot deploy functional code.

## Initial clone

Run on the Akaunting host, from the existing application root:

    cd /path/to/existing/akaunting
    git clone https://github.com/LibreCodeCoop/akaunting-credential-vault.git modules/CredentialVault
    git clone https://github.com/LibreCodeCoop/banking-connectors.git modules/BankingConnectors

For fiscal capability, also use:

    git clone https://github.com/LibreCodeCoop/akaunting-nfse.git modules/Nfse

These are ordinary, independently updatable Git checkouts. Never clone
over a running installation's existing untracked source directory.
Inspect the source and current revision before a replacement.

For a controlled financial deployment, pin reviewed commit SHAs:

    git -C modules/CredentialVault rev-parse HEAD
    git -C modules/BankingConnectors rev-parse HEAD
    git -C modules/BankingConnectors fetch --tags origin
    git -C modules/BankingConnectors checkout --detach <reviewed-commit-SHA>

The same fetch and checkout procedure applies to CredentialVault and Nfse.
Git pull is fine for development, but a recorded commit SHA offers stronger
reproducibility for production. Never force-reset dirty working trees.

## Build NFS-e dependencies when selected

NFS-e pins its fiscal library and uses PHP-Scoper. A fresh Git clone
does not contain the generated scoped runtime. After reviewing the
module's pinned dependency, run its canonical build:

    cd /path/to/existing/akaunting/modules/Nfse
    composer thirdparty:build:prod
    test -f 3rdparty/scoped/autoload.php

Do not add an unrelated composer update.

## Per-company installation after Git checkout

From the Akaunting host (not a web request), preview the operation:

    bash modules/CredentialVault/scripts/post-git-checkout.sh \
      --app-root /path/to/existing/akaunting --company 2 \
      --with banking-connectors

Other selections: --with nfse and --with all.

This verifies each required Git origin, clean checkout, manifest and exact
HEAD SHA before invoking Akaunting's native, read-only module-install plan.
The helper rejects unversioned directories and symlink escapes. It does
not contact any real bank, install from tar or enable scheduled polling.

After approval:

    CREDENTIAL_VAULT_ALLOW_MODULE_INSTALL=yes \
      bash modules/CredentialVault/scripts/post-git-checkout.sh \
      --app-root /path/to/existing/akaunting --company 2 \
      --with banking-connectors --apply

The helper calls native Akaunting per-company module:install, targeted
module:migrate for the selected modules, optimize:clear, then read-only
Banking Connectors health. No global database reset, uninstall, fiscal
emission or financial transaction posting is performed.

Production has an additional explicit gate:

    CREDENTIAL_VAULT_ALLOW_PRODUCTION_GIT_DEPLOY=yes

That gate does not replace production approval, backup/restore readiness,
reconciliation and a maintenance window. Synthetic passing tests cannot
guarantee the absence of production data loss.

## Updating with git fetch/checkout

From each installed module directory, verify local changes, fetch the
reviewed release and checkout the chosen commit:

    git status --short
    git fetch --tags origin
    git checkout --detach <next-reviewed-commit-SHA>

Then rerun the post-git-checkout helper without --apply to review all
modules together, followed by the separately approved --apply phase.
Rebuild NFS-e scoped dependencies when its pinned source changes.

Moving Git HEAD never rolls back a database migration or a legally issued
fiscal document. Do not automatically delete, reset or reinstall financial
data when reverting code.

## Security and monitoring

Module registration does not provision OpenBao AppRoles, passwords, bank
accounts or fiscal certificates. No secret belongs in Git. Bank polling
remains disabled by default; Sicoob is read-only, with staged movements
and explicit human review. The authenticated health endpoint supports any
monitoring consumer; Zabbix is optional, not a runtime dependency.

Verify company isolation, Web/API authorization, OpenBao access boundaries,
local certificate availability and the scheduler state before relying on
the integration. Verify NFS-e fiscal configuration and scoped dependencies
without issuing a new NFS-e merely to test the Vault.
