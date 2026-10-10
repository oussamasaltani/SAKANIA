<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Direct communication between a tenant and the owner of a property.
 *
 * A conversation is opened by the tenant's first message and is then reused by
 * every later message of that pair, so the whole thread stays in one place and
 * keeps its property and (when there is one) its reservation context.
 *
 * Messaging is deliberately kept apart from the notification system: a message
 * never touches a reservation, a payment or a contract status.
 */
class MessageController extends Controller
{
    /**
     * The conversations the authenticated user takes part in, most recent first.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $conversations = Conversation::forUser($user)
            ->with(['property', 'reservation', 'tenant', 'owner'])
            ->orderByDesc(DB::raw('COALESCE(last_message_at, created_at)'))
            ->orderByDesc('id')
            ->get();

        $unreadCounts = $this->unreadCounts($user->id);

        $data = $conversations->map(function (Conversation $conversation) use ($user, $unreadCounts) {
            $lastMessage = $conversation->messages()->orderByDesc('sent_at')->orderByDesc('id')->first();

            return $this->presentConversation($conversation, $user, $unreadCounts, $lastMessage);
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * A single conversation with its messages, oldest first. Opening it marks
     * the messages the user received as read.
     */
    public function show(Request $request, $conversationId)
    {
        $user = $request->user();

        $conversation = $this->findConversationForUser($conversationId, $user);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $conversation->load(['property', 'reservation', 'tenant', 'owner']);

        // Opening the conversation is what marks it read. Only the messages
        // addressed to the reader are touched, never the ones they sent.
        $conversation->messages()
            ->where('receiver_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        $messages = $conversation->messages()
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            // The thread has just been read, so it carries no unread count.
            'conversation' => $this->presentConversation($conversation, $user, [], $messages->last()),
            'messages' => $messages->map(fn (Message $message) => $this->presentMessage($message))->values(),
        ]);
    }

    /**
     * Sends a message, opening the conversation on the very first one.
     *
     * Either a conversation_id is given (a reply inside an existing thread) or
     * a receiver_id plus the property the tenant is asking about (the first
     * message of a new thread).
     */
    public function send(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'conversation_id' => 'sometimes|nullable|integer|exists:conversations,id',
            'receiver_id' => 'required_without:conversation_id|nullable|integer|exists:users,id',
            'property_id' => 'required_without:conversation_id|nullable|integer|exists:properties,id',
            'reservation_id' => 'sometimes|nullable|integer|exists:reservations,id',
            'content' => 'required|string|max:5000',
        ]);

        $content = trim($validated['content']);

        if ($content === '') {
            return response()->json(['message' => 'The message cannot be empty.'], 422);
        }

        if (!empty($validated['conversation_id'])) {
            // A reply inside an existing thread: the counterpart is whoever is
            // on the other side of it.
            $conversation = $this->findConversationForUser($validated['conversation_id'], $user);

            if (!$conversation) {
                return response()->json(['message' => 'Conversation not found.'], 404);
            }

            $receiverId = $user->id === $conversation->tenant_id
                ? $conversation->owner_id
                : $conversation->tenant_id;
        } else {
            // The first message of a thread opens the conversation.
            $opened = $this->openConversation($user, $validated);

            if (isset($opened['error'])) {
                return response()->json(['message' => $opened['error']], $opened['code']);
            }

            $conversation = $opened['conversation'];
            $receiverId = $conversation->owner_id;
        }

        if ($receiverId === $user->id) {
            return response()->json(['message' => 'You cannot send a message to yourself.'], 422);
        }

        $receiver = User::find($receiverId);

        if (!$receiver || !$receiver->is_active) {
            return response()->json(['message' => 'This user cannot receive messages.'], 422);
        }

        $message = DB::transaction(function () use ($conversation, $user, $receiverId, $content) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'receiver_id' => $receiverId,
                'property_id' => $conversation->property_id,
                'content' => $content,
                'is_read' => false,
                'sent_at' => now(),
            ]);

            // Keeps the conversation list ordered by recent activity.
            $conversation->forceFill(['last_message_at' => $message->sent_at])->save();

            return $message;
        });

        return response()->json([
            'message' => $this->presentMessage($message->load('conversation')),
            'conversation_id' => $message->conversation_id,
        ], 201);
    }

    /**
     * Total number of messages the authenticated user has not read yet.
     */
    public function unreadCount(Request $request)
    {
        $count = Message::where('receiver_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    /**
     * Marks every message received in a conversation as read.
     */
    public function markAsRead(Request $request, $conversationId)
    {
        $user = $request->user();

        $conversation = $this->findConversationForUser($conversationId, $user);

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $conversation->messages()
            ->where('receiver_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'Conversation marked as read.']);
    }

    /**
     * Finds a conversation, but only when the user is one of its two parties.
     * Anything else is reported as "not found" so the endpoint never confirms
     * that a thread somebody else owns exists.
     */
    private function findConversationForUser($conversationId, User $user): ?Conversation
    {
        return Conversation::forUser($user)->find($conversationId);
    }

    /**
     * Resolves the conversation a first message belongs to, creating it when
     * the tenant writes about a property for the first time.
     *
     * Returns ['conversation' => Conversation] or ['error' => ..., 'code' => ...].
     */
    private function openConversation(User $user, array $validated): array
    {
        $property = Property::find($validated['property_id']);

        if (!$property) {
            return ['error' => 'Property not found.', 'code' => 404];
        }

        // Only the owner of the property can be written to, and only by a
        // tenant: an owner replies inside a thread, it never opens one.
        if ($user->role_id === 2) {
            return ['error' => 'Owners reply to a conversation, they do not start one.', 'code' => 403];
        }

        if ($user->role_id !== 1) {
            return ['error' => 'Only tenants can start a conversation with a property owner.', 'code' => 403];
        }

        $ownerId = $property->owner_id;

        if ($ownerId === $user->id) {
            return ['error' => 'You cannot send a message about your own property.', 'code' => 403];
        }

        if (!empty($validated['receiver_id']) && (int) $validated['receiver_id'] !== (int) $ownerId) {
            return ['error' => 'You can only message the owner of this property.', 'code' => 403];
        }

        $reservation = null;

        if (!empty($validated['reservation_id'])) {
            $reservation = Reservation::where('id', $validated['reservation_id'])
                ->where('tenant_id', $user->id)
                ->where('property_id', $property->id)
                ->first();

            if (!$reservation) {
                return ['error' => 'Reservation not found.', 'code' => 404];
            }
        }

        $conversation = Conversation::firstOrCreate(
            [
                'tenant_id' => $user->id,
                'owner_id' => $ownerId,
                'property_id' => $property->id,
            ],
            ['reservation_id' => $reservation?->id]
        );

        // A reservation created after the thread was opened can still be
        // attached to it, as long as it is a booking of this tenant on this
        // property and the thread is not already tied to another one.
        if ($reservation && !$conversation->reservation_id) {
            $conversation->forceFill(['reservation_id' => $reservation->id])->save();
        }

        return ['conversation' => $conversation];
    }

    /**
     * Unread messages per conversation, in a single query.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function unreadCounts(int $userId)
    {
        return Message::where('receiver_id', $userId)
            ->where('is_read', false)
            ->whereNotNull('conversation_id')
            ->selectRaw('conversation_id, COUNT(*) as aggregate')
            ->groupBy('conversation_id')
            ->pluck('aggregate', 'conversation_id');
    }

    private function presentConversation(Conversation $conversation, User $user, $unreadCounts, ?Message $lastMessage): array
    {
        $counterpart = $conversation->counterpartFor($user);

        return [
            'id' => $conversation->id,
            'property' => $conversation->property ? [
                'id' => $conversation->property->id,
                'title' => $conversation->property->title,
                'location' => $conversation->property->location,
            ] : null,
            'reservation' => $conversation->reservation ? [
                'id' => $conversation->reservation->id,
                'reference' => $conversation->reservation->reference,
                'status' => $conversation->reservation->status,
            ] : null,
            'counterpart' => $counterpart ? [
                'id' => $counterpart->id,
                'full_name' => $counterpart->full_name,
                'avatar' => $counterpart->avatar,
                'role' => $counterpart->role_id === 2 ? 'owner' : 'tenant',
            ] : null,
            'last_message' => $lastMessage ? $this->presentMessage($lastMessage) : null,
            'unread_count' => (int) ($unreadCounts[$conversation->id] ?? 0),
            'last_message_at' => ($lastMessage?->sent_at ?? $conversation->last_message_at)?->toIso8601String(),
        ];
    }

    private function presentMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'receiver_id' => $message->receiver_id,
            'content' => $message->content,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'is_read' => (bool) $message->is_read,
            'read_at' => $message->read_at?->toIso8601String(),
        ];
    }
}