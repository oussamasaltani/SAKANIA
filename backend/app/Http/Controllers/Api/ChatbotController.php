<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\Property;
use App\Models\User;
use App\Services\Chatbot\ChatbotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatbotController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbot) {}

    /**
     * The SAKANIA assistant.
     *
     * The controller stays deliberately thin: it owns the conversation record,
     * the validation and the response shape. Everything about understanding the
     * question and reading the database lives in ChatbotService, which is the
     * only component allowed to query SAKANIA data on a user's behalf.
     *
     * Response keys: `reply` is the assistant's answer, `conversation_id` keeps
     * the thread, `intent` is exposed for the client's own telemetry and is never
     * required to display the answer.
     */
    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'sometimes|nullable|exists:chatbot_conversations,id',
            'property_id' => 'sometimes|nullable|exists:properties,id',
        ]);

        $user = $request->user();

        try {
            $conversation = $this->resolveConversation($user ? $user->id : null, $validated['conversation_id'] ?? null);

            ChatbotMessage::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'user',
                'message' => $validated['message'],
            ]);

            $history = $this->recentHistory($conversation->id);

            $viewedProperty = $user ? $this->viewedProperty($user, $validated['property_id'] ?? null) : null;

            $result = $this->chatbot->reply($validated['message'], $user, $history, $viewedProperty);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'reply' => 'Something went wrong on my side while handling your request. '
                    .'Please try again, or contact SAKANIA Support.',
                'message' => 'Failed to process chatbot message.',
                'conversation_id' => $validated['conversation_id'] ?? null,
            ], 500);
        }

        try {
            ChatbotMessage::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'bot',
                'message' => $result['reply'],
            ]);
        } catch (Throwable $e) {
            // The answer is still valid even if the transcript could not be stored.
            Log::warning('Chatbot: could not store the assistant message.', [
                'conversation_id' => $conversation->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'reply' => $result['reply'],
            'conversation_id' => $conversation->id,
            'intent' => $result['intent'],
        ]);
    }

    /**
     * The conversation belongs to the caller: an id that belongs to somebody else
     * is treated as no conversation at all, so a thread can never be read or
     * continued across accounts.
     */
    private function resolveConversation(?int $userId, ?int $conversationId): ChatbotConversation
    {
        if ($conversationId) {
            $query = ChatbotConversation::query();
            if ($userId) {
                $query->where(function ($q) use ($userId, $conversationId) {
                    $q->where('user_id', $userId)->orWhere(function ($qq) {
                        $qq->whereNull('user_id');
                    });
                });
            }
            $existing = $query->find($conversationId);
            if ($existing) {
                if ($userId && $existing->user_id === null) {
                    $existing->user_id = $userId;
                    $existing->save();
                }
                return $existing;
            }
        }

        return ChatbotConversation::create([
            'user_id' => $userId,
            'started_at' => now(),
        ]);
    }

    /**
     * The last turns of the thread, oldest first, so the assistant keeps track of
     * what was already discussed. Only this conversation's messages are read.
     *
     * @return array<int, array{role: string, text: string}>
     */
    private function recentHistory(int $conversationId, int $limit = 10): array
    {
        return ChatbotMessage::where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn (ChatbotMessage $message) => [
                'role' => $message->sender_type === 'bot' ? 'model' : 'user',
                'text' => (string) $message->message,
            ])
            ->all();
    }

    /**
     * The property the user is currently looking at, if they may see it.
     *
     * A tenant only gets an approved property. An owner also gets their own
     * property whatever its moderation status, which is what lets the assistant
     * answer questions about a property that is still pending approval.
     */
    private function viewedProperty(User $user, ?int $propertyId): ?Property
    {
        if (! $propertyId) {
            return null;
        }

        $property = Property::find($propertyId);

        if (! $property) {
            return null;
        }

        if ($property->status === 'approved') {
            return $property;
        }

        return $property->owner_id === $user->id ? $property : null;
    }
}
