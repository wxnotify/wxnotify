<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCategory;
use App\Enums\ProductFamily;
use App\Enums\PushSoundKind;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use App\Support\PushSounds;
use Illuminate\Support\Collection;

/**
 * The push sound a rule chose for an alert's kind, or null (the app's default sound) when it chose none.
 */
class ResolveAlertPushSound
{
    private const SHORT_FUSE_CODES = ['TO.W', 'SV.W', 'FF.W', 'DS.W', 'SQ.W'];

    private const CONVECTIVE_WATCH_CODES = ['TO.A', 'SV.A'];

    /**
     * @param  Collection<int, IngestedProductSegment>|null  $segments  the matched segments of $bulletin
     */
    public function __invoke(AlertRule $rule, ?IngestedProduct $bulletin = null, ?Collection $segments = null): ?string
    {
        $sounds = $rule->push_sounds;

        if (! is_array($sounds)) {
            return null;
        }

        $slug = $sounds[$this->kind($rule, $bulletin, $segments)->value] ?? null;

        return is_string($slug) && PushSounds::isKnown($slug) ? $slug : null;
    }

    /**
     * @param  Collection<int, IngestedProductSegment>|null  $segments
     */
    private function kind(AlertRule $rule, ?IngestedProduct $bulletin, ?Collection $segments): PushSoundKind
    {
        if (in_array($rule->category, [AlertCategory::DailySummary->value, AlertCategory::OutlookReport->value], true)
            || in_array($rule->category, [AlertCategory::DailySummary, AlertCategory::OutlookReport], true)) {
            return PushSoundKind::Other;
        }

        $ids = collect($segments ?? [])->pluck('product_id')->filter();

        if ($ids->isEmpty() && $bulletin?->product_id !== null) {
            $ids = collect([$bulletin->product_id]);
        }

        if ($ids->isEmpty()) {
            return PushSoundKind::Other;
        }

        return Product::whereIn('id', $ids->unique()->all())->get()
            ->map(fn (Product $product): PushSoundKind => $this->kindOf($product))
            ->sortBy(fn (PushSoundKind $kind): int => $kind->priority())
            ->first() ?? PushSoundKind::Other;
    }

    private function kindOf(Product $product): PushSoundKind
    {
        return match (true) {
            $product->family === ProductFamily::Warning && in_array($product->code, self::SHORT_FUSE_CODES, true) => PushSoundKind::ShortFuseWarning,
            $product->family === ProductFamily::Warning => PushSoundKind::OtherWarning,
            $product->family === ProductFamily::Watch && in_array($product->code, self::CONVECTIVE_WATCH_CODES, true) => PushSoundKind::ConvectiveWatch,
            $product->family === ProductFamily::Watch => PushSoundKind::OtherWatch,
            $product->family === ProductFamily::Advisory => PushSoundKind::Advisory,
            default => PushSoundKind::Other,
        };
    }
}
