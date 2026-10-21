<?php

use Illuminate\Database\Migrations\Migration;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

return new class extends Migration
{
    private const FLOW_KEY = 'owner-setup';

    private const OLD_PUSH_TITLE = 'Set up mobile and browser push (optional)';

    private const OLD_PUSH_DESCRIPTION = 'Only needed if subscribers should get alerts as push notifications. For the mobile app, mount your Firebase service-account JSON file into the container and set FIREBASE_SERVICE_ACCOUNT to its path. For browser push, generate VAPID keys with php artisan webpush:vapid. This ticks when the file can be read and both keys are set.';

    private const NEW_PUSH_TITLE = 'Set up mobile push (optional)';

    private const NEW_PUSH_DESCRIPTION = 'Only needed for the mobile app. Mount your Firebase service-account JSON file into the container and set FIREBASE_SERVICE_ACCOUNT to its path. This ticks when the file can be read.';

    /**
     * Splits the push step in two: push becomes mobile-only and a new web-push step covers browser
     * push, right after it. Owner edits survive: the push step is reworded only while its title and
     * description still equal the shipped text, and the new step is only created when missing.
     */
    public function up(): void
    {
        $flow = OnboardingFlow::query()->where('key', self::FLOW_KEY)->first();

        if ($flow === null) {
            return;
        }

        $push = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'push')->first();

        if ($push !== null) {
            $this->reword($push, self::OLD_PUSH_TITLE, self::OLD_PUSH_DESCRIPTION, self::NEW_PUSH_TITLE, self::NEW_PUSH_DESCRIPTION);
        }

        if (OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'web-push')->exists()) {
            return;
        }

        $order = ($push?->sort_order ?? OnboardingStep::query()->where('flow_id', $flow->id)->max('sort_order')) + 1;

        OnboardingStep::query()->where('flow_id', $flow->id)->where('sort_order', '>=', $order)->increment('sort_order');

        OnboardingStep::query()->create([
            'flow_id' => $flow->id,
            'key' => 'web-push',
            'type' => 'task',
            'title' => ['en' => 'Set up browser push (optional)'],
            'description' => ['en' => 'Only needed if subscribers should get alerts as browser push notifications. Generate the VAPID keys with php artisan webpush:vapid, which writes VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY to .env. Never change them later, or existing browser subscriptions stop working. The site must be served over HTTPS. Then clear the config cache and restart the queue worker. This ticks when both keys are set.'],
            'icon' => 'heroicon-o-bell-alert',
            'completion_mode' => 'condition',
            'condition_key' => 'web_push_configured',
            'is_required' => false,
            'is_active' => true,
            'sort_order' => $order,
        ]);
    }

    /**
     * Removes the web-push step, closes the gap in the order and restores the old push wording
     * (again only when untouched).
     */
    public function down(): void
    {
        $flow = OnboardingFlow::query()->where('key', self::FLOW_KEY)->first();

        if ($flow === null) {
            return;
        }

        $webPush = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'web-push')->first();

        if ($webPush !== null) {
            $order = $webPush->sort_order;
            $webPush->delete();
            OnboardingStep::query()->where('flow_id', $flow->id)->where('sort_order', '>', $order)->decrement('sort_order');
        }

        $push = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'push')->first();

        if ($push !== null) {
            $this->reword($push, self::NEW_PUSH_TITLE, self::NEW_PUSH_DESCRIPTION, self::OLD_PUSH_TITLE, self::OLD_PUSH_DESCRIPTION);
        }
    }

    private function reword(OnboardingStep $step, string $fromTitle, string $fromDescription, string $toTitle, string $toDescription): void
    {
        if (($step->title['en'] ?? null) !== $fromTitle || ($step->description['en'] ?? null) !== $fromDescription) {
            return;
        }

        $step->update(['title' => ['en' => $toTitle], 'description' => ['en' => $toDescription]]);
    }
};
