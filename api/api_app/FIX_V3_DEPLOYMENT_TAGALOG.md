# ERS Voice Audio + Report Approval Workflow Fix v3

Ang package na ito ay para sa dalawang issue na na-report pagkatapos ng unang voice-message update:

1. nakakapag-send ang voice message pero walang naririnig sa playback; at
2. nawala ang **Pending**, **Submitted**, at **Approved** sa Create Report workflow.

Nananatiling tanggal ang **Service Review** at lahat ng star ratings. Nananatili ang **Create Report**, at ibinalik ang admin review/approval flow.

## Ano ang inayos

### 1. Audible voice messages sa Coordination

Ang recording ay mono 16-bit PCM **WAV** na ngayon. Bago i-upload, sinusukat ng app ang peak at RMS microphone level. Kapag halos digital silence ang nakuha, hindi ito ise-send at magpapakita ang app ng malinaw na microphone error.

Ang upload at playback path ay inayos din:

- tamang `audio/wav` MIME type;
- PHP streaming endpoint na may deterministic `Content-Type` at HTTP byte-range support;
- local app-private download/cache bago i-play sa `MediaPlayer`;
- validation para hindi mapagkamalang audio ang HTML/JSON error page;
- media-volume warning at visible decoder/download errors;
- play, pause, seek, replay, at duration support.

> Ang lumang uploaded file na talagang silent na ay hindi na mare-reconstruct. Gumamit ng **bagong recording** pagkatapos ma-deploy ang backend at Android build na ito.

### 2. Report status at admin approval workflow

Responder-facing states:

| Status | Behavior |
|---|---|
| **Pending** | Hindi pa nasisimulan, saved for later, o ibinalik ng admin para i-revise. Editable. |
| **Submitted** | Naipadala na para sa admin legitimacy/review check. Locked/read-only habang nire-review. |
| **Approved** | Inaprubahan ng authorized admin. Final/read-only. |
| **Needs Revision** | Ibinalik ng admin na may reviewer notes. Lumalabas sa Pending at editable ulit. |

Backward-compatible ang database values:

| API/UI workflow status | Existing database value |
|---|---|
| `pending` | `draft` |
| `submitted` | `submitted` |
| `approved` | `verified` |
| `revision_required` | `returned` |

Hindi kailangang i-convert ang existing rows. Ang API ay nagre-return na rin ng `workflow_status`, `status_label`, at `is_editable`.

## Firebase rules na ipinadala ninyo

Ang kasalukuyang rules ay **sapat para gumana** ang private voice-message metadata. Hindi kailangan magdagdag ng `audio` node:

- private message metadata/URL: `messages/<threadId>/<messageId>`;
- thread preview: `threads/<threadId>`;
- actual audio bytes: PHP server sa `/uploads/chat/`;
- department/inter-agency messages: PHP/MySQL.

Kaya hindi Firebase rules ang root cause ng silent audio.

Gayunman, ang `.read: true` at `.write: true` ay public access. Sinumang makakuha ng Firebase Database URL ay posibleng makabasa, makapagbago, o makapag-delete ng operational data. Huwag muna itong biglang palitan ng `auth != null`: PHP login ang gamit ng current app at wala pang Firebase Authentication custom-token sign-in, kaya masisira ang private chat. Nasa `FIREBASE_RULES_ASSESSMENT.md` ang tamang migration sequence.

## Deployment order

### Step 1 — Backup

Mag-backup muna ng:

- current `public_html/api/api_app/`;
- database table `responder_after_action_reports` kung existing na;
- current Android source/release keystore configuration.

### Step 2 — Deploy backend bago ang Android app

Pinakasimple: i-overwrite ang production `api/api_app/` gamit ang complete backend package.

Minimum voice files:

```text
upload_chat_file.php
chat-file.php
send-interagency-group-attachment.php
get-interagency-group-messages.php
```

Minimum report-workflow files:

```text
_operational_api.php
_after_action_schema.php
get-completed-incidents.php
get-my-after-action-reports.php
upsert-after-action-report.php
get-after-action-reports.php
review-after-action-report.php
migrations/2026_08_03_create_responder_after_action_reports.sql
```

Tanggalin sa production kung naroon pa:

```text
get-pending-review-incidents.php
get-my-incident-reviews.php
submit-incident-review.php
```

Huwag burahin ang After-Action Report files dahil iyon ang backend ng **Create Report**.

### Step 3 — Server configuration para sa voice files

Set sa server environment:

```env
ERS_PUBLIC_BASE_URL=https://emergency-response.alertaraqc.com
ERS_API_APP_PUBLIC_PATH=/api/api_app
```

Siguraduhing writable ng PHP at readable ng web/PHP worker ang:

```text
public_html/uploads/chat/
```

Recommended PHP limits:

```ini
upload_max_filesize = 25M
post_max_size = 27M
max_file_uploads = 10
```

I-configure din ang reverse proxy/web server body limit kung mayroon. Huwag gumamit ng `0777` sa upload directory.

### Step 4 — Database migration, kung wala pa ang table

```bash
mysql -u DB_USER -p DB_NAME \
  < api_app/migrations/2026_08_03_create_responder_after_action_reports.sql
```

May safe `CREATE TABLE IF NOT EXISTS` fallback ang API, pero mas maayos pa ring patakbuhin ang supplied migration gamit ang authorized deployment account.

### Step 5 — Build at install ng Android app

Buksan ang `EmergencyResponseApp_source` sa Android Studio at gumawa ng signed release.

Package version:

```text
versionName: 17.2
versionCode: 25
```

Kung ang currently installed production APK ay may `versionCode` na 25 o mas mataas, itaas muna ito bago gumawa ng release.

## Voice-message acceptance test

Gamit ang dalawang responder accounts/devices:

1. Mag-record ng bagong private voice message nang tuloy-tuloy na nagsasalita nang hindi bababa sa 2 segundo.
2. I-play sa sender at recipient device.
3. Ulitin sa department/inter-agency group.
4. Test play, pause, seek, replay, at reopen ng chat.
5. I-zero ang media volume at tiyaking may warning.
6. Manatiling tahimik o i-mute/block ang microphone at tiyaking hindi nag-a-upload ng silent recording.
7. Tiyaking gumagana pa rin ang text, image, at file messages.

Backend smoke test:

```bash
curl -i \
  -F 'uploader_user_id=ACTIVE_RESPONDER_ID' \
  -F 'file=@sample.wav;type=audio/wav' \
  'https://emergency-response.alertaraqc.com/api/api_app/upload_chat_file.php'
```

Sa returned `file_url`:

```bash
curl -I 'RETURNED_FILE_URL'
curl -H 'Range: bytes=0-43' -i 'RETURNED_FILE_URL'
```

Expected:

```text
Content-Type: audio/wav
Accept-Ranges: bytes
HTTP 206 para sa range request
```

## Report/admin acceptance test

1. Responder opens a completed incident and presses **Create Report**.
2. **Save Pending**: dapat nasa Pending at editable pa rin.
3. **Submit Report**: dapat lumipat sa Submitted at maging read-only.
4. Admin fetches submitted reports:

```text
GET /api/api_app/get-after-action-reports.php?reviewer_id=ADMIN_ID&status=submitted
```

5. Dapat kasama sa response ang report at source incident evidence: reference, type, description, priority, location, completion notes/image, at completion time.
6. Admin approves:

```text
POST /api/api_app/review-after-action-report.php
reviewer_id=ADMIN_ID
report_id=REPORT_ID
action=approve
notes=Optional notes
```

7. Pag-refresh ng responder app: dapat nasa Approved at read-only.
8. Test return-for-revision:

```text
POST /api/api_app/review-after-action-report.php
reviewer_id=ADMIN_ID
report_id=REPORT_ID
action=return
notes=Required revision instructions
```

9. Pag-refresh: dapat nasa Pending bilang **Needs Revision**, makita ang notes, at editable ulit.

## Admin frontend scope

Ang uploaded files ay responder Android app at `api_app` backend. Wala sa upload ang hiwalay na admin web/mobile frontend, kaya hindi binago ang admin screen mismo. Ready na ang admin list/approve/return endpoints. Kailangang gamitin ng existing admin interface ang endpoints sa `ADMIN_REPORT_REVIEW_API.md` kung hindi pa ito nakakonekta.

## Validation limits

Na-static-validate ang PHP, Android XML, at modified Kotlin sources; na-test din locally ang streaming endpoint at focused voice classes. Hindi nakagawa ng full Gradle APK build sa environment na ito dahil walang installed Android SDK at walang cached Gradle distribution/dependencies. Kailangan pa rin ang Android Studio signed build at real two-device/server test bago production rollout.
