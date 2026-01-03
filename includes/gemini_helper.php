<?php
/**
 * Gemini AI Helper Functions
 * Utility functions for integrating Gemini AI into the ERS system
 */

include 'config.php';

/**
 * Make a request to Gemini AI API
 * @param string $prompt The prompt to send to AI
 * @return string|null The AI response or null on error
 */
function callGeminiAPI($prompt) {
    $apiUrl = GEMINI_API_URL . '?key=' . GEMINI_API_KEY;

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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return $responseData['candidates'][0]['content']['parts'][0]['text'];
        }
    }

    return null;
}

/**
 * Generate AI-powered incident analysis
 * @param array $incidentData Incident details
 * @return string AI analysis
 */
function analyzeIncident($incidentData) {
    $prompt = "As an emergency response AI assistant, analyze this incident and provide recommendations:\n\n";
    $prompt .= "Type: " . ($incidentData['type'] ?? 'Unknown') . "\n";
    $prompt .= "Location: " . ($incidentData['location'] ?? 'Unknown') . "\n";
    $prompt .= "Description: " . ($incidentData['description'] ?? 'No description') . "\n";
    $prompt .= "Severity: " . ($incidentData['severity'] ?? 'Unknown') . "\n\n";
    $prompt .= "Provide: 1) Risk assessment, 2) Recommended response actions, 3) Resource needs, 4) Safety considerations";

    return callGeminiAPI($prompt);
}

/**
 * Generate AI insights for reports
 * @param array $reportData Report metrics and data
 * @return string AI insights
 */
function generateReportInsights($reportData) {
    $prompt = "Analyze these emergency response metrics and provide insights:\n\n";
    $prompt .= "Total Incidents: " . ($reportData['total_incidents'] ?? 0) . "\n";
    $prompt .= "Average Response Time: " . ($reportData['avg_response_time'] ?? 'Unknown') . "\n";
    $prompt .= "Resource Utilization: " . ($reportData['resource_utilization'] ?? 'Unknown') . "\n";
    $prompt .= "Active Responders: " . ($reportData['active_responders'] ?? 0) . "\n\n";
    $prompt .= "Provide: 1) Performance analysis, 2) Trends identification, 3) Improvement recommendations, 4) Predictive insights";

    return callGeminiAPI($prompt);
}

/**
 * AI-assisted dispatch recommendations
 * @param array $dispatchData Current dispatch situation
 * @return string AI recommendations
 */
function getDispatchRecommendations($dispatchData) {
    $prompt = "As an emergency dispatch AI assistant, provide recommendations for this situation:\n\n";
    $prompt .= "Active Incidents: " . ($dispatchData['active_incidents'] ?? 0) . "\n";
    $prompt .= "Available Units: " . ($dispatchData['available_units'] ?? 0) . "\n";
    $prompt .= "Pending Calls: " . ($dispatchData['pending_calls'] ?? 0) . "\n";
    $prompt .= "Current Incident: " . ($dispatchData['current_incident'] ?? 'None') . "\n\n";
    $prompt .= "Recommend: 1) Resource allocation, 2) Priority assignments, 3) Response strategies";

    return callGeminiAPI($prompt);
}

/**
 * Generate predictive resource needs
 * @param array $historicalData Historical incident data
 * @return string AI predictions
 */
function predictResourceNeeds($historicalData) {
    $prompt = "Based on historical emergency data, predict resource needs:\n\n";
    $prompt .= "Past Week Incidents: " . ($historicalData['weekly_incidents'] ?? 0) . "\n";
    $prompt .= "Peak Hours: " . ($historicalData['peak_hours'] ?? 'Unknown') . "\n";
    $prompt .= "Common Incident Types: " . ($historicalData['common_types'] ?? 'Unknown') . "\n";
    $prompt .= "Current Resources: " . ($historicalData['current_resources'] ?? 'Unknown') . "\n\n";
    $prompt .= "Predict: 1) Future demand, 2) Optimal resource allocation, 3) Preparedness recommendations";

    return callGeminiAPI($prompt);
}
?>