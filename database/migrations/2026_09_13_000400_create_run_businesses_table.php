<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pipeline outcome for every business in a run:
     *   stage "lead"        reachable (tier A-C), scored
     *   stage "unreachable" tier D, never scored
     *   stage "inactive"    dropped by the activity gate
     * Filterable fields are real columns; the full evidence lives in `detail`.
     */
    public function up(): void
    {
        Schema::create('run_businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20);
            $table->string('query', 255);
            $table->string('place_key', 191);
            $table->string('name', 255);
            $table->string('category', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('website')->nullable();
            $table->text('maps_url')->nullable();
            $table->string('website_status', 150)->nullable();
            $table->char('contact_tier', 1)->nullable();
            $table->string('reason_code', 40)->nullable();
            $table->string('email', 254)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('priority', 10)->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('review_count')->default(0);
            $table->string('newest_review', 60)->nullable();
            $table->json('pitch')->nullable();
            $table->json('detail');
            $table->timestamps();

            $table->unique(['run_id', 'place_key']);
            $table->index(['run_id', 'stage', 'score']);
            $table->index(['stage', 'contact_tier', 'score']);
            $table->index(['query', 'stage']);
            $table->index('place_key');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_businesses');
    }
};
