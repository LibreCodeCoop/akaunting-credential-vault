<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Real Laravel Crypt + MySQL integration against a disposable Akaunting app.
 * Guard before bootstrap; cleanup only a fixed, test-owned purpose.
 */
if (getenv('CREDENTIAL_VAULT_ISOLATED_LOCAL') !== 'yes' ||
    !is_file('/var/www/html/.env')) {
    throw new RuntimeException('Synthetic Akaunting environment required');
}
$env = (string) file_get_contents('/var/www/html/.env');
foreach ([
    'APP_ENV=local', 'DB_DATABASE=akaunting_synthetic',
    'DB_HOST=banksync-local-db', 'DB_PREFIX=ak_',
] as $marker) {
    if (!str_contains($env, $marker)) {
        throw new RuntimeException('Unexpected Akaunting environment');
    }
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Modules\CredentialVault\Support\VaultProfileStore;
use Modules\CredentialVault\Support\VaultClientFactory;
use Modules\CredentialVault\Infrastructure\OpenBaoKv2;

$purpose = 'synthetic.vault-profile-qa';
$profile = app(VaultProfileStore::class);
if (DB::table('lcc_credential_vault_profiles')->where('purpose', $purpose)->exists()) {
    throw new RuntimeException('Synthetic profile collision; refusing overwrite');
}
try {
    $profile->create($purpose, [
        'addr' => 'https://vault.localhost.invalid',
        'mount' => 'synthetic',
        'auth_mode' => 'token',
        'token' => 'synthetic-token-never-use',
        'roleId' => null, 'secretId' => null,
    ]);
    $cipher = DB::table('lcc_credential_vault_profiles')
        ->where('purpose', $purpose)->value('encrypted_payload');
    if (!is_string($cipher) || strlen($cipher) < 64 ||
        str_contains($cipher, 'synthetic-token-never-use')) {
        throw new RuntimeException('CredentialVault stored unencrypted auth token');
    }
    echo "PASS real Laravel encryption stores no plaintext Vault credentials\n";
    $restored = $profile->load($purpose);
    if ($restored === null ||
        $restored['token'] !== 'synthetic-token-never-use' ||
        $restored['mount'] !== 'synthetic') {
        throw new RuntimeException('CredentialVault could not decrypt with app key');
    }
    echo "PASS shared profile uses native Akaunting application key\n";
    $client = app(VaultClientFactory::class)->forPurpose($purpose);
    if (!$client instanceof OpenBaoKv2) {
        throw new RuntimeException('Laravel did not resolve common Vault service');
    }
    echo "PASS Laravel container injects generic purpose-specific OpenBao client\n";
} finally {
    DB::table('lcc_credential_vault_profiles')->where('purpose', $purpose)->delete();
}
if (DB::table('lcc_credential_vault_profiles')->where('purpose', $purpose)->exists()) {
    throw new RuntimeException('Synthetic profile cleanup failed');
}
echo "PASS test-only encrypted profile cleaned; no native financial writes\n";
