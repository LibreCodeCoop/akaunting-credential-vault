<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Infrastructure;

use DomainException;
use InvalidArgumentException;
use Modules\CredentialVault\Contracts\SecretStore;
use Modules\CredentialVault\Domain\ScopedSecretPath;
use RuntimeException;

/**
 * Small KV v2 client; configuration is injected per consumer/purpose.
 *
 * Existing NFS-e deployments can supply their *existing* encrypted config.
 * Future modules can use a purpose-scoped AppRole, not the fiscal AppRole.
 * No HTTP routes, global admin token, credential database or secret logging.
 */
final class OpenBaoKv2 implements SecretStore
{
    private readonly string $base;
    private readonly string $mountPath;
    private ?string $sessionToken = null;
    private int $expiresAt = 0;

    /**
     * @param ?\Closure(string,string,array<string,string>,?string):array{status:int,body:string} $testExchange
     */
    public function __construct(
        string $address,
        string $mount,
        private readonly ?string $token = null,
        private readonly ?string $roleId = null,
        private readonly ?string $secretId = null,
        private readonly ?\Closure $testExchange = null,
    ) {
        $url = parse_url($address);
        if (!is_array($url) ||
            isset($url['user']) || isset($url['pass']) ||
            isset($url['query']) || isset($url['fragment']) ||
            (isset($url['path']) && trim($url['path'], '/') !== '')) {
            throw new InvalidArgumentException('vault_endpoint_invalid');
        }
        $scheme = $url['scheme'] ?? '';
        $host = strtolower((string) ($url['host'] ?? ''));
        $port = (int) ($url['port'] ?? ($scheme === 'https' ? 443 : 0));
        if ($scheme !== 'https' &&
            !($scheme === 'http' && $port === 8200 &&
              in_array($host, ['openbao', '127.0.0.1'], true))) {
            throw new InvalidArgumentException('vault_endpoint_requires_tls');
        }
        if ($host === '') {
            throw new InvalidArgumentException('vault_endpoint_invalid');
        }
        $mount = trim($mount, '/');
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $mount)) {
            throw new InvalidArgumentException('vault_mount_invalid');
        }
        $hasToken = is_string($token) && $token !== '';
        $hasRole = is_string($roleId) && $roleId !== '' &&
            is_string($secretId) && $secretId !== '';
        if ($hasToken === $hasRole) {
            throw new InvalidArgumentException('vault_auth_invalid');
        }
        $this->base = rtrim($address, '/');
        $this->mountPath = $mount;
    }

    /** @return array<string,string> */
    public function get(string $path): array
    {
        $response = $this->send('GET', $this->dataPath($path));
        $values = $response['data']['data'] ?? null;
        if (!is_array($values)) {
            throw new RuntimeException('vault_payload_invalid');
        }
        return $this->validateValues($values);
    }

    /** @param array<string,string> $values */
    public function put(string $path, array $values): void
    {
        $this->send('POST', $this->dataPath($path), [
            'data' => $this->validateValues($values),
        ]);
    }

    public function delete(string $path): void
    {
        // KV v2: soft-delete the latest version; no metadata destruction.
        $this->send('DELETE', $this->dataPath($path));
    }

    private function dataPath(string $path): string
    {
        return '/v1/' . $this->mountPath . '/data/'
            . ScopedSecretPath::validateInternalPath($path);
    }

    /** @param ?array<string,mixed> $payload @return array<string,mixed> */
    private function send(string $method, string $path, ?array $payload = null): array
    {
        $token = $this->accessToken();
        return $this->exchange($method, $path, $payload, $token);
    }

    private function accessToken(): string
    {
        if ($this->token !== null && $this->token !== '') {
            return $this->token;
        }
        if ($this->sessionToken !== null && time() < $this->expiresAt) {
            return $this->sessionToken;
        }
        // Tokens never go to STDOUT, logs, database or request parameters.
        $body = $this->exchange('POST', '/v1/auth/approle/login', [
            'role_id' => $this->roleId,
            'secret_id' => $this->secretId,
        ], null);
        $value = $body['auth']['client_token'] ?? null;
        $ttl = $body['auth']['lease_duration'] ?? 0;
        if (!is_string($value) || $value === '' || strlen($value) > 2048) {
            throw new RuntimeException('vault_auth_response_invalid');
        }
        $this->sessionToken = $value;
        $this->expiresAt = time() + max(0, min(3600, (int) $ttl) - 30);
        return $value;
    }

    /**
     * @param ?array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function exchange(string $method, string $path, ?array $payload, ?string $token): array
    {
        $encoded = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        if ($encoded !== null && strlen($encoded) > 1048576) {
            throw new InvalidArgumentException('vault_payload_too_large');
        }

        $headers = ['Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($token !== null) {
            $headers[] = 'X-Vault-Token: ' . $token;
        }
        if ($this->testExchange !== null) {
            // Test doubles are never configured by HTTP callers.
            $reply = ($this->testExchange)($method, $path, $headers, $encoded);
            $status = (int) ($reply['status'] ?? 0);
            $raw = $reply['body'] ?? '';
        } else {
            $curl = curl_init($this->base . $path);
            if ($curl === false) {
                throw new RuntimeException('vault_unavailable');
            }
            try {
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                if ($encoded !== null) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
                }
                $raw = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            } finally {
                curl_close($curl);
            }
        }
        if (!is_string($raw) || strlen($raw) > 2097152) {
            throw new RuntimeException('vault_unavailable');
        }
        if ($status === 404) {
            throw new DomainException('vault_secret_not_found');
        }
        if ($status === 401 || $status === 403) {
            throw new DomainException('vault_access_denied');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('vault_unavailable');
        }
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('vault_response_invalid');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('vault_response_invalid');
        }
        return $decoded;
    }

    /** @param array<mixed,mixed> $values @return array<string,string> */
    private function validateValues(array $values): array
    {
        if (count($values) > 24) {
            throw new InvalidArgumentException('vault_value_invalid');
        }
        $validated = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) ||
                !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) ||
                !is_string($value) || strlen($value) > 524288) {
                throw new InvalidArgumentException('vault_value_invalid');
            }
            $validated[$key] = $value;
        }
        return $validated;
    }
}
