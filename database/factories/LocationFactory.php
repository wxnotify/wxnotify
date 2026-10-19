<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use App\Models\TraccarServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->streetName(),
            'type' => LocationType::Stationary,
            'address' => fake()->address(),
            'lat' => fake()->latitude(24, 49),
            'lng' => fake()->longitude(-125, -67),
        ];
    }

    public function mobile(): static
    {
        return $this->state(function (array $attributes) {
            // The base definition()'s 'user_id' is normally an unresolved
            // User::factory() instance, not a concrete id -- resolving it
            // twice (once for this Location, once for the TraccarServer
            // below) would silently create two different Users. Resolve it
            // once here so both rows end up owned by the same user.
            $userId = $attributes['user_id'] ?? null;

            if ($userId instanceof Factory) {
                $userId = $userId->create()->id;
            } elseif ($userId === null) {
                $userId = User::factory()->create()->id;
            }

            return [
                'user_id' => $userId,
                'type' => LocationType::Mobile,
                'address' => null,
                'lat' => null,
                'lng' => null,
                'mobile_source' => MobileSource::Traccar,
                'mobile_update_interval_minutes' => 15,
                'traccar_server_id' => TraccarServer::factory()->state(['user_id' => $userId]),
                'traccar_device_id' => fake()->randomNumber(5),
                'traccar_device_name' => fake()->word(),
            ];
        });
    }

    /**
     * A mobile location kept current by a phone running the app (no Traccar link). Pass the
     * phone's app token to link it; leave it null for a location whose phone is gone.
     */
    public function mobileApp(?PersonalAccessToken $token = null): static
    {
        return $this->afterMaking(function (Location $location) use ($token): void {
            // The model refuses a new phone location with no link, so an unlinked one is created
            // with a throwaway token that is deleted straight after (the column is nulled on delete).
            if ($token === null) {
                $location->app_token_id = $location->user->createToken('placeholder', ['app'])->accessToken->id;
            }
        })->afterCreating(function (Location $location) use ($token): void {
            if ($token === null) {
                PersonalAccessToken::query()->whereKey($location->app_token_id)->delete();
                $location->refresh();
            }
        })->state(fn () => [
            'type' => LocationType::Mobile,
            'address' => null,
            'lat' => null,
            'lng' => null,
            'mobile_source' => MobileSource::App,
            'mobile_update_interval_minutes' => 5,
            'traccar_server_id' => null,
            'traccar_device_id' => null,
            'traccar_device_name' => null,
            'app_token_id' => $token?->id,
        ]);
    }

    public function withFix(float $lat, float $lng): static
    {
        return $this->state(fn () => [
            'lat' => $lat,
            'lng' => $lng,
            'mobile_position_updated_at' => now(),
        ]);
    }
}
