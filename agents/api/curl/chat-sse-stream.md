# Chat SSE Stream — internal contract

This is our own SSE endpoint (`POST /api/chat/messages`) re-described
from the **client** perspective. See also
[`api/chat.md`](../chat.md).

## Response headers

| header | value |
|---|---|
| Content-Type | `text/event-stream` |
| Cache-Control | `no-cache, no-transform` |
| X-Accel-Buffering | `no` |
| Connection | `keep-alive` |

## Event stream

Each event is a single line of the form `data: <json>\n\n` where
`<json>` is one of:

```json
{ "chunk": "Hello" }
{ "chunk": " there" }
{ "done": true, "message_id": 42 }
{ "error": "Failed to generate response" }
```

`chunk` events arrive continuously while OpenRouter streams. The
client should concatenate them to render the assistant reply in
real time. `done` arrives exactly once at the end and includes the
persisted `message_id` for the completed assistant message. `error`
arrives at most once and signals the stream should be closed.

## Sample client (Dart)

```dart
final response = await dio.post(
  '/api/chat/messages',
  data: {'message': text},
  options: Options(
    responseType: ResponseType.stream,
    headers: {'Accept': 'text/event-stream'},
    receiveTimeout: const Duration(minutes: 5),
  ),
);

final stream = response.data.stream
    .transform(utf8.decoder)
    .transform(const LineSplitter())
    .where((line) => line.startsWith('data:'))
    .map((line) => jsonDecode(line.substring(5).trim()));

await for (final evt in stream) {
  if (evt['chunk'] != null) notifier.appendChunk(evt['chunk'] as String);
  if (evt['done'] == true) notifier.finalize(evt['message_id'] as int);
  if (evt['error'] != null) notifier.error(evt['error'] as String);
}
```