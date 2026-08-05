# Coordination voice messages — audible playback fix

This Android source supports voice notes in both Coordination chat modes:

- private responder-to-responder conversations through Firebase Realtime Database metadata;
- department/inter-agency group channels through the PHP/MySQL API.

The binary audio file is uploaded to the PHP server. Firebase stores only the private-message metadata and the HTTPS file URL.

## What was fixed

The earlier build could upload a `.wav` recording while declaring it as `audio/mp4`. Some servers and Android decoders then treated the response as the wrong format, which could appear as a voice message that played without audible sound.

This build now:

1. records mono 16-bit PCM as a standard `.wav` file;
2. measures peak and RMS microphone levels and rejects a digitally silent recording before upload;
3. uploads the file with the correct `audio/wav` MIME type;
4. downloads the remote file into an app-private cache before playback;
5. validates that the response is audio rather than HTML/JSON;
6. plays the local cached file through the media stream;
7. warns when the device media volume is muted;
8. displays a useful download/decoder error instead of failing silently.

Old recordings whose uploaded file already contains silence cannot be reconstructed. Test with a newly recorded message after deploying both the backend and this Android build.

## Responder experience

1. Open a private responder chat or a department channel.
2. Leave the text field empty and tap the microphone button.
3. Grant microphone permission when prompted.
4. Speak, then tap Send; tap Delete to discard.
5. The app automatically finishes and sends at the two-minute limit.
6. Recipients can play, pause, seek, and replay the voice note in the message bubble.

Temporary recordings are kept only in the app cache and are deleted after upload or cancellation. The maximum WAV size is below the Android 15 MB voice-upload guard even when the device falls back to a 48 kHz sample rate.

## Android files involved

- `app/src/main/AndroidManifest.xml` — `RECORD_AUDIO` permission.
- `app/src/main/java/com/ers/emergencyresponseapp/CoordinationPortalScreen.kt` — recording UI and local-cache playback.
- `app/src/main/java/com/ers/emergencyresponseapp/coordination/model/ChatMessage.kt` — `MessageType.AUDIO` and attachment metadata.
- `app/src/main/java/com/ers/emergencyresponseapp/coordination/model/viewmodel/CoordinationViewModel.kt` — correct MIME upload and private/group message persistence.
- `app/src/main/java/com/ers/emergencyresponseapp/coordination/voice/VoiceRecorder.kt` — PCM WAV recording and silence detection.
- `app/src/main/java/com/ers/emergencyresponseapp/coordination/voice/VoicePlaybackCache.kt` — HTTPS download, validation, and cache.

## Required backend deployment

Deploy the matching `api_app` package first. Voice playback depends on:

- `upload_chat_file.php`
- `chat-file.php`
- `send-interagency-group-attachment.php`
- `get-interagency-group-messages.php`

`chat-file.php` serves deterministic MIME headers and byte-range responses. No voice-message database migration is required.

## Firebase compatibility

A private voice message is stored under `messages/<threadId>/<messageId>` similar to:

```json
{
  "type": "AUDIO",
  "text": "Voice message",
  "attachmentUri": "https://emergency-response.alertaraqc.com/api/api_app/chat-file.php?file=chat_....wav",
  "attachmentName": "voice_....wav",
  "attachmentMimeType": "audio/wav",
  "attachmentSize": 204844,
  "audioDurationMs": 6400
}
```

The Firebase rules supplied by the project already allow arbitrary reads/writes under `messages`, so no extra `audio` node or rule is required for functionality. Those rules are publicly readable/writable and are not suitable as a long-term production security model. Because this app currently uses PHP login rather than Firebase Authentication, changing the rules to `auth != null` without first adding Firebase custom-token sign-in will break private chat.

## Acceptance test

Deploy the backend before installing version 17.2 (versionCode 25), then test with two devices/accounts:

1. record a new private voice note while speaking continuously for at least two seconds;
2. play it on the sender and recipient devices;
3. repeat in a department channel;
4. pause, seek, replay, and leave/reopen the chat;
5. set media volume to zero and confirm the warning appears;
6. block the microphone or remain silent and confirm the app refuses to upload the recording;
7. confirm text/image/file messages still work.
