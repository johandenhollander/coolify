<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->unsignedInteger('preview_deployments_limit')
                ->nullable()
                ->after('is_pr_deployments_public_enabled')
                ->comment('Maximum number of preview deployments that can exist at the same time. Null means no limit.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('preview_deployments_limit');
        });
    }
};
