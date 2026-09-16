<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared daily scraping budget counts places uploaded in the last 24 hours.
     */
    public function up(): void
    {
        Schema::table('run_places', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('run_places', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
