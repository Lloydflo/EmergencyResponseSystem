<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_role('dispatcher', 'dispatcher/call.php');
require_once __DIR__ . '/../includes/gemini_helper.php';
require_once __DIR__ . '/../includes/incident_priority.php';

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$description = trim((string)($input['description'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$callNotes = trim((string)($input['call_notes'] ?? $input['callNotes'] ?? ''));
$incidentTypes = $input['incident_types'] ?? [];
if (!is_array($incidentTypes)) {
    $incidentTypes = [$incidentTypes];
}
$incidentTypes = array_values(array_filter(array_map(static function ($value): string {
    return trim((string)$value);
}, $incidentTypes), static fn (string $value): bool => $value !== ''));

if (strlen($description) < 3 && strlen($callNotes) < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Description or call notes are required']);
    exit;
}

$priorityAssessment = ers_build_incident_priority_assessment([
    'description' => $description,
    'location' => $location,
    'call_notes' => $callNotes,
    'incident_types' => $incidentTypes,
], 'medium');
$priorityMetricForAi = [
    'score_0_to_100' => (int)($priorityAssessment['score'] ?? 0),
    'suggested_priority' => (string)($priorityAssessment['priority'] ?? 'medium'),
    'confidence' => (float)($priorityAssessment['confidence'] ?? 0.25),
    'has_indicator' => (bool)($priorityAssessment['has_indicator'] ?? false),
    'factors' => array_slice(array_map(static function (array $factor): array {
        return [
            'label' => (string)($factor['label'] ?? ''),
            'weight' => (int)($factor['weight'] ?? 0),
        ];
    }, (array)($priorityAssessment['factors'] ?? [])), 0, 8),
];

function priority_fallback_response(array $assessment): array
{
    $priority = ers_normalize_priority_value((string)($assessment['priority'] ?? 'medium'));
    $factors = array_values(array_filter(array_map(static function ($factor): string {
        return trim((string)($factor['label'] ?? ''));
    }, (array)($assessment['factors'] ?? []))));
    $reason = !empty($factors)
        ? implode('; ', array_slice($factors, 0, 2)) . '.'
        : 'Based on the incident details currently provided.';

    return [
        'ok' => true,
        'priority' => $priority,
        'reason' => $reason,
        'confidence' => (float)($assessment['confidence'] ?? 0.25),
        'needs_more_info' => empty($factors)
            ? ['Is anyone in immediate danger, injured, or unable to leave the area?']
            : [],
        'source' => 'local_rules',
    ];
}

$context = [
    'allowed_priorities' => ['low', 'medium', 'high', 'critical'],
    'incident_types' => $incidentTypes,
    'location' => $location,
    'description' => $description,
    'call_notes' => $callNotes,
    'backend_priority_metric' => $priorityMetricForAi,
    'local_guidance' => [
        'critical' => 'Immediate life threat, active major fire/explosion, not breathing, unconscious, trapped victim, weapon threat, multiple serious casualties.',
        'high' => 'Serious injury, active danger, escalating fire/smoke, violent incident, severe symptoms, urgent but not clearly critical.',
        'medium' => 'Needs timely response but no confirmed immediate life threat.',
        'low' => 'Minor, stable, resolved, or informational incident.',
    ],
];

$prompt = "You are an AI assistant for an emergency call center in the Philippines.\n"
    . "Follow this Priority Score & Keyword Dictionary strictly:\n"
    . "- Critical (80 to 100 pts): Direct/Immediate life threat, active major hazard (explosion, fire, earthquake, flood, collapse, trapped), unconscious/not breathing victim, armed/violent threat (gunshot, shot, stab, weapon, armed, barilan, binaril, saksak, may armas, sunog, pagsabog, lindol, baha, gumuho, kombulsyon, cardiac arrest, mass casualty).\n"
    . "- High (50 to 80 pts): Severe injury, active danger, escalating situation, violent incident, severe medical symptoms (severe bleeding, stroke, seizure, burns).\n"
    . "- Medium (25 to 50 pts): Timely response needed; NO immediate life threat (injury, fracture, sprain, minor bleeding, assault, robbery, burglary, smoke, collision, accident, traffic, missing, sugat, aksidente, banggaan).\n"
    . "- Low (0 to 25 pts): Minor, stable, or resolved incident (minor, bahagya, walang sugat, hindi seryoso, okay na, stable).\n\n"
    . "Understand English, Tagalog, and Taglish. If keywords like 'explosion', 'pagsabog', 'fire', 'sunog', 'gunshot', 'barilan', or 'unconscious' appear, assign CRITICAL priority.\n"
    . "Return ONLY valid JSON with this exact shape:\n"
    . "{\"priority\":\"low|medium|high|critical\",\"reason\":\"one short dispatcher-facing reason\",\"confidence\":0.0,\"needs_more_info\":[\"short question\"]}\n\n"
    . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$text = ers_gemini_generate_text($prompt, 0.1);
if (!is_string($text) || trim($text) === '') {
    // Gemini is optional enhancement only.  A failed provider must not make
    // dispatchers see an error or lose a safe priority recommendation.
    echo json_encode(priority_fallback_response($priorityAssessment), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$jsonText = trim($text);
if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $jsonText, $match)) {
    $jsonText = trim($match[1]);
} elseif (preg_match('/\{.*\}/s', $jsonText, $match)) {
    $jsonText = trim($match[0]);
}

$parsed = json_decode($jsonText, true);
if (!is_array($parsed)) {
    echo json_encode(priority_fallback_response($priorityAssessment), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$priority = strtolower(trim((string)($parsed['priority'] ?? '')));
if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
    echo json_encode(priority_fallback_response($priorityAssessment), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$reason = trim((string)($parsed['reason'] ?? ''));
if ($reason === '') {
    $reason = 'AI selected this based on the incident details.';
}
$reason = substr($reason, 0, 220);

$confidence = isset($parsed['confidence']) ? (float)$parsed['confidence'] : null;
if ($confidence !== null) {
    $confidence = max(0.0, min(1.0, $confidence));
}

$needsMoreInfo = $parsed['needs_more_info'] ?? [];
if (!is_array($needsMoreInfo)) {
    $needsMoreInfo = [];
}
$needsMoreInfo = array_slice(array_values(array_filter(array_map(static function ($value): string {
    return substr(trim((string)$value), 0, 120);
}, $needsMoreInfo), static fn (string $value): bool => $value !== '')), 0, 3);

echo json_encode([
    'ok' => true,
    'priority' => $priority,
    'reason' => $reason,
    'confidence' => $confidence,
    'needs_more_info' => $needsMoreInfo,
    'source' => 'gemini',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
