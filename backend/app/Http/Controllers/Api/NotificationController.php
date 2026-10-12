<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    #[OA\Get(
        path: '/notifications',
        operationId: 'notificationsIndex',
        tags: ['Notifications'],
        summary: 'List notifications',
        description: 'The signed-in user\'s notifications, newest first.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated notifications.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Notification')),
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'total', type: 'integer'),
                    ],
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
    public function index(Request $request)
    {
        // created_at is stored with a one second precision, so several
        // notifications raised by the same action (accepting a reservation
        // writes two) would tie. The id keeps the order stable and newest
        // first.
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 20));

        return response()->json($notifications);
    }

    #[OA\Get(
        path: '/notifications/unread/count',
        operationId: 'notificationsUnreadCount',
        tags: ['Notifications'],
        summary: 'Unread notification count',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The unread count.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'unread_count', type: 'integer', example: 2)]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    #[OA\Put(
        path: '/notifications/{id}/read',
        operationId: 'notificationsMarkAsRead',
        tags: ['Notifications'],
        summary: 'Mark a notification as read',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notification marked as read.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)->find($id);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    #[OA\Put(
        path: '/notifications/read-all',
        operationId: 'notificationsMarkAllAsRead',
        tags: ['Notifications'],
        summary: 'Mark all notifications as read',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'All notifications marked as read.', content: new OA\JsonContent(ref: '#/components/schemas/MessageResponse')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
        ],
    )]
    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
