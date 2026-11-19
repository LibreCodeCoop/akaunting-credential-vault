# Credential Vault

Shared OpenBao integration for Akaunting modules and future LibreCode systems.

The module provides a small set of framework-independent PHP components for
company-scoped secret references, KV v2 access and reading private files
rendered by OpenBao Agent. It does not hold a global administrator token,
change native financial data or start network requests automatically.

Sicoob Banking Connectors uses the shared Agent file-reader; NFS-e can explicitly opt
into the common KV v2 client without rewriting or migrating existing secret
paths. Each consumer keeps its own authentication scope and access policy.

Security model and migration procedures:
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
Git clone/checkout deployment and per-company installation:
[docs/INSTALLATION.md](docs/INSTALLATION.md).

Laravel consumers resolve the VaultClientFactory service from the container.
Its forPurpose method retrieves a purpose-specific encrypted Vault profile;
fromConfig adapts existing encrypted settings during a gradual migration.

The module is AGPL-3.0-or-later. No vendor-bundled or proprietary bank-feed
application is required.
