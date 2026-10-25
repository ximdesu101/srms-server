<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admin  = $request->user('admin');
        $filter = $request->query('filter', 'all'); // all | unread | read

        $query = Notification::query()
            ->where('notifiable_type', get_class($admin))
            ->where('notifiable_id', $admin->id)
            ->orderByDesc('created_at');

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->paginate(20);

        $notifications->getCollection()->transform(function (Notification $n) {
            return $this->format($n);
        });

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $admin = $request->user('admin');

        $count = Notification::query()
            ->where('notifiable_type', get_class($admin))
            ->where('notifiable_id', $admin->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $admin = $request->user('admin');

        if ($notification->notifiable_type !== get_class($admin) || (int) $notification->notifiable_id !== (int) $admin->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'message'      => 'Notification marked as read.',
            'notification' => $this->format($notification),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $admin = $request->user('admin');

        Notification::query()
            ->where('notifiable_type', get_class($admin))
            ->where('notifiable_id', $admin->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    private function format(Notification $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->type,
            'title'      => $n->title,
            'message'    => $n->message,
            'data'       => $n->data,
            'read_at'    => $n->read_at?->toIso8601String(),
            'is_unread'  => $n->isUnread(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
