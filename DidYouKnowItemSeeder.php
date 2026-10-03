<?php

namespace Database\Seeders;

use App\Models\ContactType;
use App\Support\SeederUtils;
use Illuminate\Database\Seeder;

class DidYouKnowItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tableData = [
            [
                'item' => 'You may set "active" and "quiet" times using an alert schedule. For example, you can create a "Daytime only" schedule and use it for non life-threatening notifications, so you are not woke up while you are sleeping.',
            ],
            [
                'item' => 'You may create multiple locations, not just for your own. If you\'d like '.config('app.name').' to notify you for weather affecting your family and friends all across the US, '.config('app.name').' has you covered! Just create multiple location profiles and use them in creating additional alerts.',
            ],
            [
                'item' => 'The "Wx" in WxNotify is an abbreviation often used in the weather community for weather.',
            ],
            [
                'item' => config('app.name').' receives its warnings and other weather information directly from the National Weather Service as soon as they are issued. Users are often alerted for Tornado Warnings several seconds before their local sirens are sounded.',
            ],
            [
                'item' => 'With a '.config('app.name').' account, you have the option to have a daily forecast sent to your e-mail address up to two times per day. Just click on the User menu entry and then go to Settings. From there, just click on Daily Forecast.',
            ],
            [
                'item' => 'You will only be notified for Tornado, Severe Thunderstorm, and Flash Flood warnings if your specified location lies within the warning\'s geographic "polygon". Wireless Emergency Alerts do not have this feature and instead, notify you for warnings affecting your entire county, which can lead to false-positives.',
            ],
            [
                'item' => 'A "Tornado Watch" is issued when conditions are favorable for the development of tornadoes in and close to the watch area, while a "Tornado Warning" is when is tornado has been indicated on radar or confirmed by the public or law enforcement.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/SVA.png />">"Severe Thunderstorm Watch"</a> is issued by the National Weather Service when conditions are favorable for the development of severe thunderstorms in and close to the watch area.',
            ],
            [
                'item' => '"Products" consist of bulletins such as short-fuse warnings (tornado, severe thunderstorm, and flash flood), watches, advisories, forecasts/outlooks, statements, and local storm reports.',
            ],
            [
                'item' => 'The Storm Prediction Center issues <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/DY1.png /> <img src=/dist/img/product_icons/DY2.png /> <img src=/dist/img/product_icons/DY3.png /> <img src=/dist/img/product_icons/D48.png />">"Convective Outlooks"</a> which outline the chances for severe weather for the next 7 days.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/HWO.png />">"Hazardous Weather Outlook"</a> is a product issued by some local National Weather Service offices that provides information regarding the potential for significant weather during the next 1 to 5 days.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/SWO.png />">"Mesoscale Discussion"</a> is a technical discussion issued by the Storm Prediction Center that describes current convective trends and is typically issued prior to a convective watch. Mesoscale discussions can also be issued to discuss ongoing or potential severe winter weather.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/PWO.png />">"Public Severe Weather Outlook"</a> is a product issued by the Storm Prediction Center when they are anticipating an especially significant and/or widespread outbreak of severe weather. This outlook will stress the seriousness of the situation, defines the threat area, and provides information on the timing of the outbreak.',
            ],
            [
                'item' => config('app.name').' sends out monthly test messages to ensure users are being properly notified. The texts are sent on the first Tuesday of every month at 10am Central. You may opt-out of monthly tests by going to the Settings page.',
            ],
            [
                'item' => 'Most National Weather Service offices offer "storm spotter" training classes a different times of the year. The classes teach the proper way to spot and report severe weather, including tornadoes, hail, wind damage, flooding, & severe winter weather. Check your local NWS office website for class dates and times.',
            ],
            [
                'item' => 'Tornadoes can and do occur any time of the year and have occurred in every state of the United States at one time or another. To ensure you are being notified for Tornado Warnings, make sure you have at least one enabled alert that notifies you for Tornado Warnings.',
            ],
            [
                'item' => 'The National Weather Service issues warnings for tornadoes, severe thunderstorms, and flash floods using geographic polygons, based on the projected path of a storm at the time of the warning\'s issuance as estimated by Doppler radar. The points that make up the polygon shape correspond to its latitude/longitude pairs.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/TOW.png />">"PDS Tornado Warning"</a> is issued when a large, destructive tornado known to be occuring. Those in the path should seek safe shelter immediately.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/TOW.png />">"Tornado Emergency"</a> is a rare type of Tornado Warning that is issued when a large, destructive tornado is moving toward or through a heavily populated area, such as a town or city. Those in the path should seek safe shelter immediately.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/SVW.png />">"Severe Thunderstorm Warning"</a> is issued when either a severe thunderstorm is indicated by radar or a spotter reports a thunderstorm producing hail 1 inch or larger in diameter, winds greater than or equal to 57 mph.',
            ],
            [
                'item' => 'A <a href="#" data-toggle="tooltip" data-placement="top" title="<img src=/dist/img/product_icons/FFW.png />">"Flash Flood Warning"</a> is issued to alert the public that a flash flood is immenent or occurring in the warned area.',
            ],
            [
                'item' => config('app.name').' now supports dynamic locations using Traccar. (traccar.org) If your location changes frequently, you may use Traccar to ensure that you receive warnings regarless of your current location. Refer to the documentation section for more information.',
            ],
            [
                'item' => 'Users now have the option of choosing light or dark mode in the dashboard! Just click or tap the user menu in the upper right-hand corner to access light or dark mode settings.',
            ],
            [
                'item' => 'Users may enable 2-factor authentication on their accounts for increased security. To do so, click on the user menu at the top right and click on Profile. From there, click on Enable in the Two Factor Authentication section.',
            ],
            [
                'item' => 'You may export all your settings to a single file and then import them later. You might do this if you wanted to take a back up before making significant changes to your settings and wanted to save time if you needed to roll everything back.',
            ],
            [
                'item' => 'You may integrate "Place Files" generated by '.config('app.name').' with radar programs such as the Gibson Ridge family of programs (e.g., GRLevel2 AE/GRLevel 3) or the open-source program Supercell Wx. Navigate to the Place Files page under the user menu in the upper right-hand corner of the dashboard.',
            ],
            [
                'item' => config('app.name').' users can now choose from '.ContactType::where('enabled', true)->count().' different types when creating new Contacts.',
            ],
            [
                'item' => config('app.name').' now has a new documentation section that covers all features and instructions for how to get the most out of the service. To access, click on the Documentation button in the lower-left of the dashboard.',
            ],
            [
                'item' => config('app.name').' has a new search feature that lets you quickly locate contacts, locations, schedules, and product lists. To use it, input your search tearm in the search box located at the top right menu bar of the dashboard.',
            ],
            [
                'item' => 'You may quickly view a map of all current products and radar for the entire US by clicking on the "Product Map" link in the left sidebar menu. The link takes you to an external page on the National Weather Service website.',
            ],
        ];
        // Insert data
        SeederUtils::insertRowsIntoTable('App\Models\DidYouKnowItem', 'item', $tableData);
    }
}
