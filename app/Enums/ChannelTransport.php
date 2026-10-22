<?php

namespace App\Enums;

enum ChannelTransport: string
{
    case Apprise = 'apprise';
    case Webhook = 'webhook';
    case WebPush = 'webpush';
    case Mail = 'mail';
    case MobilePush = 'mobile_push';
    case Sms = 'sms';
    case Messaging = 'messaging';
}
