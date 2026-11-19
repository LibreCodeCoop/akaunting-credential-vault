<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Domain;

use InvalidArgumentException;

/**
 * Company is a native Akaunting tenant ID, never selected from CNPJ.
 * New secrets use one purpose-specific path. Existing NFS-e secret paths are
 * explicitly preserved and must not be renamed without a verified migration.
 */
final class ScopedSecretPath
{
    public static function forCompany(string $purpose, int $companyId, string $revision): string
    {
        self::validate($purpose, $companyId, $revision);
        return "credentials/{$purpose}/companies/{$companyId}/{$revision}";
    }

    public static function legacyFiscalPfx(int $companyId, string $revision): string
    {
        if ($companyId < 1 || !preg_match('/^[a-f0-9]{32}$/D', $revision)) {
            throw new InvalidArgumentException('invalid_credential_scope');
        }
        return "pfx/companies/{$companyId}/{$revision}";
    }

    public static function validate(string $purpose, int $companyId, string $revision): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,47}$/D', $purpose) ||
            $companyId < 1 ||
            !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,95}$/D', $revision) ||
            $revision === '.' || $revision === '..') {
            throw new InvalidArgumentException('invalid_credential_scope');
        }
    }

    public static function validateInternalPath(string $path): string
    {
        if (strlen($path) > 256 || strlen($path) < 1 || str_contains($path, '//')) {
            throw new InvalidArgumentException('invalid_vault_secret_path');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' ||
                !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,95}$/D', $segment)) {
                throw new InvalidArgumentException('invalid_vault_secret_path');
            }
        }
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
