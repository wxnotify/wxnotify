<?php

use Illuminate\Support\HtmlString;

/**
 * Generate the app's brand name
 */
if (! function_exists('generateBrandName')) {
    function generateBrandName(): HtmlString
    {
        return new HtmlString('<div class="flex"><div><img class="pr-2" width="36" height="auto" src="/images/logo.png" /></div><div class="p-2">'.config('app.name').'</div></div>');
    }
}
