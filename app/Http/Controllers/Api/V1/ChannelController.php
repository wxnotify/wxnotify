<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ChannelResource;
use App\Http\Resources\Api\V1\ChannelTypeResource;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChannelController extends Controller
{
    public function types(Request $request, ChannelTypeRegistry $registry): AnonymousResourceCollection
    {
        $user = $request->user();

        return ChannelTypeResource::collection(
            $registry->creatableInApp($user)->map(fn (ChannelType $type): array => ['type' => $type, 'user' => $user]),
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ChannelResource::collection($request->user()->notificationChannels()->orderBy('id')->get());
    }
}
