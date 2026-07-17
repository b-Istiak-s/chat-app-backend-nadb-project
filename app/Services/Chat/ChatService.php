<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\SmsService;
use Generator;

/**
 * AI chat orchestration. Owns:
 *   - finding-or-creating the user's conversation
 *   - assembling the OpenAI-style messages[] from history
 *   - persisting user + assistant messages
 *   - exposing `streamReply()` as a Generator that yields
 *     already-formatted SSE chunks to be echoed to the client.
 */
class ChatService
{
    private const SYSTEM_PROMPT = <<<'TEXT'
You are a helpful, friendly AI assistant in a chat app used by people in Bangladesh.
Keep replies concise, conversational, and respectful. Reply in the same language the
user writes in (Bangla or English).
TEXT;

    public function __construct(
        private OpenRouterService $openRouter,
        private SmsService $smsService,
    ) {}

    public function getOrCreateConversation(User $user): ChatConversation
    {
        return ChatConversation::firstOrCreate(
            ['user_id' => $user->id],
            ['title' => null],
        );
    }

    public function getHistory(User $user, int $limit = 50): array
    {
        $conversation = $this->getOrCreateConversation($user);

        // `ChatConversation::messages()` already has `orderBy('id')` on
        // the relation. If we just chain `->orderByDesc('id')` on top,
        // Eloquent emits BOTH order-by clauses and MySQL sorts by the
        // FIRST one (asc). `reorder()` drops the inherited ordering
        // so our `desc` is the only sort key.
        return $conversation->messages()
            ->reorder()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Yields SSE-ready strings:
     *   - "data: {\"chunk\":\"<text>\"}\n\n"  for each delta chunk
     *   - "data: {\"done\":true,\"message_id\":<id>}\n\n"  at the end
     *
     * Persists the user message before streaming and the assistant
     * message after the stream completes.
     */
    public function streamReply(User $user, string $userMessage): Generator
    {
        $conversation = $this->getOrCreateConversation($user);

        // 1. Persist user message.
        $userMsg = ChatMessage::create([
            'user_id' => $user->id,
            'chat_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        // 2. Assemble OpenAI-style messages array from history.
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($conversation->messages()->orderBy('id')->get() as $m) {
            $messages[] = ['role' => $m->role, 'content' => $m->content];
        }

        // 3. Stream from OpenRouter, accumulating + yielding.
        $accumulated = '';
        $fullResponse = $this->openRouter->chat(
            $messages,
            function (string $chunk) use (&$accumulated) {
                $accumulated .= $chunk;
                echo 'data: '.json_encode(['chunk' => $chunk], JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if (connection_aborted()) {
                    throw new \RuntimeException('Client disconnected');
                }
            }
        );

        // 4. Persist the assistant reply.
        $assistant = ChatMessage::create([
            'user_id' => $user->id,
            'chat_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $fullResponse,
        ]);

        // 4b. Fire a milestone SMS if this turn just crossed a
        // multiple of SmsService::STEP. Counting assistant messages
        // (rather than user messages) keeps the cadence tied to
        // completed AI responses — a turn that aborts mid-stream
        // doesn't burn a milestone.
        $assistantTurns = $conversation->messages()
            ->where('role', 'assistant')
            ->count();
        $this->smsService->maybeNotifyMilestone($user, (int) $assistantTurns);

        // 5. Yield done event.
        echo 'data: '.json_encode([
            'done' => true,
            'message_id' => $assistant->id,
        ], JSON_UNESCAPED_UNICODE)."\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
