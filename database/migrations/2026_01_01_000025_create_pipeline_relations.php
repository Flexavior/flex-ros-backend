<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix FK ordering: customers.lead_id -> leads.id
        // (leads.customer_id FK is already added by create_leads, so only the
        // reverse side needs to be attached here.)
        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });

        // Customer <-> Product/Service mapping
        Schema::create('customer_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_service_id')->constrained('products_services')->cascadeOnDelete();
            $table->decimal('agreed_price', 14, 2)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->unique(['customer_id', 'product_service_id']);
        });

        // Follow-up log on leads (drives stale detection via status_updated_at)
        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 50)->nullable(); // call|visit|viber|telegram|email|discord|teams
            $table->string('summary');
            $table->text('next_action')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'status_updated_at']);
        });

        // Appointments: meetings/calls scheduled with a lead
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // owner/attendee
            $table->string('title');
            $table->text('agenda')->nullable();
            $table->timestamp('scheduled_at');
            $table->string('location', 200)->nullable();
            $table->string('status', 30)->default('scheduled'); // scheduled|done|cancelled|no_show
            $table->text('outcome')->nullable();
            $table->timestamps();

            $table->index(['scheduled_at', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('engagements');
        Schema::dropIfExists('customer_products');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });
    }
};
