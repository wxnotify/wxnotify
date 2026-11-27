<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\GenerateViewSlug;
use App\Actions\Products\BuildReadingText;
use App\Actions\Products\GetProductDetails;
use App\Models\IngestedProduct;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Http\Response;

class ViewProductController extends Controller
{
    private const NOT_FOUND_CACHE = 'public, max-age=60';

    private const FOUND_CACHE = 'public, max-age=31536000, immutable';

    public function __invoke(string $slug, GenerateViewSlug $generateViewSlug, GetProductDetails $getProductDetails, BuildReadingText $buildReadingText): Response
    {
        // Registers the dashboard panel's colors, fonts and theme for the layout.
        Filament::setCurrentPanel('dashboard');
        Filament::bootCurrentPanel();

        $product = IngestedProduct::with('product')->where('view_slug', $slug)->first();

        if ($product === null || ($product->is_test && app()->isProduction())) {
            return response()->view('view-product.not-found', [], 404)
                ->header('Cache-Control', self::NOT_FOUND_CACHE);
        }

        $issuedAt = $generateViewSlug->issuedAt($product) ?? $product->received_at;
        $title = $product->displayTitle();
        $office = filled($product->cccc) ? strtoupper($product->cccc) : null;

        return response()->view('view-product.show', [
            'product' => $product,
            'title' => $title,
            'headline' => BuildReadingText::readableHeadline($product->headline),
            'reading' => $buildReadingText($product),
            'office' => $office,
            'description' => trim("{$title}".($office ? " issued by {$office}" : '').($issuedAt ? ' on '.$this->format($issuedAt) : '')).'.',
            'details' => $getProductDetails($product),
            'loginUrl' => rtrim((string) config('app.url'), '/').route('filament.dashboard.auth.login', absolute: false),
        ])->header('Cache-Control', self::FOUND_CACHE);
    }

    private function format(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d H:i').' UTC';
    }
}
