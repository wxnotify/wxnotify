<?php

use App\Actions\Pipeline\BackfillViewSlugs;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BackfillViewSlugs::class)();
    }

    public function down(): void
    {
        // Slugs are derived data; the column's own migration drops them.
    }
};
