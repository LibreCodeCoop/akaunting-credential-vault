<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\CredentialVault\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\CredentialVault\Support\ModuleStackPlan;
use Throwable;

/**
 * Install already-staged Akaunting modules using native per-company CLI.
 * Offline and dry-run by default. Never downloads, unzips or updates core.
 */
final class InstallModuleStack extends Command
{
    protected $signature = 'credential-vault:install-stack
        {company : Existing Akaunting company ID}
        {--with=all : all, nfse or banking-connectors}
        {--locale=en-GB : Akaunting module installation locale}
        {--apply : Register each previously deployed module with native module:install}';

    protected $description = 'Preview or install a verified CredentialVault module stack';

    public function handle(ModuleStackPlan $planner): int
    {
        try {
            $id = filter_var($this->argument('company'), FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                throw new \InvalidArgumentException('invalid_company_id');
            }
            $locale = (string) $this->option('locale');
            if (!preg_match('/^[a-z]{2}-[A-Z]{2}$/D', $locale)) {
                throw new \InvalidArgumentException('invalid_module_locale');
            }
            $with = (string) $this->option('with');
            $company = DB::table('companies')->where('id', (int) $id)
                ->where('enabled', 1)->exists();
            if (!$company) {
                throw new \InvalidArgumentException('company_not_available');
            }
            $names = ['credential-vault', 'nfse', 'banking-connectors'];
            $installed = [];
            foreach ($names as $alias) {
                $installed[$alias] = DB::table('modules')
                    ->where('company_id', (int) $id)
                    ->where('alias', $alias)->exists();
            }

            // Preflight ALL requested modules before the first change.
            $steps = $planner->prepare(base_path('modules'), $with, $installed);
            $apply = (bool) $this->option('apply');
            if (!$apply) {
                $this->line(json_encode([
                    'status' => 'dry_run',
                    'company_id' => (int) $id,
                    'modules' => array_map(static fn (array $step) => [
                        'alias' => $step['alias'],
                        'action' => $step['action'],
                    ], $steps),
                    'core_changes' => 0,
                    'downloads' => 0,
                ], JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            }

            if (getenv('CREDENTIAL_VAULT_ALLOW_MODULE_INSTALL') !== 'yes') {
                throw new \RuntimeException('module_stack_install_not_authorized');
            }

            $performed = [];
            foreach ($steps as $step) {
                if ($step['action'] === 'already_installed') {
                    continue;
                }
                $code = Artisan::call('module:install', [
                    'alias' => $step['alias'],
                    'company' => (int) $id,
                    'locale' => $locale,
                ]);
                // module:install is an Akaunting-native per-company operation,
                // not a Composer/package download. Check durable DB state.
                $exists = DB::table('modules')
                    ->where('company_id', (int) $id)
                    ->where('alias', $step['alias'])->exists();
                if ($code !== 0 || !$exists) {
                    throw new \RuntimeException('module_stack_install_failed');
                }
                $performed[] = $step['alias'];
            }

            $this->line(json_encode([
                'status' => 'installed',
                'company_id' => (int) $id,
                'performed' => $performed,
                'core_changes' => 0,
                'downloads' => 0,
            ], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $e) {
            // A prior installation may already have succeeded. Never
            // automatically uninstall modules or erase accounting evidence.
            $this->error($e->getMessage() === 'module_stack_install_not_authorized'
                ? 'module_stack_install_not_authorized'
                : 'module_stack_preflight_or_install_failed');
            return self::FAILURE;
        }
    }
}
