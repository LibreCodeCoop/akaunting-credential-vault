<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Support;

use Closure;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Shared infrastructure profiles, encrypted with the application's own key.
 * No plaintext passwords, tokens, Role IDs or Secret IDs are persisted.
 *
 * Storage is purpose-scoped; consumers still authorize each tenant and
 * secret path and must use a purpose-restricted OpenBao AppRole.
 */
final class VaultProfileStore
{
    private readonly string $table;
    private readonly string $legacyTable;

    /** @param Closure(string):string $encrypt @param Closure(string):string $decrypt */
    public function __construct(
        private readonly PDO $db,
        private readonly Closure $encrypt,
        private readonly Closure $decrypt,
        string $prefix = '',
    ) {
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('vault_profile_prefix_invalid');
        }
        $this->table = $prefix . 'lcc_credential_vault_profiles';
        $this->legacyTable = $prefix . 'nfse_vault_infrastructure';
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return null|array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string}
     */
    public function load(string $purpose): ?array
    {
        $cipher = $this->ciphertext($purpose);
        return $cipher === null ? null : $this->decodeConfig($cipher);
    }

    /**
     * @param array{addr:string,mount:string,auth_mode:string,token:?string,roleId:?string,secretId:?string} $config
     */
    public function create(string $purpose, array $config): void
    {
        self::validatePurpose($purpose);
        $normalized = $this->validateConfig($config);
        try {
            $cipher = ($this->encrypt)(json_encode($normalized, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new RuntimeException('vault_profile_encryption_failed');
        }
        if (!is_string($cipher) || $cipher === '') {
            throw new RuntimeException('vault_profile_encryption_failed');
        }
        // Insert-only: overwriting a live Vault identity demands a separate
        // explicit rotation and recovery flow, never implicit upsert.
        $this->insertCiphertext($purpose, $cipher);
    }

    /**
     * Import an existing encrypted NFS-e profile without decrypting and
     * re-encrypting its payload, changing the KV mount, or moving Vault data.
     *
     * @return 'imported'|'already_imported'|'legacy_not_configured'
     */
    public function importLegacyFiscal(): string
    {
        $source = $this->db->prepare(
            "SELECT encrypted_payload FROM {$this->legacyTable} WHERE id = 1"
        );
        $source->execute();
        $legacy = $source->fetchColumn();
        if ($legacy === false || $legacy === null) {
            return 'legacy_not_configured';
        }
        if (!is_string($legacy) || $legacy === '') {
            throw new RuntimeException('vault_profile_unreadable');
        }
        // Validate the actual encrypted legacy data first, but never output it.
        $this->decodeConfig($legacy);
        $existing = $this->ciphertext('nfse');
        if ($existing !== null) {
            if (hash_equals($existing, $legacy)) {
                return 'already_imported';
            }
            throw new RuntimeException('vault_profile_conflict_requires_review');
        }
        $this->insertCiphertext('nfse', $legacy);
        $copied = $this->ciphertext('nfse');
        if ($copied === null || !hash_equals($legacy, $copied)) {
            throw new RuntimeException('vault_profile_import_mismatch');
        }
        return 'imported';
    }

    /** No secrets in this readiness report. */
    public function inspectLegacyFiscal(): array
    {
        $legacy = $this->db->prepare(
            "SELECT encrypted_payload FROM {$this->legacyTable} WHERE id = 1"
        );
        $legacy->execute();
        $source = $legacy->fetchColumn();
        $shared = $this->ciphertext('nfse');
        return [
            'legacy_present' => is_string($source) && $source !== '',
            'shared_present' => $shared !== null,
            'identical_encrypted_record' => is_string($source) && $shared !== null
                && hash_equals($source, $shared),
            'no_vault_secret_writes' => true,
        ];
    }

    private function ciphertext(string $purpose): ?string
    {
        self::validatePurpose($purpose);
        $query = $this->db->prepare(
            "SELECT encrypted_payload FROM {$this->table} WHERE purpose = :purpose"
        );
        $query->execute([':purpose' => $purpose]);
        $cipher = $query->fetchColumn();
        if ($cipher === false || $cipher === null) {
            return null;
        }
        if (!is_string($cipher) || $cipher === '') {
            throw new RuntimeException('vault_profile_unreadable');
        }
        return $cipher;
    }

    private function insertCiphertext(string $purpose, string $cipher): void
    {
        $query = $this->db->prepare(
            "INSERT INTO {$this->table}
             (purpose, encrypted_payload, created_at, updated_at)
             VALUES (:purpose, :payload, :created_at, :updated_at)"
        );
        try {
            $query->execute([
                ':purpose' => $purpose, ':payload' => $cipher,
                ':created_at' => gmdate('Y-m-d H:i:s'),
                ':updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            throw new RuntimeException('vault_profile_exists_or_unavailable');
        }
    }

    /** @return array{addr:string,mount:string,token:?string,roleId:?string,secretId:?string} */
    private function decodeConfig(string $cipher): array
    {
        try {
            $raw = ($this->decrypt)($cipher);
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new InvalidArgumentException('invalid_vault_profile');
            }
            $validated = $this->validateConfig($data);
            return [
                'addr' => $validated['addr'],
                'mount' => $validated['mount'],
                'token' => $validated['auth_mode'] === 'token' ? $validated['token'] : null,
                'roleId' => $validated['auth_mode'] === 'approle' ? $validated['roleId'] : null,
                'secretId' => $validated['auth_mode'] === 'approle' ? $validated['secretId'] : null,
            ];
        } catch (Throwable) {
            throw new RuntimeException('vault_profile_unreadable');
        }
    }

    /** @param array<mixed,mixed> $data @return array<string,mixed> */
    private function validateConfig(array $data): array
    {
        foreach (['addr', 'mount', 'auth_mode'] as $key) {
            if (!is_string($data[$key] ?? null) || trim($data[$key]) === '') {
                throw new InvalidArgumentException('vault_profile_invalid');
            }
        }
        $mode = $data['auth_mode'];
        if (!in_array($mode, ['token', 'approle'], true) ||
            ($mode === 'token' &&
                (!is_string($data['token'] ?? null) || $data['token'] === '')) ||
            ($mode === 'approle' && (
                !is_string($data['roleId'] ?? null) || $data['roleId'] === '' ||
                !is_string($data['secretId'] ?? null) || $data['secretId'] === ''
            ))) {
            throw new InvalidArgumentException('vault_profile_invalid');
        }
        return $data;
    }

    private static function validatePurpose(string $purpose): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,79}$/D', $purpose)) {
            throw new InvalidArgumentException('vault_profile_purpose_invalid');
        }
    }
}
