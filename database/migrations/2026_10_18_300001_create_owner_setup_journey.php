<?php

use Illuminate\Database\Migrations\Migration;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

return new class extends Migration
{
    private const FLOW_KEY = 'owner-setup';

    /**
     * Ships the owner setup checklist on the Admin panel. The rows stay editable in the panel
     * (Onboarding flows), so each one is only created when its key is missing: running this again,
     * or after an owner reworded a step, neither duplicates nor overwrites anything. The steps tick
     * themselves through the condition classes in app/Onboarding/Conditions.
     */
    public function up(): void
    {
        $flow = OnboardingFlow::query()->firstOrCreate(['key' => self::FLOW_KEY], [
            'panel_id' => 'admin',
            'title' => ['en' => 'Set up this server'],
            'description' => ['en' => 'What WxNotify needs to run on this server. Most steps tick themselves as soon as they are true.'],
            'icon' => 'heroicon-o-server-stack',
            'visibility_condition' => 'is_owner',
            'is_active' => true,
            'is_dismissible' => true,
            'sort_order' => 0,
        ]);

        foreach ($this->steps() as $index => $step) {
            OnboardingStep::query()->firstOrCreate(['flow_id' => $flow->id, 'key' => $step['key']], [
                'type' => 'task',
                'title' => ['en' => $step['title']],
                'description' => ['en' => $step['description']],
                'icon' => $step['icon'],
                'cta_label' => isset($step['cta_label']) ? ['en' => $step['cta_label']] : null,
                'cta_url' => $step['cta_url'] ?? null,
                'completion_mode' => $step['condition_key'] === null ? 'manual' : 'condition',
                'condition_key' => $step['condition_key'],
                'visibility_condition' => $step['visibility_condition'] ?? null,
                'is_required' => $step['is_required'] ?? true,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Removes the shipped flow; its steps and everyone's progress go with it (cascade).
     */
    public function down(): void
    {
        OnboardingFlow::query()->where('key', self::FLOW_KEY)->get()->each->delete();
    }

    /**
     * @return list<array{key: string, title: string, description: string, icon: string, condition_key: string|null, cta_label?: string, cta_url?: string, visibility_condition?: string, is_required?: bool}>
     */
    private function steps(): array
    {
        return [
            [
                'key' => 'basics',
                'title' => 'Set the basics in .env',
                'description' => 'WxNotify reads a few settings from the .env file (or your Compose file) when it starts. APP_KEY encrypts sessions and stored secrets, APP_URL is the address used in links and emails, and WXNOTIFY_MODE picks the product: it defaults to cloud, so a self-hosted server must set WXNOTIFY_MODE=selfhost. If the server sits behind a load balancer or Cloudflare, set WXNOTIFY_TRUSTED_PROXIES so visitor addresses are read correctly. Restart the containers after a change, then tick this off yourself.',
                'icon' => 'heroicon-o-cog-6-tooth',
                'condition_key' => null,
            ],
            [
                'key' => 'weather-feed',
                'title' => 'Connect the weather feed',
                'description' => 'WxNotify gets its weather products from a feed: NWWS, or EMWIN if that is your feed. For NWWS, put your login in .env as NWWS_USERNAME and NWWS_PASSWORD: the NWWS container reads them, not this app, so restart that container after a change. This ticks once the first product arrives from the weather feed, which proves the feed and its container work.',
                'icon' => 'heroicon-o-signal',
                'condition_key' => 'feed_product_received',
            ],
            [
                'key' => 'reference-data',
                'title' => 'Install the reference data',
                'description' => 'WxNotify needs its product catalog, the "Did you know" tips and the list of tracked boundary shapefiles before alerts can be set up. On the Setup page, press Install reference data. It only adds what is missing and never changes what is already there.',
                'icon' => 'heroicon-o-circle-stack',
                'condition_key' => 'reference_data_installed',
                'cta_label' => 'Open Setup',
                'cta_url' => '/admin/setup',
            ],
            [
                'key' => 'boundaries',
                'title' => 'Import the boundaries',
                'description' => 'WxNotify uses NWS boundary shapefiles (counties, zones and more) to work out which places a warning covers. On Shapefile Layers, press Check for shapefile updates: the scan runs on the queue, so it needs the worker from the next step, and since that worker also delivers alerts, alerts wait while it runs (start it at a quiet time). Then review each imported layer and press Activate. If a long first scan fails, run the worker with a longer --timeout (for example 900; queue:listen defaults to 60 seconds), set DB_QUEUE_RETRY_AFTER above it and press the button again: it re-imports any layer that did not finish.',
                'icon' => 'heroicon-o-map',
                'condition_key' => 'boundaries_active',
                'cta_label' => 'Open Shapefile Layers',
                'cta_url' => '/admin/shapefile-layers',
            ],
            [
                'key' => 'scheduler-queue',
                'title' => 'Run the scheduler and a queue worker',
                'description' => 'WxNotify needs two processes running all the time: the scheduler (a cron entry running schedule:run every minute, or schedule:work) for its timed work, and a queue worker (queue:work or queue:listen) that sends alerts and runs imports. QUEUE_CONNECTION=sync is not enough: it would run the heartbeat inside the scheduler and hide a missing worker. This ticks when the heartbeat job, dispatched every minute, was run in the last 10 minutes.',
                'icon' => 'heroicon-o-clock',
                'condition_key' => 'scheduler_and_queue_running',
            ],
            [
                'key' => 'mail',
                'title' => 'Set up outgoing email',
                'description' => 'WxNotify sends sign-up confirmations, password resets and email alerts. Set MAIL_MAILER and its MAIL_* settings in .env to a real mail service; with the log mailer, mail is only written to the log. This ticks when the mailer is no longer log.',
                'icon' => 'heroicon-o-envelope',
                'condition_key' => 'mail_configured',
            ],
            [
                'key' => 'push',
                'title' => 'Set up mobile and browser push (optional)',
                'description' => 'Only needed if subscribers should get alerts as push notifications. For the mobile app, mount your Firebase service-account JSON file into the container and set FIREBASE_SERVICE_ACCOUNT to its path. For browser push, generate VAPID keys with php artisan webpush:vapid. This ticks when the file can be read and both keys are set.',
                'icon' => 'heroicon-o-device-phone-mobile',
                'condition_key' => 'push_configured',
                'is_required' => false,
            ],
            [
                'key' => 'billing',
                'title' => 'Set up billing',
                'description' => 'Subscribers pay for their plans through Stripe or Paddle. Set the Stripe secret key and webhook signing secret, or the Paddle API key and webhook signing secret, in App Settings > Secrets or in .env (STRIPE_SECRET, STRIPE_WEBHOOK_SECRET, PADDLE_API_KEY, PADDLE_WEBHOOK_SECRET); a value saved in App Settings wins. This ticks when a Stripe secret key or a Paddle API key is set.',
                'icon' => 'heroicon-o-credit-card',
                'condition_key' => 'billing_configured',
                'visibility_condition' => 'is_cloud_mode',
            ],
            [
                'key' => 'support-widget',
                'title' => 'Set up the support chat',
                'description' => 'Subscribers reach support through the Chatwoot chat widget on their dashboard. Set CHATWOOT_BASE_URL and CHATWOOT_WEBSITE_TOKEN in .env, and CHATWOOT_IDENTITY_SECRET so Chatwoot can verify who is chatting. This ticks when the base URL and the website token are set.',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'condition_key' => 'support_widget_configured',
                'visibility_condition' => 'is_cloud_mode',
            ],
        ];
    }
};
