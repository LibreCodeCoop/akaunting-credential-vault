<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

if (getenv('CREDENTIAL_VAULT_ISOLATED_QA') !== 'yes'
    || !is_file('/auth/roleid') || !is_file('/auth/secretid')
    || !is_file('/auth/fiscal-roleid') || !is_file('/auth/fiscal-secretid')) {
    throw new RuntimeException('Isolated synthetic OpenBao QA only');
}
require dirname(__DIR__) . '/Contracts/SecretStore.php';
require dirname(__DIR__) . '/Domain/ScopedSecretPath.php';
require dirname(__DIR__) . '/Infrastructure/OpenBaoKv2.php';

use Modules\CredentialVault\Infrastructure\OpenBaoKv2;
use Modules\CredentialVault\Domain\ScopedSecretPath;

function checkLiveVault(bool $valid, string $label): void {
    if (!$valid) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo "PASS {$label}\n";
}
$read = static fn (string $name): string => trim((string) file_get_contents('/auth/' . $name));
$bank = new OpenBaoKv2(
    'http://openbao:8200', 'banking-connectors',
    roleId:$read('roleid'), secretId:$read('secretid')
);
$bankValues = $bank->get('company-2/account-3');
checkLiveVault(($bankValues['client_id'] ?? null) === 'synthetic_qa_client' &&
    ($bankValues['account_number'] ?? null) === '123456',
    'real OpenBao 2.7.1 AppRole reads bank-scoped synthetic secret');
try {
    $bank->get('company-3/account-3');
    throw new RuntimeException('FAIL cross-company bank secret accessible');
} catch (DomainException $error) {
    checkLiveVault(in_array($error->getMessage(), [
        'vault_access_denied', 'vault_secret_not_found'
    ], true), 'real bank AppRole cannot access another company');
}
foreach (['put', 'delete'] as $operation) {
    try {
        if ($operation === 'put') {
            $bank->put('company-2/account-3', ['client_id' => 'NOT_ALLOWED']);
        } else {
            $bank->delete('company-2/account-3');
        }
        throw new RuntimeException('FAIL bank AppRole could mutate a secret');
    } catch (DomainException $error) {
        checkLiveVault($error->getMessage() === 'vault_access_denied',
            'read-only bank AppRole denies ' . $operation);
    }
}
$fiscal = new OpenBaoKv2('http://openbao:8200', '/nfse',
    roleId:$read('fiscal-roleid'), secretId:$read('fiscal-secretid'));
$path = ScopedSecretPath::legacyFiscalPfx(2, str_repeat('a', 32));
$old = $fiscal->get($path);
checkLiveVault(($old['password'] ?? null) === 'synthetic-initial-password',
    'shared generic Vault reads original NFS-e PFX path without migration');
$fiscal->put($path, [
    'password' => 'synthetic-updated-password',
    'pfx_path' => '/fake/qa/cert.pfx',
]);
$updated = $fiscal->get($path);
checkLiveVault($updated['password'] === 'synthetic-updated-password',
    'shared Vault performs verified fiscal secret update on disposable KV v2');
try {
    $fiscal->get('pfx/companies/3/' . str_repeat('a', 32));
    throw new RuntimeException('FAIL foreign fiscal company accessible');
} catch (DomainException $error) {
    checkLiveVault(in_array($error->getMessage(), [
        'vault_secret_not_found', 'vault_access_denied'
    ], true), 'fiscal AppRole denied cross-company certificate');
}
$fiscal->delete($path);
try {
    $fiscal->get($path);
    throw new RuntimeException('FAIL soft-deleted revision returned');
} catch (DomainException $error) {
    checkLiveVault($error->getMessage() === 'vault_secret_not_found',
        'fiscal KV v2 delete soft-deletes latest synthetic revision');
}
echo "Real isolated OpenBao KV v2 compatibility passed; no production Vault touched.\n";
