<?php

use App\Support\ProductSearchPresenter;

test('a recent search reads as its text, type, office and period joined by dots', function () {
    expect(ProductSearchPresenter::recentLabel(['q' => 'AFD', 'type' => null, 'type_label' => null, 'wfo' => 'MOB', 'period' => 'week']))->toBe('AFD · MOB · Past 7 days')
        ->and(ProductSearchPresenter::recentLabel(['q' => '', 'type' => 4, 'type_label' => 'Tornado Warning', 'wfo' => '', 'period' => '']))->toBe('Tornado Warning')
        ->and(ProductSearchPresenter::recentLabel(['q' => 'afd', 'type' => null, 'type_label' => null, 'wfo' => '', 'period' => '']))->toBe('afd');
});

test('a result row reads as its office and how long ago it was issued, or just the time without an office', function () {
    $this->freezeTime();

    expect(ProductSearchPresenter::subtitle(['office' => 'KMOB', 'issued_at' => now()->subHours(2)->toIso8601String()]))->toBe('KMOB · 2 hours ago')
        ->and(ProductSearchPresenter::subtitle(['office' => null, 'issued_at' => now()->subMinutes(5)->toIso8601String()]))->toBe('5 minutes ago');
});
