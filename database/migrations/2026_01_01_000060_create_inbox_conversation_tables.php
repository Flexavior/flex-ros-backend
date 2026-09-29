<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-pane conversations mirrored from the multi-channel service (ConvyMes):
        // Facebook Page Messenger, Viber, LINE and future channels.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 30);                       // facebook|viber|line|...
            $table->string('external_id', 100);                  // conversation id at the gateway
            $table->string('customer_ref', 150)->nullable();     // platform user id / phone / PSID
            $table->string('customer_name')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 30)->default('unassigned'); // unassigned|open|pending|closed
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete(); // who took ownership
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
            $table->index(['status', 'last_message_at']);
            $table->index('owner_id');
        });

        // Message thread (inbound customer queries + outbound replies)
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('channel', 30);
            $table->enum('direction', ['in', 'out']);
            $table->text('body')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('sender_ref')->nullable();
            $table->string('external_message_id', 150)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_message_id']);
            $table->index(['conversation_id', 'sent_at']);
        });

        // Ownership & flow audit trail — answers "who took ownership, when, and why"
        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('type', 40); // ingested|claimed|assigned|reassigned|released|status_changed|replied|linked|note
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
