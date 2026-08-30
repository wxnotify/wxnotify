<?php

use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the admin dashboard loads without error for a staff user', function () {
    $this->get('/admin')->assertOk();
});

test('the stats overview widget reports real counts', function () {
    AlertRule::factory()->create(['is_active' => true]);
    AlertRule::factory()->create(['is_active' => false]);
    Location::factory()->create();
    NotificationChannel::factory()->create(['verified_at' => now()]);
    NotificationChannel::factory()->unverified()->create();

    livewire(AdminStatsOverview::class)
        ->assertSee((string) AlertRule::where('is_active', true)->count())
        ->assertSee((string) User::count())
        ->assertSee((string) Location::count())
        ->assertSee((string) NotificationChannel::whereNotNull('verified_at')->count());
});
