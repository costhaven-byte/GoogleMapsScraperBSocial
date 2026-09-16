<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run is one search job (scrape), a re-process of saved places, or an import
     * of a legacy GMSCraper report. Counters are denormalised so lists never COUNT().
     */
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('queued');
            $table->json('queries');
            $table->json('options')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->json('progress')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('places_count')->default(0);
            $table->unsignedInteger('leads_count')->default(0);
            $table->unsignedInteger('hot_count')->default(0);
            $table->unsignedInteger('warm_count')->default(0);
            $table->unsignedInteger('unreachable_count')->default(0);
            $table->unsignedInteger('inactive_count')->default(0);
            $table->unsignedInteger('tier_a_count')->default(0);
            $table->unsignedInteger('tier_b_count')->default(0);
            $table->unsignedInteger('tier_c_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'type', 'id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
