<?php

namespace App\Events;

/**
 * Named in the `event` key of the FCM data of every WxNotify push. The firebase plugin dispatches it in
 * an ephemeral PHP runtime (`native:dispatch-push-event`) only when a push arrives while the app is open.
 * It has no constructor arguments on purpose: the push sends `payload` = `{}`, and the plugin builds the
 * event as `new $eventClass(...$payload)`. Handled by App\Listeners\MarkForegroundPush.
 */
class ForegroundPushReceived {}
