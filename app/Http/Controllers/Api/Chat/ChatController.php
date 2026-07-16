<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Chat\StreamMessageRequest;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI chat endpoints. Streams the AI reply via SSE so the client can
 * render text as it's generated — never returns the full reply in a
 * single non-streamed JSON call.
 *
 *   POST /api/chat/messages  → text/event-stream
 *   GET  /api/chat/messages  → paginated JSON history
 */
class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService,
    ) {}

    public function stream(StreamMessageRequest $request): StreamedResponse|JsonResponse
    {
        try {
            $user = $request->user();
            $message = $request->input('message');

            return response()->stream(function () use ($user, $message) {
                try {
                    iterator_to_array($this->chatService->streamReply($user, $message));
                } catch (\Throwable $e) {
                    Log::error('Chat streaming error', [
                        'message' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);

                    if (! connection_aborted()) {
                        echo 'data: '.json_encode([
                            'error' => 'Failed to generate response',
                        ])."\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    public function history(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $history = $this->chatService->getHistory($user);

            return $this->sendSuccessResponse([
                'messages' => $history,
            ]);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }
}