<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Non-invasive install preflight for already-distributed Akaunting modules.
 * module.json "requires" is metadata, not a module-download installer.
 */
final class ModuleStackPlan
{
    private const ALIASES = [
        'credential-vault' => 'CredentialVault',
        'nfse' => 'Nfse',
        'banking-connectors' => 'BankingConnectors',
    ];

    /**
     * @param array<string,bool> $installed company-scoped state, not code availability
     * @return list<array{alias:string,path:string,action:string}>
     */
    public function prepare(string $moduleRoot, string $with, array $installed): array
    {
        if ($moduleRoot === '' || !is_dir($moduleRoot)) {
            throw new InvalidArgumentException('module_root_not_available');
        }
        $names = match ($with) {
            'nfse' => ['credential-vault', 'nfse'],
            'banking-connectors' => ['credential-vault', 'banking-connectors'],
            'all' => ['credential-vault', 'nfse', 'banking-connectors'],
            default => throw new InvalidArgumentException('invalid_module_stack'),
        };

        $steps = [];
        foreach ($names as $alias) {
            $directory = $moduleRoot . '/' . self::ALIASES[$alias];
            $manifestPath = $directory . '/module.json';
            if (!is_file($manifestPath) || is_link($directory) || is_link($manifestPath)) {
                throw new RuntimeException('required_module_source_unavailable');
            }
            try {
                $metadata = json_decode(
                    (string) file_get_contents($manifestPath), true, 32,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                throw new RuntimeException('required_module_manifest_invalid');
            }
            if (!is_array($metadata) ||
                ($metadata['alias'] ?? null) !== $alias ||
                !is_array($metadata['providers'] ?? null) ||
                $metadata['providers'] === []) {
                throw new RuntimeException('required_module_manifest_invalid');
            }
            $steps[] = [
                'alias' => $alias,
                'path' => self::ALIASES[$alias],
                'action' => ($installed[$alias] ?? false)
                    ? 'already_installed' : 'install',
            ];
        }
        return $steps;
    }
}
