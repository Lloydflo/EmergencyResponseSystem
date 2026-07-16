# ERS Central API

Use this single endpoint for external system integrations:

```text
/ERS/api/
```

External incident intake is disabled by default. Enable it only when the partner
system is ready:

```env
ERS_EXTERNAL_INTAKE_ENABLED=true
```

Authentication can be sent as a header:

```http
X-ERS-API-Key: your-api-key
```

For quick browser testing only:

```text
/ERS/api/?api_key=your-api-key
```

Default overview:

```http
GET /ERS/api/
```

Available actions:

```http
GET /ERS/api/?action=incidents
GET /ERS/api/?action=resources
GET /ERS/api/?action=alerts
GET /ERS/api/?action=calls
GET /ERS/api/?action=conversations
POST /ERS/api/?action=create_incident
POST /ERS/api/?action=incoming-transfer
PATCH /ERS/api/?action=incident_status
```

Create incident body. This saves the incident and sends it as an incident card in Interagency chat:

```json
{
  "source_system": "External System",
  "external_incident_id": "TIP-2026-001",
  "incident": {
    "tip_id": "TIP-2026-001",
    "timestamp": "2026-07-08 21:43:52",
    "type": "police",
    "priority": "high",
    "location": "Heavenly Drive Brgy. San Agustin QC",
    "description": "May nagri-riot po dito tapos may isang may dalang knife.",
    "reason_for_police_backup": "We need police officers to assist us.",
    "caller_name": "Juan Dela Cruz",
    "caller_phone": "09171234567"
  }
}
```

Incoming transfer call body. This saves the transferred call and creates the paired incident:

```json
{
  "source_system": "Partner Dispatch",
  "transfer_id": "CALL-2026-001",
  "call": {
    "callId": "CALL-2026-001",
    "room": "emergency-call-CALL-2026-001",
    "socketUrl": "https://emergency-comm.alertaraqc.com",
    "socketPath": "/socket.io",
    "caller_name": "Maria Santos",
    "caller_phone": "09171234567"
  },
  "incident": {
    "incident_type": "medical",
    "priority": "high",
    "location": "Quezon City Hall",
    "latitude": 14.6507,
    "longitude": 121.0494,
    "description": "Caller reports chest pain near the main entrance."
  }
}
```

You can also post the same body to:

```http
POST /ERS/api/incoming-transfer.php
```

AlertaraQC Emergency Communication integration:

```http
POST /ERS/api/?action=create_incident&api_key=${ERS_EXTERNAL_API_KEY}
Authorization: Bearer ${ERS_EXTERNAL_API_KEY}
X-API-Key: ${ERS_EXTERNAL_API_KEY}
X-ERS-API-Key: ${ERS_EXTERNAL_API_KEY}
X-ERS-Client: emergency-comm-alertaraqc
```

The endpoint accepts JSON or form-data. It supports two transfer types:

- Live call transfer: `callId`/`call_id` and `room` are present. The incident is
  saved, then the dispatcher can answer through AlertaraQC Socket.IO/WebRTC.
- Report/message transfer: `callId` and `room` are empty or missing. The
  incident/report is saved only; no Socket.IO call modal is opened.

Transfer payload fields such as `event`, `callId`, `call_id`, `room`,
`socketUrl`, `socket_url`, `socketPath`, `socket_path`, `conversationId`,
`conversation_id`, `emergencyType`, `emergency_type`, `incident_type`, `type`,
`priority`, `status`, `title`, `description`, `caller_name`, `caller_phone`,
`caller_address`, `location_address`, `latitude`, `longitude`, `messages`,
`transferred_at`, and `payload_json` are detected as incoming transfers.

The dispatcher call screen polls `api/incoming_transfers.php`, opens the
transferred-call modal, plays an alert tone when the browser allows audio, and
uses the transfer `room` with Socket.IO polling at `https://emergency-comm.alertaraqc.com/socket.io`.
Caller name, phone number, incident type, priority, location, coordinates, and
description from the transfer payload are automatically filled into the call
logging form after the dispatcher accepts the call.
When the dispatcher accepts the call, the browser joins `payload.room`, listens
for `offer`, answers with local microphone audio through WebRTC, and exchanges
ICE `candidate` events in the same room. Do not hardcode a room name; each call
uses its own `emergency-call-{callId}` room from the payload.
