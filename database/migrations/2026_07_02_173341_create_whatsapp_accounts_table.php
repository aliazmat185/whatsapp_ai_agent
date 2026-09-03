<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained('vendors')->cascadeOnDelete();

            $table->string('phone_number_id')->nullable()->unique(); // Meta's phone_number_id, issued by provisioning
            $table->string('waba_id')->nullable(); // shared platform WABA in the default v1 path
            $table->string('display_phone_number')->nullable();

            // Reserved for a future "bring your own WABA" tier — null in default v1 path.
            $table->text('access_token')->nullable();
            $table->boolean('uses_platform_token')->default(true);

            $table->enum('onboarding_status', [
                'number_submitted', 'verifying', 'registered', 'active', 'failed', 'disabled',
            ])->default('number_submitted');
            $table->enum('verification_method', ['sms', 'voice'])->nullable();
            $table->text('rejection_reason')->nullable();

            $table->enum('status', ['pending', 'active', 'disabled'])->default('pending');
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
