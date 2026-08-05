# Coordination voice messages

This source package adds recorded voice messages to both Coordination chat modes:

- private responder-to-responder conversations (Firebase Realtime Database);
- department/inter-agency group channels (PHP/MySQL API).

It also retains the reports-only change in `SERVICE_REVIEW_REMOVAL.md`: service reviews and star ratings are removed, while Create Report and the operational report workflow remain.

## Responder experience

1. Open a private responder chat or a department channel.
2. Leave the text field empty and tap the microphone button.
3. Grant the Android microphone permission when prompted.
4. Tap the green send button to finish and upload, or tap the trash button to discard.
5. The recording is sent automatically at the two-minute limit.
6. Recipients can play, pause, seek, and replay the voice note inside the message bubble.

Recordings use mono AAC audio in an MPEG-4 (`.m4a`) container at 44.1 kHz and 96 kbps. Partial recordings are held only in the app cache and are deleted after upload, cancellation, or screen disposal.

## Android files changed

- `app/src/main/AndroidManifest.xml`
  - adds `android.permission.RECORD_AUDIO`.
- `app/src/main/java/com/ers/emergencyresponseapp/CoordinationPortalScreen.kt`
  - microphone permission request;
  - recording timer, discard/send UI, and two-minute auto-send;
  - in-message audio player and seek bar;
  - voice notes in Shared Media & Files.
- `app/src/main/java/com/ers/emergencyresponseapp/coordination/model/ChatMessage.kt`
  - adds `MessageType.AUDIO`, MIME, size, and duration metadata.
- `app/src/main/java/com/ers/emergencyresponseapp/coordination/model/viewmodel/CoordinationViewModel.kt`
  - uploads `.m4a` recordings;
  - writes/reads private Firebase audio messages;
  - sends/reads department audio messages through the API;
  - sends voice-message notification previews.
- `app/src/main/java/com/ers/emergencyresponseapp/coordination/voice/VoiceRecorder.kt`
  - lifecycle-safe `MediaRecorder` wrapper.
- `FCM_NOTIFICATION_PAYLOADS.md`
  - documents `message_type=audio`.

## Required backend deployment

Deploy the matching `api_app` package before releasing this Android build. The required endpoints are:

- `upload_chat_file.php`
- `send-interagency-group-attachment.php`
- `get-interagency-group-messages.php`

See `VOICE_MESSAGE_BACKEND_DEPLOYMENT.md` in the API package. No database migration is required; department voice metadata is stored in the existing `message_details` JSON.

## Firebase compatibility

Private voice messages add these fields under `messages/<threadId>/<messageId>`:

```json
{
  "type": "AUDIO",
  "text": "Voice message",
  "attachmentUri": "https://.../uploads/chat/chat_....m4a",
  "attachmentName": "voice_....m4a",
  "attachmentMimeType": "audio/mp4",
  "attachmentSize": 1450021,
  "audioDurationMs": 64320
}
```

When Firebase Realtime Database security rules whitelist fields or message types, allow `AUDIO` plus the attachment metadata above. Do not make the whole database publicly writable.

Older app builds degrade safely: they can display the text `Voice message`, but they will not have the in-app audio control.

## Build and device test

Open the project in Android Studio and build with the configured Android SDK. Test on at least one Android 8–11 device/emulator and one Android 12+ device because the `MediaRecorder` constructor differs by platform version.

Minimum acceptance tests:

1. deny and then grant microphone permission;
2. send a private voice message and play it on a second account;
3. send a department voice message and play it on another group member account;
4. pause, seek, replay, and leave the screen during playback;
5. discard a recording and confirm no message/file is created;
6. background the app while recording and confirm the microphone is released;
7. reach the two-minute limit and confirm automatic send;
8. confirm notification text says `Sent a voice message`;
9. confirm text, image, file, incident-tip, and report workflows still operate.

## Operational limits

- recording duration: 2 minutes;
- Android voice-upload guard: 15 MB;
- backend upload limit: 25 MB;
- transport: HTTPS only.

Voice recordings may contain sensitive operational or personal data. Apply the same access-control, retention, audit, and deletion policy used for other incident evidence.
