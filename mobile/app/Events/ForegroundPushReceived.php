<?php

namespace App\Events;

/**
 * Named in the `event` key of the FCM data of every WxNotify push. The firebase plugin dispatches it in
 * an ephemeral PHP runtime (`native:dispatch-push-event`) only when a push arrives while the app is open.
 * It has no constructor arguments on purpose: the push sends `payload` = `{}`, and the plugin builds the
 * event as `new $eventClass(...$payload)`.
 *
 * The class name is duplicated in the backend (MobilePushTransport::FOREGROUND_PUSH_EVENT) and must be
 * changed in both places: a mismatch silently degrades to the old behaviour (the plugin only logs that
 * the event class does not exist).
 * Handled by App\Listeners\MarkForegroundPush.
 */
class ForegroundPushReceived {}
