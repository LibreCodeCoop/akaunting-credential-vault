# Shared credential infrastructure

## Separation of concerns

OpenBao (server): existing shared encrypted secret engine.

CredentialVault (small reusable PHP module):
- OpenBao KV v2 get/put/soft-delete, with purpose-scoped AppRole or token;
- Laravel-injected VaultClientFactory for applications and fiscal modules;
- encrypted VaultProfileStore for purpose-specific credentials;
- strict internal secret path validation;
- tenant/purpose-specific reference helpers;
- private OpenBao Agent template file reader.

NFS-e (fiscal consumer):
- owns fiscal certificate revision, issuance permissions and NFS-e rules;
- can use the shared OpenBao client with its existing encrypted configuration;
- does not receive access to banking secrets.

Banking Connectors (banking consumer):
- owns account binding and bank-specific OAuth/mTLS logic;
- reads only its own private Agent-rendered files;
- does not receive access to fiscal PFX passwords or fiscal issuance.

The PHP abstractions are framework-independent, so they may later be extracted
as a Composer package if another non-Akaunting application requires it.

## OpenBao mounts and identity

A shared OpenBao **server** does not imply a shared mount or AppRole.
KV mounts named "nfse", "banking-connectors" or another purpose can coexist, and
provide useful isolation. Moving all data to one mount simply to make the
infrastructure "common" would *reduce* the authorization boundary.

CredentialVault accepts a mount explicitly per instance. Never use one
administrator AppRole with broad read/update access for every module.

The Laravel application resolves VaultClientFactory from the container.
Its forPurpose method looks up a named, encrypted profile and returns the
common OpenBao client. Its fromConfig method supports the older encrypted
NFS-e configuration during the transition. Neither method initializes
OpenBao in the application boot sequence.

**Security limit:** classes within one Laravel/PHP process are not isolated
from each other by the service container. A module with arbitrary code
execution and access to the application's encryption key may attempt to
read profiles belonging to another module. OpenBao audits the token/AppRole
used for each request, not which PHP class made the call. Restrict policies
per purpose and company; if modules require protection from one another,
put their workers in separate processes with separate secret mounts and
identities. A shared library alone cannot enforce that security boundary.

Read-only bank AppRole example on a hypothetical KV v2 banking-connectors mount:

    path "banking-connectors/data/company-2/account-3" {
        capabilities = ["read"]
    }

Fiscal role example limited to one revision in a legacy nfse mount:

    path "nfse/data/pfx/companies/2/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" {
        capabilities = ["read", "create", "update", "delete"]
    }

The paths, mounts and company IDs above are **synthetic examples**, not
statements about deployed LibreCode secrets.

## Bootstrap identity

The "secret zero" needed for authenticating to OpenBao can be supplied
by OpenBao Agent auto-auth on a protected tmpfs, or by an encrypted legacy
Akaunting application setting when migrating NFS-e. Do not put OpenBao tokens,
AppRole Secret IDs or PFX passwords into source control or unencrypted app
configuration files.

No HTTP access to OpenBao is permitted except HTTPS, or the existing
internal-only Docker URL "http://openbao:8200". Never disable TLS validation.

## Legacy fiscal behavior

The NFS-e module currently has:
- encrypted global OpenBao connection configuration in the Akaunting database;
- a KV v2 mount named /nfse in the default legacy configuration;
- company/revision path "pfx/companies/{id}/{revision}";
- a PFX *password* and PFX file path stored at that Vault path;
- PFX binary still stored in a protected local server directory.

The new generic client can read/write the same secret path and keys without
duplicating, moving or deleting the original OpenBao secret. The fiscal module
must continue authorizing by company ID, not CNPJ.

**The PFX binary is not automatically moved into OpenBao.** This is a
distinct high-risk migration requiring verified runtime materialization,
backup/restore, rotation, disaster recovery and a rollback plan. Do not
purge existing PFX files or copy them into a new mount automatically.

## Installation and distribution

Each module is distributed as an independent Git checkout under the native
Akaunting modules directory. The post-git-checkout.sh helper validates
reviewed Git origins, exact SHAs, clean worktrees and native manifests.
It delegates installation to the dry-run-first credential-vault:install-stack
command and targets only the selected modules' migrations. No archive,
proprietary installer, core changes or auto-enabled bank jobs are required.
See INSTALLATION.md for Git clone, fetch and checkout procedures.

## Security and retention

Only trusted services may instantiate OpenBaoKv2. The shared module has
no routes to reveal secret values. In Banking Connectors, the dedicated Authenticated
health endpoint returns only safely aggregated status, and never calls the
bank or the Vault during a status scrape.

Keys are never rendered to logs, command output or tests. The generic KV v2
DELETE soft-deletes the latest version; it is not a purge of historical secret
versions. Retention and physical destruction require separate approvals.
