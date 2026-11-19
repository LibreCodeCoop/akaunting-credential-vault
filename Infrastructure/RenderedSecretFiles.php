<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Infrastructure;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * OpenBao Agent (or equivalent) delivers tenant-scoped files to a private
 * read-only mount. This helper never logs secret contents or authenticates
 * with OpenBao. A symlink/path traversal cannot escape the scoped directory.
 *
 * Reusable by Sicoob, C6, PagBank, fiscal and other future modules.
 */
final class RenderedSecretFiles
{
    private readonly string $root;

    public function __construct(string $root)
    {
        if ($root === '' || $root[0] !== '/') {
            throw new InvalidArgumentException('secret_root_must_be_absolute');
        }
        $canonical = realpath($root);
        if ($canonical === false || !is_dir($canonical)) {
            throw new RuntimeException('secret_mount_unavailable');
        }
        $this->root = $canonical;
    }

    /**
     * @param list<string> $keys
     * @return array<string,string> Keys map to verified absolute file paths.
     */
    public function filePaths(
        string $purpose, int $companyId, int $accountId, string $reference,
        array $keys
    ): array {
        if (!preg_match('/^[a-z][a-z0-9-]{1,47}$/D', $purpose) ||
            $companyId < 1 || $accountId < 1) {
            throw new DomainException('secret_scope_invalid');
        }
        $expected = "{$purpose}/company-{$companyId}/account-{$accountId}";
        if ($reference !== $expected) {
            throw new DomainException('secret_scope_invalid');
        }
        $base = $this->root . '/' . $expected;
        $real = realpath($base);
        if ($real !== $base || !is_dir($base)) {
            throw new RuntimeException('secret_mount_unavailable');
        }
        foreach ([
            $this->root,
            $this->root . '/' . $purpose,
            $this->root . '/' . $purpose . '/company-' . $companyId,
            $base,
        ] as $dir) {
            $permissions = fileperms($dir);
            if (is_link($dir) || !is_dir($dir) || $permissions === false ||
                ($permissions & 0002) !== 0) {
                throw new RuntimeException('secret_permissions_unsafe');
            }
        }
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($key) ||
                !preg_match('/^[a-z][a-z0-9-]{0,63}(?:\.[a-z0-9]{1,8})?$/D', $key) ||
                array_key_exists($key, $result)) {
                throw new InvalidArgumentException('secret_filename_invalid');
            }
            $file = $base . '/' . $key;
            $permissions = is_file($file) ? fileperms($file) : false;
            if (is_link($file) || !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('secret_file_unavailable');
            }
            if ($permissions === false || ($permissions & 0007) !== 0) {
                throw new RuntimeException('secret_permissions_unsafe');
            }
            $result[$key] = $file;
        }
        return $result;
    }

    public function text(string $path, int $maxBytes = 4096): string
    {
        if ($maxBytes < 1 || $maxBytes > 1048576 ||
            !is_file($path) || is_link($path)) {
            throw new RuntimeException('secret_file_invalid');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('secret_file_invalid');
        }
        $text = file_get_contents($path, false, null, 0, $maxBytes + 1);
        if (!is_string($text) || strlen($text) > $maxBytes) {
            throw new RuntimeException('secret_file_invalid');
        }
        return trim($text);
    }
}
