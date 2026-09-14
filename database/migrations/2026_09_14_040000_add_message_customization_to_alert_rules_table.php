<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->string('custom_subject_template')->nullable()->after('quiet_hours_end');
            $table->string('message_body_style')->nullable()->after('custom_subject_template');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->dropColumn(['custom_subject_template', 'message_body_style']);
        });
    }
};
