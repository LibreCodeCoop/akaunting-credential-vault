<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Contracts/SecretStore.php';
require dirname(__DIR__) . '/Domain/ScopedSecretPath.php';
require dirname(__DIR__) . '/Infrastructure/OpenBaoKv2.php';
require dirname(__DIR__) . '/Infrastructure/RenderedSecretFiles.php';

use Modules\CredentialVault\Domain\ScopedSecretPath;
use Modules\CredentialVault\Infrastructure\OpenBaoKv2;

function assertion(bool $ok, string $description): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $description);
    }
    echo "PASS {$description}\n";
}
assertion(
    ScopedSecretPath::legacyFiscalPfx(2, str_repeat('a', 32)) ===
    'pfx/companies/2/' . str_repeat('a', 32),
    'legacy fiscal certificate path remains byte-for-byte compatible'
);
assertion(
    ScopedSecretPath::forCompany('sicoob', 2, 'bank-account-9') ===
    'credentials/sicoob/companies/2/bank-account-9',
    'generic company-scoped path independent of fiscal implementation'
);
foreach ([
    static fn() => ScopedSecretPath::forCompany('../nfse', 1, 'id'),
    static fn() => ScopedSecretPath::forCompany('sicoob', 0, 'id'),
    static fn() => ScopedSecretPath::forCompany('sicoob', 1, '../secret'),
    static fn() => ScopedSecretPath::validateInternalPath('pfx/../admin'),
    static fn() => ScopedSecretPath::validateInternalPath('pfx/%2e%2e/admin'),
    static fn() => ScopedSecretPath::validateInternalPath('//secret'),
] as $callback) {
    try {
        $callback();
        throw new RuntimeException('FAIL invalid secret path accepted');
    } catch (InvalidArgumentException) {
        assertion(true, 'scope and path escape rejected');
    }
}
foreach ([
    ['http://evil.example:8200', '/nfse'],
    ['http://openbao:8080', '/nfse'],
    ['https://vault.example/private', '/nfse'],
    ['https://vault.example', '../nfse'],
] as [$addr, $mount]) {
    try {
        new OpenBaoKv2($addr, $mount, token: 'test-token');
        throw new RuntimeException('FAIL invalid Vault endpoint accepted');
    } catch (InvalidArgumentException) {
        assertion(true, 'untrusted endpoint, mount or URI rejected');
    }
}
$calls = [];
$exchange = static function (string $method, string $path, array $headers, ?string $body) use (&$calls): array {
    $calls[] = [$method, $path, $headers];
    if ($path === '/v1/auth/approle/login') {
        return ['status' => 200, 'body' => json_encode([
            'auth' => ['client_token' => 'fake-app-role-token', 'lease_duration' => 3600],
        ], JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET') {
        return ['status' => 200, 'body' => json_encode(['data' => ['data' => [
            'password' => 'synthetic-only', 'pfx_path' => '/fake/synthetic/test.pfx',
        ]]], JSON_THROW_ON_ERROR)];
    }
    return ['status' => 204, 'body' => ''];
};
$store = new OpenBaoKv2('http://openbao:8200', '/nfse',
    roleId:'synthetic-role', secretId:'synthetic-id', testExchange:$exchange);
$path = ScopedSecretPath::legacyFiscalPfx(2, str_repeat('a', 32));
assertion($store->get($path)['password'] === 'synthetic-only',
    'legacy fiscal KV v2 read returns same key/value structure');
$store->put($path, ['password' => 'test', 'pfx_path' => '/synthetic']);
$store->delete($path);
assertion(count($calls) === 4 && $calls[0][1] === '/v1/auth/approle/login' &&
    $calls[1][1] === '/v1/nfse/data/' . $path &&
    $calls[1][0] === 'GET' && $calls[2][0] === 'POST' &&
    $calls[3][0] === 'DELETE',
    'AppRole authenticated once and KV read/write/delete use legacy mount');
assertion(!in_array('fake-app-role-token', $calls[0][2], true),
    'AppRole token never used before successful authorization');
foreach ([403, 404, 500] as $status) {
    $bad = new OpenBaoKv2('https://vault.example', '/nfse',
        token: 'synthetic', testExchange: static fn(): array => [
            'status' => $status, 'body' => '{"error":"DO_NOT_EXPOSE_SECRET"}',
        ]);
    try {
        $bad->get('pfx/companies/2/' . str_repeat('a', 32));
        throw new RuntimeException('FAIL Vault failure accepted');
    } catch (DomainException|RuntimeException $error) {
        assertion(!str_contains($error->getMessage(), 'DO_NOT_EXPOSE_SECRET'),
            "HTTP {$status} failure does not disclose private Vault response");
    }
}
echo "CredentialVault generic contract passed without network traffic.\n";
