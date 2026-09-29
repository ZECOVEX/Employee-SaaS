<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationController extends Controller
{
    /** The caller's in-app notifications (§25) — own rows only, no permission. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return NotificationResource::collection(
            $request->user()->notifications()->paginate(20),
        );
    }

    public function markRead(Request $request, string $id): JsonResource
    {
        $notification = $request->user()->notifications()
            ->where('id', $id)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return new NotificationResource($notification);
    }

    public function markAllRead(Request $request): array
    {
        $request->user()->unreadNotifications->markAsRead();

        return ['message' => 'All notifications marked read.'];
    }
}
