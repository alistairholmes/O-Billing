<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard notifications table, used by Filament database
 * notifications so the on-site worker can report the outcome of a queued Sage
 * operation back to the user who triggered it (shown in the panel's bell).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // JSON (not text): Filament's database notifications query the data
            // column with the JSON operator data->>'format', which Postgres only
            // allows on a json/jsonb column.
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
