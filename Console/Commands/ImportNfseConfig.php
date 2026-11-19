<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\CredentialVault\Support\VaultProfileStore;
use Throwable;

/**
 * Explicit migration gate. Keeps the original fiscal encrypted record intact
 * and does NOT read/write any Vault KV record or certificate file.
 */
final class ImportNfseConfig extends Command
{
    protected $signature = 'credential-vault:import-nfse-config
        {--apply : Copy the existing encrypted NFS-e infrastructure config without changing Vault data}';

    protected $description = 'Inspect/copy NFS-e encrypted Vault config to shared CredentialVault table';

    public function handle(VaultProfileStore $profiles): int
    {
        try {
            if (!Schema::hasTable('nfse_vault_infrastructure')) {
                $this->line(json_encode([
                    'status' => 'legacy_module_not_installed',
                    'applied' => false,
                ], JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }
            $before = $profiles->inspectLegacyFiscal();
            if (!$this->option('apply')) {
                $this->line(json_encode([
                    'status' => 'inspection_only',
                    'legacy_present' => $before['legacy_present'],
                    'shared_present' => $before['shared_present'],
                    'identical_encrypted_record' => $before['identical_encrypted_record'],
                    'applied' => false,
                ], JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }
            if (getenv('CREDENTIAL_VAULT_ALLOW_NFSE_IMPORT') !== 'yes') {
                $this->error('vault_profile_import_not_authorized');
                return self::FAILURE;
            }
            $result = $profiles->importLegacyFiscal();
            $after = $profiles->inspectLegacyFiscal();
            $this->line(json_encode([
                'status' => $result, 'applied' => $result === 'imported',
                'identical_encrypted_record' => $after['identical_encrypted_record'],
                'vault_kv_writes' => 0, 'financial_writes' => 0,
            ], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('vault_profile_import_failed');
            return self::FAILURE;
        }
    }
}
