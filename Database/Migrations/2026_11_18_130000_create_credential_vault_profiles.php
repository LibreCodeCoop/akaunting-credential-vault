<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Encrypted configuration only; no certificate binaries or app credentials. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lcc_credential_vault_profiles')) {
            return;
        }
        Schema::create('lcc_credential_vault_profiles', static function (Blueprint $table): void {
            $table->string('purpose', 80)->primary();
            $table->longText('encrypted_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // A rollback must NEVER silently destroy encrypted Vault credentials.
    }
};
