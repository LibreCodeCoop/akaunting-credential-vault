<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

require dirname(__DIR__) . '/Support/ModuleStackPlan.php';

use Modules\CredentialVault\Support\ModuleStackPlan;

function verifyStack(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}
$root = sys_get_temp_dir() . '/credential-vault-stack-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Synthetic module root could not be created');
}
$directories = [
    'CredentialVault' => 'credential-vault',
    'BankingConnectors' => 'banking-connectors',
    'Nfse' => 'nfse',
];
try {
    $planner = new ModuleStackPlan();
    try {
        $planner->prepare($root, 'banking-connectors', []);
        throw new RuntimeException('FAIL missing dependency accepted');
    } catch (RuntimeException $error) {
        verifyStack($error->getMessage() === 'required_module_source_unavailable',
            'installation cannot proceed when dependency code is absent');
    }
    foreach ($directories as $dir => $alias) {
        mkdir($root . '/' . $dir, 0700);
        file_put_contents($root . '/' . $dir . '/module.json',
            json_encode(['alias' => $alias, 'providers' => [
                'Modules\\' . $dir . '\\Providers\\Main',
            ]], JSON_THROW_ON_ERROR));
    }
    $all = $planner->prepare($root, 'all', ['banking-connectors' => true]);
    verifyStack(array_column($all, 'alias') === [
        'credential-vault', 'nfse', 'banking-connectors',
    ], 'three modules install in dependency order with Vault first');
    verifyStack(array_column($all, 'action') === [
        'install', 'install', 'already_installed',
    ], 'company installation plan is idempotent and tenant-scoped');
    verifyStack(array_column($planner->prepare($root, 'nfse', []), 'alias')
        === ['credential-vault', 'nfse'],
        'NFS-e bundle does not force unrelated bank integration');
    verifyStack(array_column($planner->prepare($root, 'banking-connectors', []), 'alias')
        === ['credential-vault', 'banking-connectors'],
        'bank bundle does not force unrelated fiscal integration');
    foreach (['random', '../nfse', ''] as $option) {
        try {
            $planner->prepare($root, $option, []);
            throw new RuntimeException('FAIL invalid stack accepted');
        } catch (InvalidArgumentException) {
            verifyStack(true, 'untrusted stack name rejected');
        }
    }
    file_put_contents($root . '/Nfse/module.json', '{"alias":"banking-connectors","providers":[]}');
    try {
        $planner->prepare($root, 'all', []);
        throw new RuntimeException('FAIL mismatched module manifest accepted');
    } catch (RuntimeException $error) {
        verifyStack($error->getMessage() === 'required_module_manifest_invalid',
            'tampered or mismatched module manifest rejected before any installation');
    }
} finally {
    foreach (array_keys($directories) as $dir) {
        @unlink($root . '/' . $dir . '/module.json');
        @rmdir($root . '/' . $dir);
    }
    @rmdir($root);
}
echo 'Offline module-install preflight contract passed without database changes.' . PHP_EOL;
