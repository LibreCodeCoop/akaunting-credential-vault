<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Providers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Modules\CredentialVault\Console\Commands\ImportNfseConfig;
use Modules\CredentialVault\Console\Commands\InstallModuleStack;
use Modules\CredentialVault\Support\VaultProfileStore;
use Modules\CredentialVault\Support\VaultClientFactory;

/**
 * Shared infrastructure only. Never opens Vault on HTTP startup, never
 * stores an administrator token globally and never exposes a secrets route.
 */
final class Main extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VaultClientFactory::class, VaultClientFactory::class);
        $this->app->bind(VaultProfileStore::class, static function (): VaultProfileStore {
            return new VaultProfileStore(
                DB::connection()->getPdo(),
                static fn (string $data): string => Crypt::encryptString($data),
                static fn (string $data): string => Crypt::decryptString($data),
                DB::connection()->getTablePrefix(),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ImportNfseConfig::class, InstallModuleStack::class]);
        }
    }
}
