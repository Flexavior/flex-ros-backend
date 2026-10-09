<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->json('raw_claims')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('crm_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 30); // lead|engagement
            $table->string('field_key', 80);
            $table->string('label', 120);
            $table->string('field_type', 20); // text|select|date|boolean
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['entity', 'field_key']);
            $table->index(['entity', 'is_active', 'sort_order']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('lead_source', 80)->nullable();
            $table->string('product_interest', 120)->nullable();
            $table->string('customer_segment', 120)->nullable();
            $table->string('contact_role', 120)->nullable();
            $table->string('current_stage', 80)->nullable();
            $table->string('interest_level', 80)->nullable();
            $table->string('buying_timeline', 80)->nullable();
            $table->string('primary_contact_method', 80)->nullable();
            $table->string('last_activity_outcome', 120)->nullable();
            $table->json('custom_fields')->nullable();

            $table->index('current_stage');
            $table->index('lead_source');
        });

        // Map legacy values into new columns for backward compatibility.
        DB::table('leads')->whereNotNull('source')->update(['lead_source' => DB::raw('source')]);
        DB::table('leads')->whereNull('current_stage')->update([
            'current_stage' => DB::raw(
                "CASE status
                    WHEN 'new' THEN 'New'
                    WHEN 'contacted' THEN 'Contacted'
                    WHEN 'qualified' THEN 'Qualified'
                    WHEN 'appointment' THEN 'Demo / Meeting'
                    WHEN 'converted' THEN 'Won'
                    WHEN 'lost' THEN 'Lost'
                    ELSE 'New'
                END"
            ),
        ]);

        Schema::table('engagements', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable();
            $table->string('contact_method', 80)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('purpose', 150)->nullable();
            $table->string('activity_outcome', 120)->nullable();
            $table->text('customer_response')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->foreignId('assigned_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('completed')->default(false);
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();

            $table->index('occurred_at');
            $table->index('next_follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropForeign(['assigned_owner_id']);
            $table->dropColumn([
                'occurred_at',
                'contact_method',
                'contact_person',
                'purpose',
                'activity_outcome',
                'customer_response',
                'next_follow_up_at',
                'assigned_owner_id',
                'completed',
                'notes',
                'custom_fields',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'lead_source',
                'product_interest',
                'customer_segment',
                'contact_role',
                'current_stage',
                'interest_level',
                'buying_timeline',
                'primary_contact_method',
                'last_activity_outcome',
                'custom_fields',
            ]);
        });

        Schema::dropIfExists('crm_field_definitions');
        Schema::dropIfExists('user_identities');
    }
};
