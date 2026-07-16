# Chat API

## POST /api/chat/messages

Send a user message and **stream** the AI reply via SSE. The endpoint
returns `Content-Type: text/event-stream` and yields multiple
`data: <json>` events until the response is complete.

SSE event shapes:

| event | payload | when |
|---|---|---|
| `data: {"chunk":"<text>"}` | one text delta | per OpenRouter stream chunk |
| `data: {"done":true,"message_id":<id>}` | completion + persisted id | once at end |
| `data: {"error":"<msg>"}` | error description | on failure |

The user message is persisted **before** streaming starts; the
assistant message is persisted **after** the stream completes.

| field | type | required | description |
|---|---|---|---|
| message | string | yes | User text, 1–8000 chars |

###### POST /api/chat/messages

```curl
curl \
  -X POST \
  "$APP_URL/api/chat/messages" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: text/event-stream" \
  -d '{
    "message": "Hello!"
  }'
```

Sample streamed body:

```
data: {"chunk":"Hi"}
data: {"chunk":" there"}
data: {"chunk":"!"}
data: {"done":true,"message_id":42}
```

---

## GET /api/chat/messages

Return paginated chat history (most recent 50 by default), ordered
oldest first.

###### GET /api/chat/messages

```curl
curl \
  -X GET \
  "$APP_URL/api/chat/messages" \
  -H "Authorization: Bearer $TOKEN"
```

Sample response:

```json
{
  "success": true,
  "message": "Response Successful",
  "data": {
    "messages": [
      {
        "id": 41,
        "role": "user",
        "content": "Hello!",
        "created_at": "2026-07-16T12:00:00+00:00"
      },
      {
        "id": 42,
        "role": "assistant",
        "content": "Hi there!",
        "created_at": "2026-07-16T12:00:02+00:00"
      }
    ]
  }
}
```
