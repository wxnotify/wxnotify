<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product of a Weather Alerts or SPC & WPC rule can carry its own notification subject, which
 * overrides the rule-wide `custom_subject_template`. Rollback: down() only drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->string('custom_subject_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->dropColumn('custom_subject_template');
        });
    }
};
