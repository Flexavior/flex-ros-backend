<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registered external channels (Viber Business, Telegram, Discord, MS Teams)
        Schema::create('channel_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique(); // viber|telegram|discord|teams
            $table->string('display_name');
            $table->text('credentials')->nullable(); // encrypted JSON (bot tokens etc.)
            $table->enum('status', ['disconnected', 'connected', 'error'])->default('disconnected');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });

        // Unified inbox/outbox — single-pane view across all channels
        Schema::create('integration_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_integration_id')->constrained('channel_integrations')->cascadeOnDelete();
            $table->string('direction', 10)->default('inbound'); // inbound|outbound
            $table->string('external_ref')->nullable(); // message id on the platform
            $table->string('contact_name')->nullable();
            $table->string('contact_handle')->nullable(); // phone/@handle
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['unread', 'read', 'replied', 'archived'])->default('unread');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['lead_id', 'customer_id']);
        });

        // Pluggable modules registry: Finance, Project Management, HR, ...
        Schema::create('module_registry', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('version', 20)->nullable();
            $table->string('base_path')->nullable(); // e.g. /api/v1/finance
            $table->enum('status', ['available', 'enabled', 'disabled'])->default('available');
            $table->json('manifest')->nullable(); // menu, permissions, endpoints
            $table->timestamps();
        });

        // System settings: dynamic checklists config, dashboard thresholds, IDs
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->string('group', 50)->default('crm');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('module_registry');
        Schema::dropIfExists('integration_messages');
        Schema::dropIfExists('channel_integrations');
    }
};
