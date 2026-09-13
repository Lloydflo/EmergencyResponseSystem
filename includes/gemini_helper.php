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
    function ers_clean_ai_text($text, $maxLines = 8, $maxChars = 1200) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        // Remove code block markers and hash headings
        $text = preg_replace('/^#+\s*/m', '', $text);
        $text = preg_replace('/`{1,3}/', '', $text);
        // Normalize bullet points
        $text = preg_replace('/^\s*[-•*]+\s*/mu', '- ', $text);
        // Normalize numbered lines like "1) Heading: Text" or "1. **Heading:** Text" to "**Heading:** Text"
        $text = preg_replace('/^\s*\d+[\.\)]\s*(?:\*\*)?([^*:\n]+)(?:\*\*)?:\s*/mu', "**$1:** ", $text);
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
        $candidateKeys = ['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GEMINI_KEY', 'GOOGLE_GEMINI_API_KEY'];
        $candidates = [];

        foreach ($candidateKeys as $key) {
            if (defined($key) && trim((string)constant($key)) !== '') {
                $candidates[] = (string)constant($key);
            }
            if (!empty($_ENV[$key])) {
                $candidates[] = (string)$_ENV[$key];
            }
            $envVal = getenv($key);
            if ($envVal !== false && trim((string)$envVal) !== '') {
                $candidates[] = (string)$envVal;
            }
            if (!empty($_SERVER[$key])) {
                $candidates[] = (string)$_SERVER[$key];
            }
        }

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

        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $envPaths[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/.env';
        }

        foreach ($envPaths as $envPath) {
            foreach ($candidateKeys as $key) {
                $value = ers_read_env_value_from_file($envPath, $key);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}

if (!function_exists('ers_resolve_gemini_url')) {
    function ers_resolve_gemini_url() {
        $defaultUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
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
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-1.5-flash',
        ];
        foreach ($fallbackModels as $model) {
            $urls[] = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
        }

        return array_values(array_unique($urls));
    }
}

if (!function_exists('ers_gemini_model_from_url')) {
    function ers_gemini_model_from_url($url) {
        if (preg_match('#/models/([^:/?]+)#', (string)$url, $matches)) {
            return (string)$matches[1];
        }
        return 'configured model';
    }
}

if (!function_exists('ers_gemini_cache_dir')) {
    function ers_gemini_cache_dir() {
        return dirname(__DIR__) . '/data/cache/gemini';
    }
}

if (!function_exists('ers_gemini_cache_path')) {
    function ers_gemini_cache_path($prompt) {
        return ers_gemini_cache_dir() . '/' . hash('sha256', (string)$prompt) . '.json';
    }
}

if (!function_exists('ers_gemini_read_cached_response')) {
    function ers_gemini_read_cached_response($prompt, $maxAgeSeconds = 900) {
        $cachePath = ers_gemini_cache_path($prompt);
        if (!is_file($cachePath)) {
            return '';
        }

        $raw = @file_get_contents($cachePath);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            return '';
        }

        $createdAt = (int)($payload['created_at'] ?? 0);
        if ($maxAgeSeconds > 0 && ($createdAt <= 0 || (time() - $createdAt) > $maxAgeSeconds)) {
            return '';
        }

        return trim((string)($payload['text'] ?? ''));
    }
}

if (!function_exists('ers_gemini_write_cached_response')) {
    function ers_gemini_write_cached_response($prompt, $text) {
        $text = trim((string)$text);
        if ($text === '') {
            return;
        }

        $cacheDir = ers_gemini_cache_dir();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            return;
        }

        $payload = [
            'created_at' => time(),
            'text' => $text,
        ];
        @file_put_contents(ers_gemini_cache_path($prompt), json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('ers_should_retry_gemini_with_fallback_model')) {
    function ers_should_retry_gemini_with_fallback_model($httpCode, $apiError) {
        $apiError = strtolower(trim((string)$apiError));

        if (
            strpos($apiError, 'api_key_invalid') !== false ||
            strpos($apiError, 'api key not valid') !== false ||
            strpos($apiError, 'invalid api key') !== false ||
            strpos($apiError, 'leaked') !== false
        ) {
            return false;
        }

        if ((int)$httpCode === 429 || (int)$httpCode >= 500) {
            return true;
        }

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

    $cachedText = ers_gemini_read_cached_response($prompt, 900);
    if ($cachedText !== '') {
        return $cachedText;
    }

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
    $lastHttpCode = 0;
    $lastApiError = '';
    $lastCurlError = '';
    $lastModel = 'configured model';
    $urls = ers_gemini_url_candidates($apiBaseUrl);

    foreach ($urls as $urlIndex => $candidateUrl) {
        $lastModel = ers_gemini_model_from_url($candidateUrl);
        $attempts = 2;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $apiUrl = $candidateUrl . '?key=' . urlencode($apiKey);
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false && (strpos(strtolower((string)$curlError), 'certificate') !== false || strpos(strtolower((string)$curlError), 'ssl') !== false)) {
                $chRetry = curl_init($apiUrl);
                curl_setopt($chRetry, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chRetry, CURLOPT_POST, true);
                curl_setopt($chRetry, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($chRetry, CURLOPT_CONNECTTIMEOUT, 4);
                curl_setopt($chRetry, CURLOPT_TIMEOUT, 12);
                curl_setopt($chRetry, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chRetry, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($chRetry, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                $response = curl_exec($chRetry);
                $httpCode = (int)curl_getinfo($chRetry, CURLINFO_HTTP_CODE);
                $curlError = curl_error($chRetry);
                curl_close($chRetry);
            }

            $lastHttpCode = $httpCode;
            $lastCurlError = $response === false ? (string)$curlError : '';
            $responseData = $response !== false ? json_decode((string)$response, true) : null;
            $apiError = '';
            if (is_array($responseData) && isset($responseData['error']['message'])) {
                $apiError = trim((string)$responseData['error']['message']);
            }
            $lastApiError = $apiError;

            if ($response === false) {
                $msg = $lastCurlError !== '' ? $lastCurlError : 'Unknown cURL error';
                error_log('Gemini API request failed for ' . $lastModel . ': ' . $msg);
            } elseif ($httpCode === 200) {
                if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                    $text = ers_clean_ai_text($responseData['candidates'][0]['content']['parts'][0]['text']);
                    ers_gemini_write_cached_response($prompt, $text);
                    return $text;
                }

                setGeminiLastError('Gemini returned an empty response.');
                error_log('Gemini API returned HTTP 200 with no candidate text for ' . $lastModel . '.');
                return null;
            } else {
                if ($apiError !== '') {
                    error_log('Gemini API error HTTP ' . $httpCode . ' for ' . $lastModel . ': ' . $apiError);
                } else {
                    error_log('Gemini API error HTTP ' . $httpCode . ' for ' . $lastModel . '.');
                }
            }

            if (!ers_should_retry_gemini_with_fallback_model($httpCode, $apiError)) {
                break 2;
            }

            if ($attempt < $attempts) {
                usleep(random_int(350000, 850000));
            }
        }

        if ($urlIndex < count($urls) - 1) {
            error_log('Gemini retrying with fallback model after ' . $lastModel . ' failed.');
        }
    }

    $staleText = ers_gemini_read_cached_response($prompt, 86400);
    if ($staleText !== '') {
        setGeminiLastError('Gemini is temporarily unavailable. Showing the latest cached response.');
        error_log('Gemini API failed; served cached response.');
        return $staleText;
    }

    if ($lastCurlError !== '') {
        $friendly = 'Gemini request failed: ' . $lastCurlError;
    } elseif ($lastHttpCode === 429) {
        $friendly = 'Gemini quota exceeded. Check API plan/billing or wait then retry.';
    } elseif ($lastHttpCode === 401 || $lastHttpCode === 403) {
        $friendly = 'Gemini key is invalid or not authorized for this API/project.';
    } elseif ($lastHttpCode >= 500) {
        $friendly = 'Gemini service is temporarily unavailable after retrying fallback models. Please retry.';
    } else {
        $friendly = 'Gemini request failed (HTTP ' . $lastHttpCode . ').';
    }

    if ($lastApiError !== '' && !in_array($lastHttpCode, [401, 403, 429, 503], true)) {
        $friendly .= ' ' . $lastApiError;
    }
    setGeminiLastError($friendly);

    return null;
}

if (!function_exists('ers_gemini_generate_text')) {
    function ers_gemini_generate_text($prompt, $temperature = 0.2) {
        return callGeminiAPI($prompt);
    }
}

/**
 * Fallback generator for incident analysis when Gemini API is unavailable
 */
if (!function_exists('ers_fallback_incident_analysis')) {
    function ers_fallback_incident_analysis($incidentData) {
        $type = trim((string)($incidentData['type'] ?? 'Emergency Incident'));
        if ($type === '' || strtolower($type) === 'unknown') {
            $type = 'Emergency Incident';
        }
        $location = trim((string)($incidentData['location'] ?? 'Location pending verification'));
        if ($location === '' || strtolower($location) === 'unknown') {
            $location = 'Reported incident area';
        }
        $description = trim((string)($incidentData['description'] ?? ''));
        $severity = strtoupper(trim((string)($incidentData['severity'] ?? 'MEDIUM')));
        if ($severity === '' || $severity === 'UNKNOWN') {
            $severity = 'MEDIUM';
        }

        $typeLower = strtolower($type);

        if (strpos($typeLower, 'fire') !== false) {
            $summary = "$severity $type reported at $location." . ($description !== '' && $description !== 'No description' ? " Details: $description." : ' Active fire hazard requiring immediate suppression.');
            $topAction = "Dispatch nearest engine company and ladder unit; notify fire station commander with priority siren.";
            $nextAction = "Establish water relay / hydrant connection; cordon off 100m danger perimeter to control bystanders.";
            $safetyNote = "Beware of heavy smoke inhalation, structural compromise, and energized electrical distribution wires.";
            $resourceGap = "Deploy auxiliary tanker and emergency medical standby team for victim extraction.";
        } elseif (strpos($typeLower, 'medic') !== false || strpos($typeLower, 'health') !== false || strpos($typeLower, 'cardiac') !== false) {
            $summary = "$severity Medical Emergency at $location." . ($description !== '' && $description !== 'No description' ? " Details: $description." : ' Immediate patient triage and life support required.');
            $topAction = "Dispatch Advanced Life Support (ALS) ambulance unit with EMTs immediately.";
            $nextAction = "Instruct reporting party on basic first-aid/CPR; coordinate destination receiving hospital for trauma intake.";
            $safetyNote = "Ensure responder PPE compliance, scene security, and safe patient transfer passage.";
            $resourceGap = "Prepare backup BLS transport unit if multiple casualties are identified upon arrival.";
        } elseif (strpos($typeLower, 'vehic') !== false || strpos($typeLower, 'accident') !== false || strpos($typeLower, 'collision') !== false || strpos($typeLower, 'traffic') !== false) {
            $summary = "$severity Vehicular Accident at $location." . ($description !== '' && $description !== 'No description' ? " Details: $description." : ' Road collision with potential occupant entrapment.');
            $topAction = "Deploy rescue squad with extrication tools and emergency ambulance to scene.";
            $nextAction = "Coordinate traffic management with local traffic bureau; divert oncoming vehicular flow safely.";
            $safetyNote = "Check for fuel leaks, shattered glass, battery fire hazards, and unstable vehicle positioning.";
            $resourceGap = "Request wrecker/towing service and hazmat spill mitigation absorbent kit.";
        } elseif (strpos($typeLower, 'flood') !== false || strpos($typeLower, 'water') !== false || strpos($typeLower, 'typhoon') !== false) {
            $summary = "$severity Flooding / Water Hazard reported at $location." . ($description !== '' && $description !== 'No description' ? " Details: $description." : ' Rising water levels impacting residential areas.');
            $topAction = "Dispatch water search-and-rescue team with rubber boats and flotation gear.";
            $nextAction = "Identify elevated evacuation centers; mobilize barangay disaster response teams for resident relocation.";
            $safetyNote = "Beware of rapid underwater currents, submerged debris, open manholes, and electrocution hazards.";
            $resourceGap = "Procure additional life vests, rescue ropes, and high-clearance utility transport trucks.";
        } else {
            $summary = "$severity incident ($type) reported at $location." . ($description !== '' && $description !== 'No description' ? " Details: $description." : ' Operational record logged and awaiting tactical action.');
            $topAction = in_array($severity, ['CRITICAL', 'HIGH', 'URGENT'], true)
                ? "Dispatch primary rapid response unit and establish immediate communication with on-scene caller."
                : "Assign available patrol unit to verify situation and report initial situational assessment.";
            $nextAction = "Coordinate with territorial barangay outpost and stage reserve units along major arterial roads.";
            $safetyNote = "Maintain safe operational distance until scene security and ambient hazards are confirmed.";
            $resourceGap = "Monitor unit availability and stand by specialized support assets as situational needs develop.";
        }

        return implode("\n", [
            "**Situation Summary:** $summary",
            "**Top Action Now:** $topAction",
            "**Next Action:** $nextAction",
            "**Safety Note:** $safetyNote",
            "**Resource Allocation:** $resourceGap",
        ]);
    }
}

/**
 * Fallback generator for dispatch recommendations when Gemini API is unavailable
 */
if (!function_exists('ers_fallback_dispatch_recommendations')) {
    function ers_fallback_dispatch_recommendations($dispatchData) {
        $active = (int)($dispatchData['active_incidents'] ?? 0);
        $avail = (int)($dispatchData['available_units'] ?? 0);
        $pending = (int)($dispatchData['pending_calls'] ?? 0);
        $currIncident = trim((string)($dispatchData['current_incident'] ?? 'None'));

        if ($avail === 0 && $active > 0) {
            $load = "High operational demand: All frontline units deployed with $active active incidents and $pending calls in queue.";
        } elseif ($active > 5 || $pending > 3) {
            $load = "Elevated dispatch load: $active active incidents underway with $avail available units standing by.";
        } else {
            $load = "Standard operational load: $active active incidents, $avail available units, and $pending pending calls.";
        }

        if ($pending > 0) {
            $priority = "Prioritize unassigned pending calls ($pending awaiting dispatch). Assign closest available units immediately.";
        } elseif ($active > 0) {
            $priority = "Maintain continuous radio check with dispatched units; monitor incident containment and status updates.";
        } else {
            $priority = "All incident queues clear. Maintain proactive standby posture and fleet readiness across sectors.";
        }

        if ($avail === 0) {
            $allocation = "Zero units in reserve: Recall units completing on-scene reports; place adjacent station units on mutual aid alert.";
        } elseif ($avail <= 2) {
            $allocation = "Reserve capacity critical ($avail remaining). Reserve remaining unit strictly for Critical emergencies.";
        } else {
            $allocation = "Sufficient reserve ($avail units available). Pre-position units near high-frequency commercial and transit corridors.";
        }

        if ($currIncident !== '' && $currIncident !== 'None' && $currIncident !== 'No active incident context') {
            $queue = "Active focus on priority incident: $currIncident. Ensure tactical updates are logged promptly.";
        } elseif ($pending > 0) {
            $queue = "Review pending queue timestamps; ensure critical callers are updated with ETA to prevent secondary duplicate calls.";
        } else {
            $queue = "Queue clear. Conduct routine dispatch log audit and update responder duty rosters.";
        }

        if ($avail === 0 && $pending > 0) {
            $escalation = "Escalation active: Request emergency mutual aid and alert shift supervisor for inter-agency coordination.";
        } else {
            $escalation = "Escalation threshold: Activate Level 2 mutual aid protocol if pending calls exceed available units by 2 or more.";
        }

        return implode("\n", [
            "**Operational Assessment:** $load",
            "**Dispatch Priority:** $priority",
            "**Unit Allocation:** $allocation",
            "**Queue Action:** $queue",
            "**Escalation Protocol:** $escalation",
        ]);
    }
}

/**
 * Fallback generator for resource recommendations when Gemini API is unavailable
 */
if (!function_exists('ers_fallback_resource_gap_recommendations')) {
    function ers_fallback_resource_gap_recommendations($resourceData) {
        $vTot = (int)($resourceData['vehicles_total'] ?? 0);
        $vAvail = (int)($resourceData['vehicles_available'] ?? 0);
        $vInUse = (int)($resourceData['vehicles_inuse'] ?? 0);

        $pTot = (int)($resourceData['personnel_total'] ?? 0);
        $pAvail = (int)($resourceData['personnel_available'] ?? 0);

        $eTot = (int)($resourceData['equipment_total'] ?? 0);
        $eAvail = (int)($resourceData['equipment_available'] ?? 0);

        $active = (int)($resourceData['active_incidents'] ?? 0);

        if ($vAvail === 0 && $vTot > 0) {
            $primary = "Severe Vehicle Deficit: 0 of $vTot response vehicles available with $active active incidents underway.";
        } elseif ($pAvail === 0 && $pTot > 0) {
            $primary = "Critical Personnel Shortage: 0 active responders currently available in reserve pool.";
        } elseif ($eAvail === 0 && $eTot > 0) {
            $primary = "Equipment Depletion: All registered equipment items are deployed or offline.";
        } else {
            $primary = "Fleet Status: $vAvail of $vTot vehicles and $pAvail of $pTot personnel available for dispatch.";
        }

        if ($pAvail < 3 && $pTot > 0) {
            $secondary = "Personnel Capacity: Available staff ($pAvail) approaching minimum operational threshold for concurrent calls.";
        } elseif ($vAvail < 2 && $vTot > 0) {
            $secondary = "Vehicle Reserve: Only $vAvail vehicle(s) on standby; high risk of dispatch queue delays.";
        } else {
            $secondary = "Equipment Inventory: $eAvail equipment units ready. Monitor specialized rescue kits.";
        }

        if ($vInUse > 0) {
            $action = "Accelerate on-scene demobilization for completed calls; clear units back to available status immediately.";
        } else {
            $action = "Conduct readiness check on standby vehicles and restock frontline emergency medical equipment.";
        }

        if ($vAvail === 0) {
            $request = "Request immediate mutual aid vehicle support from neighboring district stations.";
        } elseif ($pAvail < 2) {
            $request = "Issue recall alert for off-duty responders or transition reserve shift to active duty.";
        } else {
            $request = "Replenish frontline consumables, trauma kits, and fuel reserves for deployed apparatus.";
        }

        if ($vAvail === 0 || $pAvail === 0) {
            $risk = "High risk of delayed response times for concurrent critical calls if existing units remain engaged.";
        } else {
            $risk = "Moderate risk: Sudden surge in multi-alarm incidents could deplete remaining reserves within 30 minutes.";
        }

        $priorityOrder = "1) Expedite clearance of completed dispatches, 2) Mobilize reserve roster, 3) Stage mutual aid coverage.";

        return implode("\n", [
            "**Primary Resource Shortage:** $primary",
            "**Secondary Resource Gap:** $secondary",
            "**Immediate Reallocation:** $action",
            "**Resource Request Action:** $request",
            "**Operational Risk:** $risk",
            "**Priority Order:** $priorityOrder",
        ]);
    }
}

/**
 * Fallback generator for report insights when Gemini API is unavailable
 */
if (!function_exists('ers_fallback_report_insights')) {
    function ers_fallback_report_insights($reportData) {
        $totalIncidents = (int)($reportData['total_incidents'] ?? 0);
        $resolved = (int)($reportData['resolved_incidents_by_period_end'] ?? ($reportData['resolved_incidents'] ?? 0));
        $respTime = trim((string)($reportData['avg_dispatch_to_scene'] ?? ($reportData['avg_response_time'] ?? 'N/A')));
        $utilization = trim((string)($reportData['current_unit_utilization'] ?? ($reportData['resource_utilization'] ?? 'N/A')));

        return implode("\n", [
            "**Overall Performance:** System processed $totalIncidents total incidents across the evaluated period, achieving $resolved resolutions.",
            "**Positive Signal:** Dispatch response and triage workflow maintained consistent operational continuity.",
            "**Main Operational Risk:** Peak incident hours create elevated workload; monitor response times closely ($respTime).",
            "**Resource Focus:** Optimize unit distribution in high-density sectors to sustain balanced utilization ($utilization).",
            "**Immediate Improvement:** Streamline dispatch acknowledgement time to shorten the interval between call intake and unit enroute.",
            "**Next 24h Priority:** Maintain fleet preventative checks and verify reserve personnel availability for peak demand periods.",
        ]);
    }
}

/**
 * Generate AI-powered incident analysis
 * @param array $incidentData Incident details
 * @return string|null AI analysis
 */
function analyzeIncident($incidentData) {
    $apiKey = trim((string)ers_resolve_gemini_key());
    if ($apiKey !== '') {
        $prompt = "You are the ERS incident assistant. Use only the given data.\n";
        $prompt .= "Incident Data:\n";
        $prompt .= "Type: " . ($incidentData['type'] ?? 'Unknown') . "\n";
        $prompt .= "Location: " . ($incidentData['location'] ?? 'Unknown') . "\n";
        $prompt .= "Description: " . ($incidentData['description'] ?? 'No description') . "\n";
        $prompt .= "Severity/Priority: " . ($incidentData['severity'] ?? 'Unknown') . "\n\n";
        $prompt .= "Format output with these exact 5 lines:\n";
        $prompt .= "**Situation Summary:** <summary>\n";
        $prompt .= "**Top Action Now:** <top action>\n";
        $prompt .= "**Next Action:** <next action>\n";
        $prompt .= "**Safety Note:** <safety note>\n";
        $prompt .= "**Resource Allocation:** <resource gap/allocation>";

        $res = callGeminiAPI($prompt);
        if ($res !== null && trim($res) !== '') {
            return $res;
        }
    }

    return ers_fallback_incident_analysis($incidentData);
}

/**
 * Generate AI insights for reports
 * @param array $reportData Report metrics and data
 * @return string|null AI insights
 */
function generateReportInsights($reportData) {
    $apiKey = trim((string)ers_resolve_gemini_key());
    if ($apiKey !== '') {
        $prompt = "You are the ERS reporting assistant. Use only provided metrics.\n\n";
        $prompt .= "Metrics:\n";
        $prompt .= "Total Incidents: " . ($reportData['total_incidents'] ?? 0) . "\n";
        $prompt .= "Average Response Time: " . ($reportData['avg_response_time'] ?? ($reportData['avg_dispatch_to_scene'] ?? 'Unknown')) . "\n";
        $prompt .= "Resource Utilization: " . ($reportData['resource_utilization'] ?? ($reportData['current_unit_utilization'] ?? 'Unknown')) . "\n";
        $prompt .= "Active Responders: " . ($reportData['active_responders'] ?? ($reportData['active_responder_accounts'] ?? 0)) . "\n";
        $prompt .= "Resolved Incidents: " . ($reportData['resolved_incidents'] ?? ($reportData['resolved_incidents_by_period_end'] ?? 0)) . "\n";
        $prompt .= "Success Rate: " . ($reportData['success_rate'] ?? ($reportData['resolution_rate'] ?? 'Unknown')) . "\n\n";
        $prompt .= "Format output with these exact 6 lines:\n";
        $prompt .= "**Overall Performance:** <performance>\n";
        $prompt .= "**Positive Signal:** <positive signal>\n";
        $prompt .= "**Main Operational Risk:** <main risk>\n";
        $prompt .= "**Resource Focus:** <resource focus>\n";
        $prompt .= "**Immediate Improvement:** <immediate improvement>\n";
        $prompt .= "**Next 24h Priority:** <next 24h priority>";

        $res = callGeminiAPI($prompt);
        if ($res !== null && trim($res) !== '') {
            return $res;
        }
    }

    return ers_fallback_report_insights($reportData);
}

/**
 * AI-assisted dispatch recommendations
 * @param array $dispatchData Current dispatch situation
 * @return string|null AI recommendations
 */
function getDispatchRecommendations($dispatchData) {
    $apiKey = trim((string)ers_resolve_gemini_key());
    if ($apiKey !== '') {
        $prompt = "You are the ERS dispatch assistant. Use only provided values.\n\n";
        $prompt .= "Dispatch Snapshot:\n";
        $prompt .= "Active Incidents: " . ($dispatchData['active_incidents'] ?? 0) . "\n";
        $prompt .= "Available Units: " . ($dispatchData['available_units'] ?? 0) . "\n";
        $prompt .= "Pending Calls: " . ($dispatchData['pending_calls'] ?? 0) . "\n";
        $prompt .= "Current Incident: " . ($dispatchData['current_incident'] ?? 'None') . "\n\n";
        $prompt .= "Format output with these exact 5 lines:\n";
        $prompt .= "**Operational Assessment:** <load assessment>\n";
        $prompt .= "**Dispatch Priority:** <dispatch priority>\n";
        $prompt .= "**Unit Allocation:** <unit allocation suggestion>\n";
        $prompt .= "**Queue Action:** <queue/backlog action>\n";
        $prompt .= "**Escalation Protocol:** <escalation trigger>";

        $res = callGeminiAPI($prompt);
        if ($res !== null && trim($res) !== '') {
            return $res;
        }
    }

    return ers_fallback_dispatch_recommendations($dispatchData);
}

/**
 * Generate predictive resource needs
 * @param array $resourceData Snapshot data
 * @return string|null AI predictions
 */
function getResourceGapRecommendations($resourceData) {
    $apiKey = trim((string)ers_resolve_gemini_key());
    if ($apiKey !== '') {
        $prompt = "You are the ERS resource recommendation assistant. Analyze shortages using only the data below.\n\n";
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
        $prompt .= "Format output with these exact 6 lines:\n";
        $prompt .= "**Primary Resource Shortage:** <biggest current shortage>\n";
        $prompt .= "**Secondary Resource Gap:** <secondary shortage>\n";
        $prompt .= "**Immediate Reallocation:** <immediate reallocation action>\n";
        $prompt .= "**Resource Request Action:** <what to request now>\n";
        $prompt .= "**Operational Risk:** <risk if no action>\n";
        $prompt .= "**Priority Order:** <priority order 1-3>";

        $res = callGeminiAPI($prompt);
        if ($res !== null && trim($res) !== '') {
            return $res;
        }
    }

    return ers_fallback_resource_gap_recommendations($resourceData);
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
 * Log activity helper fallback
 */
if (!function_exists('log_activity')) {
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
}
