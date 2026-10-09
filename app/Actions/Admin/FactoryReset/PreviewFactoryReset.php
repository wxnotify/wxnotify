<?php

namespace App\Actions\Admin\FactoryReset;

use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PreviewFactoryReset
{
    /**
     * Read-only live counts for the toggles that are on.
     *
     * @param  array{pipeline?: bool, catalogs?: bool, subscribers?: bool}  $toggles
     * @return array<string, mixed>
     */
    public function __invoke(array $toggles): array
    {
        $preview = [];

        if ($toggles['pipeline'] ?? false) {
            $preview['pipeline'] = [
                'ingested_products' => DB::table('ingested_products')->count(),
                'ingested_product_segments' => DB::table('ingested_product_segments')->count(),
                'active_wwas' => DB::table('active_wwas')->count(),
                'delivery_logs' => DB::table('delivery_logs')->count(),
            ];
        }

        if ($toggles['catalogs'] ?? false) {
            $shippedCodes = array_column(require database_path('data/shipped_product_catalog.php'), 'code');
            $customProductIds = fn (Builder $query) => $query->select('id')->from('products')->whereNotIn('code', $shippedCodes);

            $preview['catalogs'] = [
                'custom_products' => DB::table('products')->whereNotIn('code', $shippedCodes)->count(),
                'alert_rules_affected' => DB::table('alert_rule_product')->whereIn('product_id', $customProductIds)->distinct()->count('alert_rule_id'),
                'custom_channel_types' => DB::table('channel_types')->whereNotIn('slug', array_column(ChannelTypeSeeder::SHIPPED, 'slug'))->count(),
            ];
        }

        $ids = fn (Builder $query) => $query->select('id')->from('users')->where('is_staff', false);

        if ($toggles['subscribers'] ?? false) {
            $preview['subscribers'] = [
                'users' => DB::table('users')->where('is_staff', false)->count(),
                'locations' => DB::table('locations')->whereIn('user_id', $ids)->count(),
                'alert_rules' => DB::table('alert_rules')->whereIn('user_id', $ids)->count(),
                'channels' => DB::table('notification_channels')->whereIn('user_id', $ids)->count(),
            ];
        }

        $preview['real_subscribers_exist'] = DB::table('users')->where('is_staff', false)->where('is_test_account', false)->exists();

        return $preview;
    }
}
