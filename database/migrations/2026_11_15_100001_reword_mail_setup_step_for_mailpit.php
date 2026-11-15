<?php

use Illuminate\Database\Migrations\Migration;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingFlow;
use Wallacemartinss\FilamentOnboarding\Models\OnboardingStep;

return new class extends Migration
{
    private const FLOW_KEY = 'owner-setup';

    private const OLD_MAIL = 'WxNotify sends sign-up confirmations, password resets and email alerts. Set MAIL_MAILER and its MAIL_* settings in .env to a real mail service; with the log mailer, mail is only written to the log. This ticks when the mailer is no longer log.';

    private const NEW_MAIL = 'WxNotify sends sign-up confirmations, password resets and email alerts. A fresh install uses the bundled Mailpit (MAIL_MAILER=mailpit), which catches every message and shows it in its own web page instead of delivering it, so you can follow verification links before real mail works. For real delivery set MAIL_MAILER=smtp and the MAIL_HOST, MAIL_PORT, MAIL_USERNAME and MAIL_PASSWORD of your mail service in .env, then recreate the containers. This ticks when the mailer is something other than log, array or mailpit.';

    /**
     * Rewords the mail step for the Mailpit default. An owner's own wording survives: the step
     * changes only while its description still equals the shipped text.
     */
    public function up(): void
    {
        $this->reword(self::OLD_MAIL, self::NEW_MAIL);
    }

    public function down(): void
    {
        $this->reword(self::NEW_MAIL, self::OLD_MAIL);
    }

    private function reword(string $from, string $to): void
    {
        $flow = OnboardingFlow::query()->where('key', self::FLOW_KEY)->first();

        if ($flow === null) {
            return;
        }

        $step = OnboardingStep::query()->where('flow_id', $flow->id)->where('key', 'mail')->first();

        if ($step === null || ($step->description['en'] ?? null) !== $from) {
            return;
        }

        $step->update(['description' => ['en' => $to]]);
    }
};
