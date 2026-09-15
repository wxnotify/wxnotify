<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category');
            $table->text('subject_template')->nullable();
            // Only meaningful for category = weather_alerts -- an array of
            // App\Enums\AlertTemplateSection values. Always null/ignored
            // for spc_wpc templates, which only ever use subject_template.
            $table->json('sections')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_templates');
    }
};
