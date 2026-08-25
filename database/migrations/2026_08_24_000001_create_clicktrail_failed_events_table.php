<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clicktrail_failed_events', static function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('event');
            $table->json('payload');
            $table->string('exception', 1000);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicktrail_failed_events');
    }
};
