<?php
/**
 * Gemini AI Helper Functions
 * Utility functions for integrating Gemini AI into the ERS system
 */

require_once __DIR__ . '/config.php';

if (!function_exists('setGeminiLastError')) {
    function setGeminiLastError($message) {
        $GLOBALS['ERS_GEMINI_LAST_ERROR'] = $message ? trim((string) $message) : '';
    }
}

if (!function_exists('getGeminiLastError')) {
    function getGeminiLastError() {
        return isset($GLOBALS['ERS_GEMINI_LAST_ERROR']) ? (string) $GLOBALS['ERS_GEMINI_LAST_ERROR'] : '';
    }
}

if (!function_exists('ers_clean_ai_text')) {
    function ers_clean_ai_text($text, $maxLines = 7, $maxChars = 900) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace('/[`*_#]/', '', $text);
        $text = preg_replace('/^\s*[-•]+\s*/m', '- ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $lines = explode("\n", $text);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $clean[] = $line;
        }
        if (count($clean) > $maxLines) {
            $clean = array_slice($clean, 0, $maxLines);
        }
        $out = trim(implode("\n", $clean));
        if ($out === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($out) > $maxChars) {
                $out = rtrim(mb_substr($out, 0, $maxChars - 3)) . '...';
            }
        } elseif (strlen($out) > $maxChars) {
            $out = rtrim(substr($out, 0, $maxChars - 3)) . '...';
        }
        return $out;
    }
}

if (!function_exists('ers_read_env_value_from_file')) {
    function ers_read_env_value_from_file($filePath, $key) {
        if (!is_string($filePath) || $filePath === '' || !file_exists($filePath)) {
            return '';
        }
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return '';
        }
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            list($name, $value) = explode('=', $line, 2);
            if (trim((string)$name) !== $key) {
                continue;
            }
            $value = trim((string)$value);
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            return trim((string)$value);
        }
        return '';
    }
}

if (!function_exists('ers_resolve_gemini_key')) {
    function ers_resolve_gemini_key() {
        $candidates = [];
        if (defined('GEMINI_API_KEY')) {
            $candidates[] = (string)GEMINI_API_KEY;
        }
        $candidates[] = (string)($_ENV['GEMINI_API_KEY'] ?? '');
        $candidates[] = (string)getenv('GEMINI_API_KEY');
        $candidates[] = (string)($_SERVER['GEMINI_API_KEY'] ?? '');
        $candidates[] = (string)($_ENV['GOOGLE_API_KEY'] ?? '');
        $candidates[] = (string)getenv('GOOGLE_API_KEY');
        $candidates[] = (string)($_SERVER['GOOGLE_API_KEY'] ?? '');

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $envPaths = [
            dirname(__DIR__) . '/.env',
            __DIR__ . '/.env',
            __DIR__ . '/../.env',
            dirname(__DIR__, 2) . '/.env',
        ];

        foreach ($envPaths as $envPath) {
            $value = ers_read_env_value_from_file($envPath, 'GEMINI_API_KEY');
            if ($value !== '') {
                return $value;
            }
            $value = ers_read_env_value_from_file($envPath, 'GOOGLE_API_KEY');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}

if (!function_exists('ers_resolve_gemini_url')) {
    function ers_resolve_gemini_url() {
        $defaultUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
        $candidates = [];
        if (defined('GEMINI_API_URL')) {
            $candidates[] = (string)GEMINI_API_URL;
        }
        $candidates[] = (string)($_ENV['GEMINI_API_URL'] ?? '');
        $candidates[] = (string)getenv('GEMINI_API_URL');
        $candidates[] = (string)($_SERVER['GEMINI_API_URL'] ?? '');

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $envPaths = [
            dirname(__DIR__) . '/.env',
            __DIR__ . '/.env',
            __DIR__ . '/../.env',
            dirname(__DIR__, 2) . '/.env',
        ];
        foreach ($envPaths as $envPath) {
            $value = ers_read_env_value_from_file($envPath, 'GEMINI_API_URL');
            if ($value !== '') {
                return $value;
            }
        }

        return $defaultUrl;
    }
}

if (!function_exists('ers_gemini_url_candidates')) {
    function ers_gemini_url_candidates($configuredUrl) {
        $urls = [];
        $configuredUrl = trim((string)$configuredUrl);
        if ($configuredUrl !== '') {
            $urls[] = $configuredUrl;
        }

        $fallbackModels = [
            'gemini-2.0-flash',
            'gemini-1.5-flash',
        ];
        foreach ($fallbackModels as $model) {
            $urls[] = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
        }

        return array_values(array_unique($urls));
    }
}

if (!function_exists('ers_should_retry_gemini_with_fallback_model')) {
    function ers_should_retry_gemini_with_fallback_model($httpCode, $apiError) {
        $apiError = strtolower(trim((string)$apiError));

        if ($httpCode === 404) {
            return true;
        }

        if ($httpCode === 400) {
            return (
                strpos($apiError, 'model') !== false &&
                (
                    strpos($apiError, 'not found') !== false ||
                    strpos($apiError, 'unsupported') !== false ||
                    strpos($apiError, 'not available') !== false
                )
            );
        }

        if ($httpCode === 403) {
            if (
                strpos($apiError, 'api key not valid') !== false ||
                strpos($apiError, 'invalid api key') !== false
            ) {
                return false;
            }
            return (
                strpos($apiError, 'model') !== false ||
                strpos($apiError, 'permission') !== false ||
                strpos($apiError, 'access') !== false
            );
        }

        return false;
    }
}

/**
 * Make a request to Gemini AI API
 * @param string $prompt The prompt to send to AI
 * @return string|null The AI response or null on error
 */
function callGeminiAPI($prompt) {
    setGeminiLastError('');

    $apiKey = trim((string)ers_resolve_gemini_key());
    $apiBaseUrl = trim((string)ers_resolve_gemini_url());
    $prompt = trim((string) $prompt);

    if ($prompt === '') {
        setGeminiLastError('Gemini prompt is empty.');
        return null;
    }

    if ($apiKey === '' || $apiBaseUrl === '') {
        setGeminiLastError('Gemini configuration is missing.');
        return null;
    }

    $apiUrl = $apiBaseUrl . '?key=' . urlencode($apiKey);

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    $jsonData = json_encode($data);

    // Initialize cURL
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $msg = $curlError !== '' ? $curlError : 'Unknown cURL error';
        setGeminiLastError('Gemini request failed: ' . $msg);
        error_log('Gemini API request failed: ' . $msg);
        return null;
    }

    $responseData = json_decode((string) $response, true);

    if ($httpCode === 200) {
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return ers_clean_ai_text($responseData['candidates'][0]['content']['parts'][0]['text']);
        }

        setGeminiLastError('Gemini returned an empty response.');
        error_log('Gemini API returned HTTP 200 with no candidate text.');
        return null;
    }

    $apiError = '';
    if (is_array($responseData) && isset($responseData['error']['message'])) {
        $apiError = trim((string) $responseData['error']['message']);
    }

    if ($httpCode === 429) {
        $friendly = 'Gemini quota exceeded. Check API plan/billing or wait then retry.';
    } elseif ($httpCode === 401 || $httpCode === 403) {
        $friendly = 'Gemini key is invalid or not authorized for this API/project.';
    } elseif ($httpCode >= 500) {
        $friendly = 'Gemini service is temporarily unavailable. Please retry.';
    } else {
        $friendly = 'Gemini request failed (HTTP ' . $httpCode . ').';
    }

    // Do not expose raw auth/quota provider messages directly to UI.
    if ($apiError !== '' && !in_array($httpCode, [401, 403, 429], true)) {
        $friendly .= ' ' . $apiError;
    }
    setGeminiLastError($friendly);

    if ($apiError !== '') {
        error_log('Gemini API error HTTP ' . $httpCode . ': ' . $apiError);
    } else {
        error_log('Gemini API error HTTP ' . $httpCode . '.');
    }

    return null;
}

/**
 * Generate AI-powered incident analysis
 * @param array $incidentData Incident details
 * @return string AI analysis
 */
function analyzeIncident($incidentData) {
    $prompt = "You are the ERS incident assistant. Use only the given data.\n";
    $prompt .= "If a field is missing, say 'Data unavailable' only.\n";
    $prompt .= "Keep output short, practical, and no markdown.\n\n";
    $prompt .= "Incident Data:\n";
    $prompt .= "Type: " . ($incidentData['type'] ?? 'Unknown') . "\n";
    $prompt .= "Location: " . ($incidentData['location'] ?? 'Unknown') . "\n";
    $prompt .= "Description: " . ($incidentData['description'] ?? 'No description') . "\n";
    $prompt .= "Severity/Priority: " . ($incidentData['severity'] ?? 'Unknown') . "\n\n";
    $prompt .= "Return max 5 lines:\n";
    $prompt .= "1) Situation summary\n";
    $prompt .= "2) Top action now\n";
    $prompt .= "3) Next action\n";
    $prompt .= "4) Safety note\n";
    $prompt .= "5) Resource gap";

    return callGeminiAPI($prompt);
}

/**
 * Generate AI insights for reports
 * @param array $reportData Report metrics and data
 * @return string AI insights
 */
function generateReportInsights($reportData) {
    $prompt = "You are the ERS reporting assistant. Use only provided metrics.\n";
    $prompt .= "Keep output concise and no markdown.\n\n";
    $prompt .= "Metrics:\n";
    $prompt .= "Total Incidents: " . ($reportData['total_incidents'] ?? 0) . "\n";
    $prompt .= "Average Response Time: " . ($reportData['avg_response_time'] ?? 'Unknown') . "\n";
    $prompt .= "Resource Utilization: " . ($reportData['resource_utilization'] ?? 'Unknown') . "\n";
    $prompt .= "Active Responders: " . ($reportData['active_responders'] ?? 0) . "\n";
    $prompt .= "Resolved Incidents: " . ($reportData['resolved_incidents'] ?? 0) . "\n";
    $prompt .= "Success Rate: " . ($reportData['success_rate'] ?? 'Unknown') . "\n\n";
    $prompt .= "Return max 6 short lines:\n";
    $prompt .= "1) Overall performance\n";
    $prompt .= "2) Positive signal\n";
    $prompt .= "3) Main risk\n";
    $prompt .= "4) Resource focus\n";
    $prompt .= "5) Immediate improvement\n";
    $prompt .= "6) Next 24h priority";

    return callGeminiAPI($prompt);
}

/**
 * AI-assisted dispatch recommendations
 * @param array $dispatchData Current dispatch situation
 * @return string AI recommendations
 */
function getDispatchRecommendations($dispatchData) {
    $prompt = "You are the ERS dispatch assistant. Use only provided values.\n";
    $prompt .= "Keep output short and action-oriented. No markdown.\n\n";
    $prompt .= "Dispatch Snapshot:\n";
    $prompt .= "Active Incidents: " . ($dispatchData['active_incidents'] ?? 0) . "\n";
    $prompt .= "Available Units: " . ($dispatchData['available_units'] ?? 0) . "\n";
    $prompt .= "Pending Calls: " . ($dispatchData['pending_calls'] ?? 0) . "\n";
    $prompt .= "Current Incident: " . ($dispatchData['current_incident'] ?? 'None') . "\n\n";
    $prompt .= "Return max 5 short lines:\n";
    $prompt .= "1) Current load assessment\n";
    $prompt .= "2) Dispatch priority\n";
    $prompt .= "3) Unit allocation suggestion\n";
    $prompt .= "4) Queue/backlog action\n";
    $prompt .= "5) Escalation trigger";

    return callGeminiAPI($prompt);
}

/**
 * Generate predictive resource needs
 * @param array $historicalData Historical incident data
 * @return string AI predictions
 */
function getResourceGapRecommendations($resourceData) {
    $prompt = "You are the ERS resource recommendation assistant.\n";
    $prompt .= "Analyze shortages using only the data below. No markdown.\n";
    $prompt .= "Keep output short and specific.\n\n";
    $prompt .= "Resource Snapshot:\n";
    $prompt .= "Vehicles total: " . ($resourceData['vehicles_total'] ?? 0) . "\n";
    $prompt .= "Vehicles available: " . ($resourceData['vehicles_available'] ?? 0) . "\n";
    $prompt .= "Vehicles in use: " . ($resourceData['vehicles_inuse'] ?? 0) . "\n";
    $prompt .= "Vehicles offline: " . ($resourceData['vehicles_offline'] ?? 0) . "\n";
    $prompt .= "Personnel total: " . ($resourceData['personnel_total'] ?? 0) . "\n";
    $prompt .= "Personnel available: " . ($resourceData['personnel_available'] ?? 0) . "\n";
    $prompt .= "Personnel in use: " . ($resourceData['personnel_inuse'] ?? 0) . "\n";
    $prompt .= "Personnel offline: " . ($resourceData['personnel_offline'] ?? 0) . "\n";
    $prompt .= "Equipment total: " . ($resourceData['equipment_total'] ?? 0) . "\n";
    $prompt .= "Equipment available: " . ($resourceData['equipment_available'] ?? 0) . "\n";
    $prompt .= "Equipment in use: " . ($resourceData['equipment_inuse'] ?? 0) . "\n";
    $prompt .= "Equipment offline: " . ($resourceData['equipment_offline'] ?? 0) . "\n";
    $prompt .= "Active incidents: " . ($resourceData['active_incidents'] ?? 0) . "\n";
    $prompt .= "Pending resource requests summary: " . ($resourceData['pending_request_summary'] ?? 'None') . "\n\n";
    $prompt .= "Return max 6 short lines:\n";
    $prompt .= "1) Biggest current shortage\n";
    $prompt .= "2) Secondary shortage\n";
    $prompt .= "3) Immediate reallocation action\n";
    $prompt .= "4) What to request now\n";
    $prompt .= "5) Risk if no action\n";
    $prompt .= "6) Priority order (1-3)";

    return callGeminiAPI($prompt);
}

function predictResourceNeeds($historicalData) {
    $normalized = [
        'vehicles_total' => $historicalData['vehicles_total'] ?? 0,
        'vehicles_available' => $historicalData['vehicles_available'] ?? 0,
        'vehicles_inuse' => $historicalData['vehicles_inuse'] ?? 0,
        'vehicles_offline' => $historicalData['vehicles_offline'] ?? 0,
        'personnel_total' => $historicalData['personnel_total'] ?? 0,
        'personnel_available' => $historicalData['personnel_available'] ?? 0,
        'personnel_inuse' => $historicalData['personnel_inuse'] ?? 0,
        'personnel_offline' => $historicalData['personnel_offline'] ?? 0,
        'equipment_total' => $historicalData['equipment_total'] ?? 0,
        'equipment_available' => $historicalData['equipment_available'] ?? 0,
        'equipment_inuse' => $historicalData['equipment_inuse'] ?? 0,
        'equipment_offline' => $historicalData['equipment_offline'] ?? 0,
        'active_incidents' => $historicalData['active_incidents'] ?? ($historicalData['weekly_incidents'] ?? 0),
        'pending_request_summary' => $historicalData['pending_request_summary'] ?? ($historicalData['current_resources'] ?? 'None'),
    ];

    return getResourceGapRecommendations($normalized);

}

if (!function_exists('generatePredictiveAnalyticsInsights')) {
    function generatePredictiveAnalyticsInsights($predictiveData) {
        $forecast = is_array($predictiveData['forecast'] ?? null) ? $predictiveData['forecast'] : [];
        $resource = is_array($predictiveData['resource'] ?? null) ? $predictiveData['resource'] : [];
        $current = is_array($predictiveData['current'] ?? null) ? $predictiveData['current'] : [];
        $peakHour = is_array($predictiveData['peak_hour'] ?? null) ? $predictiveData['peak_hour'] : [];
        $typeForecast = is_array($predictiveData['type_forecast'] ?? null) ? $predictiveData['type_forecast'] : [];
        $hotspots = is_array($predictiveData['hotspots'] ?? null) ? $predictiveData['hotspots'] : [];

        $typeSummary = [];
        foreach (array_slice($typeForecast, 0, 3) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $typeSummary[] = trim((string)($row['label'] ?? 'Other')) . '=' . (int)($row['forecast'] ?? 0);
        }
        $hotspot = $hotspots[0] ?? [];

        $prompt = "You are the ERS predictive analytics assistant.\n";
        $prompt .= "Use only the forecast snapshot below. No markdown. Keep each line short and operational.\n\n";
        $prompt .= "Forecast Snapshot:\n";
        $prompt .= "Next 7 day incidents: " . (int)($forecast['next_7_total'] ?? 0) . "\n";
        $prompt .= "Forecast average per day: " . ($forecast['avg_daily'] ?? 0) . "\n";
        $prompt .= "Forecast delta percent: " . ($forecast['delta_percent'] ?? 0) . "\n";
        $prompt .= "High priority forecast load: " . (int)($forecast['high_priority_load'] ?? 0) . "\n";
        $prompt .= "Peak window: " . ($peakHour['label'] ?? 'Unavailable') . "\n";
        $prompt .= "Active incidents now: " . (int)($current['active_incidents'] ?? 0) . "\n";
        $prompt .= "Resource strain index: " . ($resource['strain_index'] ?? 0) . "%\n";
        $prompt .= "Available units: " . (int)($resource['available_units'] ?? 0) . "\n";
        $prompt .= "Busy units: " . (int)($resource['busy_units'] ?? 0) . "\n";
        $prompt .= "Active responders: " . (int)($resource['active_responders'] ?? 0) . "\n";
        $prompt .= "Top forecast mix: " . ($typeSummary ? implode(', ', $typeSummary) : 'No type forecast available') . "\n";
        $prompt .= "Top hotspot: " . trim((string)($hotspot['location'] ?? 'None')) . "\n";
        $prompt .= "Hotspot risk: " . trim((string)($hotspot['risk'] ?? 'Low')) . "\n";
        $prompt .= "Hotspot dominant type: " . trim((string)($hotspot['dominant_type'] ?? 'Unknown')) . "\n\n";
        $prompt .= "Return max 6 short lines:\n";
        $prompt .= "1) Overall next-7-day demand outlook\n";
        $prompt .= "2) Peak operating window\n";
        $prompt .= "3) Most likely pressure area\n";
        $prompt .= "4) Resource strain warning\n";
        $prompt .= "5) Pre-positioning recommendation\n";
        $prompt .= "6) Command note for the next shift";

        return callGeminiAPI($prompt);
    }
}

/**
 * Log activity
 * @param PDO $pdo Database connection
 * @param int $user_id User ID
 * @param string $action Action performed
 * @param string $entity_type Entity type
 * @param int $entity_id Entity ID
 * @param string $details Additional details
 */
function log_activity($pdo, $user_id, $action, $entity_type, $entity_id, $details = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $entity_type, $entity_id, $details]);
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        $isDuplicateZeroPrimary = (strpos($msg, "Duplicate entry '0' for key 'PRIMARY'") !== false);
        if (!$isDuplicateZeroPrimary) {
            throw $e;
        }

        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nextId, $user_id, $action, $entity_type, $entity_id, $details]);
    }
}
