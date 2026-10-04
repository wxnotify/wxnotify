<?php

return [

    /*
    | Base URL of the WxNotify backend the app talks to (no trailing slash). Build-time only;
    | a user-editable server URL for self-hosted instances is a later feature.
    */
    'api_url' => rtrim((string) env('WXNOTIFY_API_URL', ''), '/'),

    /*
    | The deep link the browser sign-in returns to. Must equal the backend's
    | config('wxnotify.app_auth.callback_uri'); the code and state follow as path segments.
    */
    'callback_uri' => 'wxnotify://auth/callback',

    /*
    | How many feed entries the offline cache keeps.
    */
    'feed_cache_size' => 50,

];
