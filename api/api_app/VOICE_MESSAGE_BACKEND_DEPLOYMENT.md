# Coordination voice-message backend deployment

This API package supports `.m4a` coordination voice notes for private responder chats and department/inter-agency group channels.

It also contains the reports-only API changes documented in `SERVICE_REVIEW_REMOVAL.md`.

## Files to deploy

Replace these files in the production `api/api_app/` directory:

- `upload_chat_file.php`
- `send-interagency-group-attachment.php`
- `get-interagency-group-messages.php`

Deploy the rest of this API package as well when applying the reports-only changes.

## Upload directory

The upload endpoint writes to:

```text
<document-root>/uploads/chat/
```

The directory is created with mode `0755` when possible. Uploaded files receive generated, non-guessable names and mode `0644` so the HTTP worker can stream them even when PHP-FPM uses another service account.

Recommended production setup:

```bash
mkdir -p /path/to/public_html/uploads/chat
chown PHP_USER:WEB_GROUP /path/to/public_html/uploads/chat
chmod 0750 /path/to/public_html/uploads/chat
```

Use permissions appropriate to the actual PHP/web-server accounts. Do not grant world write access (`0777`).

## Public URL

Set this server environment variable to the application origin, without `/api`:

```env
ERS_PUBLIC_BASE_URL=https://emergency-response.alertaraqc.com
```

The response URL will be:

```text
https://emergency-response.alertaraqc.com/uploads/chat/<generated-file>.m4a
```

## PHP limits

The endpoint enforces a 25 MB application limit. PHP and the reverse proxy must allow at least that amount:

```ini
upload_max_filesize = 25M
post_max_size = 27M
max_file_uploads = 10
```

For Nginx, also configure an adequate `client_max_body_size`. Reload PHP-FPM and the web server after configuration changes.

## Supported audio formats

The voice recorder uploads AAC audio in an `.m4a` file. The upload endpoint also accepts common audio attachments with these extensions:

```text
m4a, aac, 3gp, ogg, opus, wav, mp3
```

Image and document types previously supported by the endpoint remain supported.

## Department-message persistence

No schema migration is required. The existing attachment row stores the file details, while the existing `message_details` JSON stores:

```json
{
  "attachments": [
    {
      "is_audio": 1,
      "duration_ms": 64320
    }
  ]
}
```

`get-interagency-group-messages.php` merges the attachment table and JSON metadata and returns:

```json
{
  "type": "AUDIO",
  "attachmentMimeType": "audio/mp4",
  "attachmentSize": 1450021,
  "audioDurationMs": 64320
}
```

## Push notifications

`send-interagency-group-attachment.php` sends:

```text
message_type=audio
body=Sent a voice message
```

Private messages use the existing `notify-private-message.php` endpoint with the same message type/body.

## Security note

The endpoint preserves the current API trust model: it verifies that the supplied uploader ID belongs to an active responder, and department sending also verifies group membership. A supplied numeric user ID is not a strong bearer credential. Before exposing these endpoints outside the trusted responder app, add authenticated sessions or signed access tokens and rate limits at the API gateway.

Serve voice files only over HTTPS. Apply retention/deletion rules and avoid logging attachment URLs together with sensitive incident data.

## Smoke tests

A real multipart upload must use an active responder ID:

```bash
curl -i \
  -F 'uploader_user_id=42' \
  -F 'file=@sample.m4a;type=audio/mp4' \
  'https://emergency-response.alertaraqc.com/api/api_app/upload_chat_file.php'
```

Then send the returned URL to a department group:

```bash
curl -i -X POST \
  --data-urlencode 'group_id=6' \
  --data-urlencode 'sender_user_id=42' \
  --data-urlencode 'file_url=https://emergency-response.alertaraqc.com/uploads/chat/GENERATED.m4a' \
  --data-urlencode 'file_name=voice_test.m4a' \
  --data-urlencode 'mime_type=audio/mp4' \
  --data-urlencode 'file_size=123456' \
  --data-urlencode 'is_image=0' \
  --data-urlencode 'is_audio=1' \
  --data-urlencode 'audio_duration_ms=12000' \
  'https://emergency-response.alertaraqc.com/api/api_app/send-interagency-group-attachment.php'
```

Read it back:

```bash
curl -i \
  'https://emergency-response.alertaraqc.com/api/api_app/get-interagency-group-messages.php?group_id=6&user_id=42&limit=20'
```

Expected message fields include `type: AUDIO` and `audioDurationMs: 12000`.
