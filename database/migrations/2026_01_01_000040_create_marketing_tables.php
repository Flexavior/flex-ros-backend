<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Channel register (Facebook, Viber, Telegram, Website, ...)
        Schema::create('marketing_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->enum('type', ['social', 'messaging', 'search', 'email', 'offline', 'other'])->default('social');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Campaign plan with approval workflow
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('marketing_channels')->cascadeOnDelete();
            $table->foreignId('product_service_id')->nullable()->constrained('products_services')->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('objective')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['approval_status', 'channel_id']);
        });

        // Posting schedule
        Schema::create('marketing_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'published', 'cancelled'])->default('draft');
            $table->string('media_url')->nullable();
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('leads_generated')->default(0);
            $table->timestamps();

            $table->index(['scheduled_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_posts');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_channels');
    }
};
