# OpenRouter — POST /chat/completions (stream=true)

Source: `vendor/openai-php/client` (which speaks OpenAI-compatible,
including OpenRouter).

Used by `OpenRouterService` to stream AI chat completions. The
service wraps the call so callers see a `chat(messages, onChunk)`
PHP API; under the hood we POST to OpenRouter with `stream: true`
and iterate the SSE response.

## Headers

| header | value |
|---|---|
| Authorization | `Bearer $OPENROUTER_API_KEY` |
| Content-Type | `application/json` |
| Accept | `text/event-stream` |
| X-Title | `Chat App` (configurable via `OPENROUTER_APP_NAME`) |
| HTTP-Referer | `https://your-host.com` (configurable via `OPENROUTER_APP_URL`) |

## Body

| field | type | required | description |
|---|---|---|---|
| model | string | yes | E.g. `openai/gpt-4o-mini`, `anthropic/claude-3.5-sonnet` |
| messages | array | yes | OpenAI-style `[{role, content}, ...]` |
| stream | bool | yes | `true` for streaming |

Each message:

| field | type | description |
|---|---|---|
| role | string | `system` \| `user` \| `assistant` |
| content | string | Message text |

## Sample request

```curl
curl \
  -X POST \
  "https://openrouter.ai/api/v1/chat/completions" \
  -H "Authorization: Bearer $OPENROUTER_API_KEY" \
  -H "Content-Type: application/json" \
  -H "X-Title: Chat App" \
  -H "HTTP-Referer: $APP_URL" \
  -d '{
    "model": "openai/gpt-4o-mini",
    "stream": true,
    "messages": [
      { "role": "system", "content": "You are a helpful assistant." },
      { "role": "user",   "content": "Hello!" }
    ]
  }'
```

## Sample streamed response

```
data: {"id":"chatcmpl-...","object":"chat.completion.chunk","choices":[{"delta":{"content":"Hi"},"index":0}]}

data: {"id":"chatcmpl-...","object":"chat.completion.chunk","choices":[{"delta":{"content":" there"},"index":0}]}

data: [DONE]
```

Each `data:` line is a JSON chunk whose `choices[0].delta.content` is
the next text delta. The stream ends with `data: [DONE]`.

## Implementation

- Service: `app/Services/OpenRouterService.php`.
- The service hides the streaming details from callers; consumers just
  pass `[$messages, $onChunk]` and get back the concatenated reply.
- Errors thrown by OpenRouter are re-thrown as
  `RuntimeException('OpenRouter request failed: ...')` so the chat
  controller can convert them to an SSE error event.