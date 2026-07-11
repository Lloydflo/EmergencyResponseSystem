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
