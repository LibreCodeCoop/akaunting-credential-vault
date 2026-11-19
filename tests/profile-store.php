<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Support/VaultProfileStore.php';

use Modules\CredentialVault\Support\VaultProfileStore;

function checkProfile(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo "PASS {$label}\n";
}
$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec('CREATE TABLE nfse_vault_infrastructure
    (id INTEGER PRIMARY KEY, encrypted_payload TEXT, updated_at DATETIME)');
$db->exec('CREATE TABLE lcc_credential_vault_profiles
    (purpose VARCHAR(80) PRIMARY KEY, encrypted_payload TEXT NOT NULL,
     created_at DATETIME, updated_at DATETIME)');
$key = 'synthetic-test-only-DO-NOT-USE';
$encrypt = static fn (string $value): string => 'ENCRYPTED_FIXTURE:' .
    base64_encode(hash_hmac('sha256', $value, $key) . '|' . $value);
$decrypt = static function (string $cipher) use ($key): string {
    if (!str_starts_with($cipher, 'ENCRYPTED_FIXTURE:')) {
        throw new RuntimeException('Invalid synthetic ciphertext');
    }
    $raw = base64_decode(substr($cipher, strlen('ENCRYPTED_FIXTURE:')), true);
    if (!is_string($raw) || !str_contains($raw, '|')) {
        throw new RuntimeException('Invalid synthetic ciphertext');
    }
    [$signature, $value] = explode('|', $raw, 2);
    if (!hash_equals(hash_hmac('sha256', $value, $key), $signature)) {
        throw new RuntimeException('Bad synthetic MAC');
    }
    return $value;
};
$store = new VaultProfileStore($db, $encrypt, $decrypt);
$legacy = [
    'addr' => 'http://openbao:8200',
    'mount' => '/nfse',
    'auth_mode' => 'approle',
    'roleId' => 'synthetic-fiscal-role',
    'secretId' => 'synthetic-fiscal-secret',
    'token' => null,
];
$originalCipher = $encrypt(json_encode($legacy, JSON_THROW_ON_ERROR));
$db->prepare('INSERT INTO nfse_vault_infrastructure (id,encrypted_payload)
    VALUES (1,:cipher)')->execute([':cipher' => $originalCipher]);
$status = $store->inspectLegacyFiscal();
checkProfile($status['legacy_present'] && !$status['shared_present'] &&
    !$status['identical_encrypted_record'], 'read-only migration preflight');
checkProfile($store->importLegacyFiscal() === 'imported',
    'existing fiscal encrypted config copied once');
checkProfile($store->importLegacyFiscal() === 'already_imported',
    'migration is replay-safe');
$currentCipher = $db->query("SELECT encrypted_payload
    FROM lcc_credential_vault_profiles WHERE purpose='nfse'")->fetchColumn();
checkProfile($currentCipher === $originalCipher &&
    !str_contains($currentCipher, 'synthetic-fiscal-role'),
    'encrypted record copied byte-for-byte; no plaintext stored or re-encrypted');
$shared = $store->load('nfse');
checkProfile($shared['mount'] === '/nfse' &&
    $shared['roleId'] === 'synthetic-fiscal-role' &&
    $shared['secretId'] === 'synthetic-fiscal-secret' &&
    $shared['token'] === null,
    'shared profile faithfully resolves existing fiscal AppRole');
$bank = [
    'addr' => 'https://vault.example',
    'mount' => 'banking-connectors',
    'auth_mode' => 'token',
    'token' => 'synthetic-bank-token',
    'roleId' => null,
    'secretId' => null,
];
$store->create('banking-connectors.company-2.account-9', $bank);
checkProfile($store->load('banking-connectors.company-2.account-9')['token']
    === 'synthetic-bank-token' &&
    $store->load('nfse')['secretId'] === 'synthetic-fiscal-secret',
    'bank and fiscal credential profiles are isolated by purpose');
try {
    $store->create('nfse', $legacy);
    throw new RuntimeException('FAIL existing fiscal profile overwritten');
} catch (RuntimeException $e) {
    checkProfile($e->getMessage() === 'vault_profile_exists_or_unavailable',
        'existing encrypted profile cannot be silently overwritten');
}
$db->prepare('UPDATE nfse_vault_infrastructure SET encrypted_payload=:cipher WHERE id=1')
    ->execute([':cipher' => $encrypt(json_encode([
        ...$legacy, 'secretId' => 'synthetic-different-secret',
    ], JSON_THROW_ON_ERROR))]);
try {
    $store->importLegacyFiscal();
    throw new RuntimeException('FAIL divergent fiscal credentials were overwritten');
} catch (RuntimeException $e) {
    checkProfile($e->getMessage() === 'vault_profile_conflict_requires_review',
        'conflicting legacy and shared configuration fails closed');
}
checkProfile($store->load('nfse')['secretId'] === 'synthetic-fiscal-secret',
    'conflict leaves original shared encrypted profile intact');
try {
    $store->create('../nfse', $bank);
    throw new RuntimeException('FAIL path traversal purpose accepted');
} catch (InvalidArgumentException $e) {
    checkProfile($e->getMessage() === 'vault_profile_purpose_invalid',
        'invalid profile namespace rejected');
}
echo "Encrypted configuration migration passed on disposable SQLite.\n";
