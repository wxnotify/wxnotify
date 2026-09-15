<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            // null-on-delete, not restrict: unlike location_id (which a
            // rule structurally needs), a template is a pure rendering
            // nicety -- a rule whose template gets deleted just falls
            // back to the next tier down (custom_subject_template /
            // message_body_style, then displayName()/raw text), a fully
            // working state. Matches this schema's real precedent for
            // optional relations (location_id is also nullOnDelete()) --
            // see the design doc's corrected note on this.
            $table->foreignId('alert_template_id')->nullable()->after('message_body_style')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('alert_template_id');
        });
    }
};
