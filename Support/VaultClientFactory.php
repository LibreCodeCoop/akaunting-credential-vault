<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Support;

use Modules\CredentialVault\Contracts\SecretStore;
use Modules\CredentialVault\Infrastructure\OpenBaoKv2;
use RuntimeException;

/**
 * Injection point for NFS-e, Banking Connectors and future consumers.
 *
 * Each purpose resolves its OWN OpenBao role/mount from encrypted settings.
 * This does not impersonate a PHP module to Vault: the Vault audit identity
 * is the AppRole/token used by that purpose, not the Laravel class name.
 */
final class VaultClientFactory
{
    public function __construct(private readonly VaultProfileStore $profiles) {}

    public function forPurpose(string $purpose): SecretStore
    {
        $config = $this->profiles->load($purpose);
        if ($config === null) {
            throw new RuntimeException('vault_profile_missing');
        }
        return $this->fromConfig($config);
    }

    /**
     * Transitional entrypoint: use existing NFS-e encrypted settings without
     * migrating or persisting them again. No global Vault credentials.
     *
     * @param array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} $config
     */
    public function fromConfig(array $config): SecretStore
    {
        return new OpenBaoKv2(
            address: $config['addr'],
            mount: $config['mount'],
            token: $config['token'],
            roleId: $config['roleId'],
            secretId: $config['secretId'],
        );
    }
}
