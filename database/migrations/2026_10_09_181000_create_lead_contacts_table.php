<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('name', \App\Domain\Crm\CrmConfigLimits::LEAD_CONTACT_NAME_MAX)->nullable();
            $table->string('role', \App\Domain\Crm\CrmConfigLimits::LEAD_CONTACT_ROLE_MAX)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', \App\Domain\Crm\CrmConfigLimits::LEAD_CONTACT_PHONE_MAX)->nullable();
            $table->string('viber_id', \App\Domain\Crm\CrmConfigLimits::LEAD_CONTACT_VIBER_ID_MAX)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['lead_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_contacts');
    }
};
