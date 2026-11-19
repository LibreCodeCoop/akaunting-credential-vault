<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Contracts;

/**
 * Infrastructure contract only. Every consumer controls its own purpose,
 * company authorization and exact path. Never expose this interface via HTTP.
 */
interface SecretStore
{
    /** @return array<string,string> */
    public function get(string $path): array;
    /** @param array<string,string> $values */
    public function put(string $path, array $values): void;
    public function delete(string $path): void;
}
