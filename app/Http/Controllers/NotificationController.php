<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /**
     * In-app notification center (§25) — strictly the signed-in user's own
     * rows; there is nothing tenant-scoped to leak because the notifiable
     * morph is always the authenticated user.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $user->notifications()->latest()->paginate(15),
            'unreadCount' => $user->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $notification = $this->find($request, $id);
        $notification->markAsRead();

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()
            ->notifications()
            ->whereNull('read_at')
            ->get()
            ->each(fn (DatabaseNotification $notification) => $notification->markAsRead());

        return back()->with('status', 'All notifications marked as read.');
    }

    /**
     * Another user's notification id is a 404, never a 403 (no existence
     * oracle across accounts).
     */
    private function find(Request $request, string $id): DatabaseNotification
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($id)
            ->first();

        if (! $notification) {
            abort(404);
        }

        return $notification;
    }
}
