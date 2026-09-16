<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw places as scraped from Google Maps. Kept so a run can be re-processed
     * (new rules, fresh website checks) without contacting Google again.
     */
    public function up(): void
    {
        Schema::create('run_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->string('query', 255);
            $table->string('place_key', 191);
            $table->string('name', 255);
            $table->json('data');
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'place_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_places');
    }
};
