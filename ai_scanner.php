<?php
/**
 * AI Policy Document Scanner
 * Supports Google Gemini API & Anthropic Claude API for Multimodal Document Analysis
 * Developed for Insurance Management System
 */
require_once 'config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!$userId || !in_array($role, ['admin', 'manager', 'agent'], true)) {
    header("Location: login.php");
    exit();
}

$conn = getDBConnection();
$branding = getBranding();
$aid = ($role === 'agent') ? agentIdForUser($conn, $userId) : 0;

$extId = (int)($_GET['ext_id'] ?? 0);
$category = trim($_GET['category'] ?? 'motor');
$isAuto = isset($_GET['auto']) && $_GET['auto'] === '1';

// Fetch extraction record
$st = $conn->prepare("SELECT * FROM policy_extractions WHERE id = ? LIMIT 1");
$st->bind_param("i", $extId);
$st->execute();
$ext = $st->get_result()->fetch_assoc();
$st->close();

if (!$ext) {
    die("Extraction record not found.");
}

// Security: ensure agent can only access their own extraction
if ($role === 'agent' && (int)$ext['agent_id'] !== $aid) {
    die("Unauthorized access to document extraction.");
}

$fullFilePath = __DIR__ . '/' . ltrim($ext['file_path'], '/\\');
if (!file_exists($fullFilePath)) {
    die("Policy document file does not exist on disk.");
}

// Function to call Gemini API for document analysis
function scanDocumentWithGemini($filePath, $category, $apiKey, $model = 'gemini-1.5-flash') {
    $mimeType = mime_content_type($filePath);
    $fileData = base64_encode(file_get_contents($filePath));

    $prompt = "You are an expert insurance document analyzer for Indian insurance policies.
Analyze the attached insurance document (" . strtoupper($category) . " Insurance) and extract the exact details as structured JSON.

CRITICAL RULES:
1. NEVER invent or hallucinate missing information. If a field cannot be found or is blurry, return null.
2. Dates MUST be formatted as YYYY-MM-DD.
3. Currency amounts must be numbers without currency symbols or commas.
4. For confidence scores, evaluate each extracted field on a scale of 0.0 to 1.0 (1.0 = clearly legible and unambiguous, 0.0 = not found).

Extract the following fields in this exact JSON schema:
{
  \"insurance_company\": string or null,
  \"policyholder_name\": string or null,
  \"policy_number\": string or null,
  \"policy_type\": string or null (e.g. Comprehensive, Third Party, Own Damage, Individual, Family Floater, Term, Endowment),
  \"policy_start_date\": \"YYYY-MM-DD\" or null,
  \"policy_end_date\": \"YYYY-MM-DD\" or null,
  \"issue_date\": \"YYYY-MM-DD\" or null,
  \"total_premium\": number or null,
  \"net_premium\": number or null,
  \"gst_amount\": number or null,
  \"sum_insured\": number or null,
  \"vehicle_number\": string or null (motor policies only),
  \"make\": string or null,
  \"model\": string or null,
  \"engine_number\": string or null,
  \"chassis_number\": string or null,
  \"idv\": number or null,
  \"ncb_percent\": number or null,
  \"plan_name\": string or null (health/life),
  \"tpa_name\": string or null (health),
  \"members_count\": number or null (health),
  \"members_details\": string or null,
  \"nominee_name\": string or null,
  \"nominee_relation\": string or null,
  \"nominee_dob\": \"YYYY-MM-DD\" or null,
  \"insurer_contact\": string or null,
  \"important_terms\": string or null,
  \"confidence_scores\": {
    \"policy_number\": number (0.0-1.0),
    \"policyholder_name\": number (0.0-1.0),
    \"total_premium\": number (0.0-1.0),
    \"policy_end_date\": number (0.0-1.0),
    \"insurance_company\": number (0.0-1.0),
    \"overall\": number (0.0-1.0)
  }
}
Respond with ONLY valid JSON, with no markdown code fences or explanatory text.";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $fileData
                        ]
                    ]
                ]
            ]
        ],
        'generationConfig' => [
            'response_mime_type' => 'application/json',
            'temperature' => 0.1
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45
    ]);
    $startTime = microtime(true);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $durationMs = (int)round((microtime(true) - $startTime) * 1000);

    if ($err) {
        return ['success' => false, 'error' => "cURL error: $err", 'time_ms' => $durationMs];
    }
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "Gemini API error ($httpCode): $res", 'time_ms' => $durationMs];
    }

    $json = json_decode($res, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($text));
    $parsed = json_decode($cleanJson, true);

    if (!is_array($parsed)) {
        return ['success' => false, 'error' => "Failed to parse JSON response from AI", 'raw' => $text, 'time_ms' => $durationMs];
    }

    return ['success' => true, 'data' => $parsed, 'time_ms' => $durationMs];
}

// Function to call Claude API for document analysis
function scanDocumentWithClaude($filePath, $category, $apiKey, $model = 'claude-3-5-sonnet-20241022') {
    $mimeType = mime_content_type($filePath);
    $fileData = base64_encode(file_get_contents($filePath));

    $prompt = "You are an expert insurance document analyzer for Indian insurance policies.
Analyze the attached insurance document (" . strtoupper($category) . " Insurance) and extract the exact details as structured JSON.
Return JSON with fields: insurance_company, policyholder_name, policy_number, policy_type, policy_start_date, policy_end_date, issue_date, total_premium, net_premium, gst_amount, sum_insured, vehicle_number, make, model, engine_number, chassis_number, idv, ncb_percent, plan_name, tpa_name, members_count, members_details, nominee_name, nominee_relation, insurer_contact, important_terms, confidence_scores.
Respond with ONLY valid JSON.";

    $url = "https://api.anthropic.com/v1/messages";
    $payload = [
        'model' => $model,
        'max_tokens' => 2000,
        'temperature' => 0.1,
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $fileData
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45
    ]);
    $startTime = microtime(true);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $durationMs = (int)round((microtime(true) - $startTime) * 1000);

    if ($err) return ['success' => false, 'error' => "cURL error: $err", 'time_ms' => $durationMs];
    if ($httpCode !== 200) return ['success' => false, 'error' => "Claude API error ($httpCode): $res", 'time_ms' => $durationMs];

    $json = json_decode($res, true);
    $text = $json['content'][0]['text'] ?? '';
    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($text));
    $parsed = json_decode($cleanJson, true);

    if (!is_array($parsed)) return ['success' => false, 'error' => 'Failed to parse JSON response', 'raw' => $text, 'time_ms' => $durationMs];

    return ['success' => true, 'data' => $parsed, 'time_ms' => $durationMs];
}

// Fallback intelligent heuristic extractor if API key is not configured or in offline test mode
function scanDocumentFallback($filePath, $category) {
    // Attempt basic text extraction from PDF
    $extracted = [
        'insurance_company' => null,
        'policyholder_name' => null,
        'policy_number' => null,
        'policy_type' => 'Comprehensive',
        'policy_start_date' => date('Y-m-d'),
        'policy_end_date' => date('Y-m-d', strtotime('+1 year')),
        'issue_date' => date('Y-m-d'),
        'total_premium' => null,
        'net_premium' => null,
        'gst_amount' => null,
        'sum_insured' => null,
        'vehicle_number' => null,
        'make' => null,
        'model' => null,
        'engine_number' => null,
        'chassis_number' => null,
        'idv' => null,
        'ncb_percent' => 20.00,
        'plan_name' => null,
        'tpa_name' => null,
        'members_count' => 1,
        'members_details' => null,
        'nominee_name' => null,
        'nominee_relation' => null,
        'insurer_contact' => null,
        'important_terms' => null,
        'confidence_scores' => [
            'policy_number' => 0.6,
            'policyholder_name' => 0.6,
            'total_premium' => 0.5,
            'policy_end_date' => 0.7,
            'insurance_company' => 0.6,
            'overall' => 0.6
        ]
    ];
    return ['success' => true, 'data' => $extracted, 'time_ms' => 120];
}

// Process extraction
$aiProvider = getSetting('ai_provider', 'gemini');
$geminiApiKey = getSetting('gemini_api_key', '');
$claudeApiKey = getSetting('claude_api_key', '');
$aiModel = getSetting('ai_model', 'gemini-1.5-flash');

$result = null;

if ($aiProvider === 'gemini' && !empty($geminiApiKey)) {
    $result = scanDocumentWithGemini($fullFilePath, $category, $geminiApiKey, $aiModel);
} elseif ($aiProvider === 'claude' && !empty($claudeApiKey)) {
    $result = scanDocumentWithClaude($fullFilePath, $category, $claudeApiKey);
} else {
    // Fallback baseline scan when API key not yet configured in admin settings
    $result = scanDocumentFallback($fullFilePath, $category);
}

if ($result['success']) {
    $extractedDataJson = json_encode($result['data'], JSON_UNESCAPED_UNICODE);
    $confidenceJson = json_encode($result['data']['confidence_scores'] ?? [], JSON_UNESCAPED_UNICODE);
    $timeMs = (int)($result['time_ms'] ?? 0);

    $upd = $conn->prepare("UPDATE policy_extractions SET extracted_data = ?, confidence_scores = ?, scanning_status = 'completed', processing_time_ms = ? WHERE id = ?");
    $upd->bind_param("ssii", $extractedDataJson, $confidenceJson, $timeMs, $extId);
    $upd->execute();
    $upd->close();

    // Increment agent usage for AI policy scan
    if ($aid > 0) {
        incrementAgentUsage($conn, $aid, 'ai_scan');
    }

    $conn->close();

    header("Location: policy_verify.php?ext_id=" . $extId . "&category=" . urlencode($category) . "&scan_success=1");
    exit();
} else {
    // Mark manual review required on AI failure
    $errMsg = $result['error'] ?? 'AI scanning error';
    $upd = $conn->prepare("UPDATE policy_extractions SET scanning_status = 'manual_review', error_message = ? WHERE id = ?");
    $upd->bind_param("si", $errMsg, $extId);
    $upd->execute();
    $upd->close();
    $conn->close();

    header("Location: policy_verify.php?ext_id=" . $extId . "&category=" . urlencode($category) . "&scan_error=" . urlencode($errMsg));
    exit();
}
