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
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Null on delete keeps the money history (and /v1/usage reconciliation) intact when an account is removed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            // The exact body sent to Pixelforge, replayed byte-for-byte with the same Idempotency-Key.
            $table->json('request_payload');

            $table->bigInteger('estimated_charge_min_nanos');
            $table->bigInteger('estimated_charge_max_nanos');

            $table->json('rates')->nullable();
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charge_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->string('failure_source')->nullable();

            $table->boolean('possibly_submitted')->default(false);
            $table->unsignedSmallInteger('submit_attempts')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // UTC Y-m-d of completed_at for succeeded generations: the day Pixelforge bills it.
            $table->string('billed_on', 10)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_synced_at']);
            $table->index(['billed_on', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
