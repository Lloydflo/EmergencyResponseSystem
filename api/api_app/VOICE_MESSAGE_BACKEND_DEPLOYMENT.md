# Coordination voice-message backend deployment

This API package supports audible PCM WAV coordination voice notes for private responder chats and department/inter-agency group channels.

## Files required for the audio fix

Replace these files in production `api/api_app/`:

- `upload_chat_file.php`
- `chat-file.php`
- `send-interagency-group-attachment.php`
- `get-interagency-group-messages.php`

Deploy the matching Android build only after these files are live.

## Upload directory

The upload endpoint writes to:

```text
<document-root>/uploads/chat/
```

Recommended setup, adjusted for the actual PHP and web-server accounts:

```bash
mkdir -p /path/to/public_html/uploads/chat
chown PHP_USER:WEB_GROUP /path/to/public_html/uploads/chat
chmod 0750 /path/to/public_html/uploads/chat
```

Do not grant world-write permission (`0777`). Uploaded files receive generated non-guessable names and read permission for the HTTP worker.

## Server environment

```env
ERS_PUBLIC_BASE_URL=https://emergency-response.alertaraqc.com
ERS_API_APP_PUBLIC_PATH=/api/api_app
```

The upload response uses the streaming URL:

```text
https://emergency-response.alertaraqc.com/api/api_app/chat-file.php?file=<generated-file>.wav
```

A legacy direct static URL is also returned as `static_file_url`, but the Android app uses `file_url` because the PHP stream endpoint supplies deterministic MIME and byte-range headers.

## PHP/reverse-proxy limits

```ini
upload_max_filesize = 25M
post_max_size = 27M
max_file_uploads = 10
```

The application endpoint enforces 25 MB. Configure an adequate reverse-proxy body limit as well.

## Supported audio formats

The app records `audio/wav`. The endpoint also accepts:

```text
m4a, aac, 3gp, ogg, opus, wav, mp3
```

Image and document attachment types remain supported.

## Department-message persistence

No schema migration is required. The existing attachment row stores URL/MIME/size, and `message_details` stores voice metadata:

```json
{
  "attachments": [
    {
      "is_audio": 1,
      "duration_ms": 6400,
      "mime_type": "audio/wav"
    }
  ]
}
```

The read endpoint returns `type: AUDIO`, `attachmentMimeType: audio/wav`, and `audioDurationMs`.

## Smoke test

Use a valid WAV file and an active responder ID:

```bash
curl -i \
  -F 'uploader_user_id=42' \
  -F 'file=@sample.wav;type=audio/wav' \
  'https://emergency-response.alertaraqc.com/api/api_app/upload_chat_file.php'
```

Copy the returned `file_url`, then check headers and content:

```bash
curl -I 'RETURNED_FILE_URL'
curl -L --fail --output downloaded.wav 'RETURNED_FILE_URL'
file downloaded.wav
```

Expected headers include:

```text
Content-Type: audio/wav
Accept-Ranges: bytes
```

Then send the returned URL to a department channel:

```bash
curl -i -X POST \
  --data-urlencode 'group_id=6' \
  --data-urlencode 'sender_user_id=42' \
  --data-urlencode 'file_url=RETURNED_FILE_URL' \
  --data-urlencode 'file_name=voice_test.wav' \
  --data-urlencode 'mime_type=audio/wav' \
  --data-urlencode 'file_size=204844' \
  --data-urlencode 'is_image=0' \
  --data-urlencode 'is_audio=1' \
  --data-urlencode 'audio_duration_ms=6400' \
  'https://emergency-response.alertaraqc.com/api/api_app/send-interagency-group-attachment.php'
```

## Firebase rules

Private-message metadata is written to `messages` and thread previews to `threads`. The rules supplied by the project already permit these writes. The audio bytes do not pass through Firebase. No `audio` top-level node is needed.

The current rules are open to the public and should be treated as a temporary compatibility configuration. Do not switch to `auth != null` until the PHP login flow issues Firebase custom tokens and the Android app signs in to Firebase Authentication.
