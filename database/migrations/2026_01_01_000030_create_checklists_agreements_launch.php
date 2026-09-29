<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-customer checklist execution state
        Schema::create('checklist_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('stage_checklist_items')->cascadeOnDelete();
            $table->boolean('is_done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'checklist_item_id']);
        });

        // NDA / MoU / Contract
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('type', ['nda', 'mou', 'contract']);
            $table->string('title');
            $table->string('document_url')->nullable();
            $table->enum('status', ['draft', 'sent', 'in_review', 'pending_signature', 'signed', 'rejected', 'expired'])->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signatory_name')->nullable();
            $table->string('signatory_title')->nullable();
            $table->decimal('value', 14, 2)->nullable(); // contract value
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'type', 'status']);
        });

        // Launch plan per customer product
        Schema::create('launch_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_product_id')->nullable()->constrained('customer_products')->nullOnDelete();
            $table->string('title');
            $table->text('plan')->nullable();
            $table->date('target_date')->nullable();
            $table->enum('status', ['planning', 'ready', 'in_progress', 'launched', 'delayed'])->default('planning');
            $table->timestamp('launched_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // External dependencies, e.g. "Mobile app released"
        Schema::create('launch_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('launch_plan_id')->constrained('launch_plans')->cascadeOnDelete();
            $table->string('title'); // e.g. "Mobile app released"
            $table->enum('type', ['internal', 'external'])->default('external');
            $table->enum('status', ['pending', 'in_progress', 'done', 'blocked'])->default('pending');
            $table->date('due_date')->nullable();
            $table->boolean('is_blocking')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['launch_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_dependencies');
        Schema::dropIfExists('launch_plans');
        Schema::dropIfExists('agreements');
        Schema::dropIfExists('checklist_completions');
    }
};
