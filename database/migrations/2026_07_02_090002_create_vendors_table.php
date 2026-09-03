<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('business_name');
            $table->string('business_type')->nullable();
            $table->foreignId('vendor_package_id')->constrained('vendor_packages')->restrictOnDelete();

            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('suspended_reason')->nullable();

            // Assumption A10: separate contact number for order/alert notifications,
            // distinct from the customer-facing WhatsApp business number.
            $table->string('notification_phone')->nullable();

            $table->string('default_currency', 3)->default('PKR');
            $table->string('timezone')->default('Asia/Karachi');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
