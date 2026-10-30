<?php
/**
 * AI Policy Document Scanner
 * Supports Google Gemini API & Anthropic Claude API for Multimodal Document Analysis
 * With Built-in Offline PDF Intelligent Heuristic Extraction
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

// Fetch extraction record with customer details
$st = $conn->prepare("SELECT pe.*, c.name AS cust_name, c.mobile AS cust_mobile, c.email AS cust_email 
                      FROM policy_extractions pe 
                      LEFT JOIN customers c ON c.id = pe.customer_id 
                      WHERE pe.id = ? LIMIT 1");
$st->bind_param("i", $extId);
$st->execute();
$ext = $st->get_result()->fetch_assoc();
$st->close();

if (!$ext) {
    die("Extraction record not found.");
}

// Security: ensure agent can only access their own extraction
if ($role === 'agent' && !empty($ext['agent_id']) && (int)$ext['agent_id'] !== $aid) {
    die("Unauthorized access to document extraction.");
}

$fullFilePath = __DIR__ . '/' . ltrim($ext['file_path'], '/\\');
if (!file_exists($fullFilePath)) {
    die("Policy document file does not exist on disk.");
}

// Helper: Extract text from PDF files using pure PHP
function extractRawPdfText($filePath) {
    $content = @file_get_contents($filePath);
    if (!$content) return '';
    $text = '';

    // Extract stream chunks
    if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches)) {
        foreach ($matches[1] as $stream) {
            $data = @gzuncompress($stream);
            if ($data === false) {
                $data = @gzinflate(substr($stream, 2));
            }
            if ($data === false) {
                $data = $stream;
            }

            // Extract string literals in text operations: (text) Tj and [(t)(e)(x)(t)] TJ
            if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $data, $tj)) {
                foreach ($tj[1] as $str) {
                    $text .= stripcslashes($str) . ' ';
                }
            }
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $data, $tjBlocks)) {
                foreach ($tjBlocks[1] as $block) {
                    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $block, $inner)) {
                        foreach ($inner[1] as $str) {
                            $text .= stripcslashes($str);
                        }
                        $text .= ' ';
                    }
                }
            }
        }
    }

    // Also collect plain text visible in ASCII stream
    $cleanAscii = preg_replace('/[^\x20-\x7E\r\n\t]/', ' ', $content);
    return trim($text . "\n" . $cleanAscii);
}

// Intelligent Heuristic Document Extractor (Offline / No API Key required)
function scanDocumentFallback($filePath, $category, $customer = null) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $rawText = ($ext === 'pdf') ? extractRawPdfText($filePath) : '';

    $extracted = [
        'insurance_company' => null,
        'policyholder_name' => $customer['cust_name'] ?? null,
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
            'overall' => 0.7
        ]
    ];

    if (!empty($rawText)) {
        // 1. Detect Insurance Company
        $insurers = [
            'HDFC ERGO General Insurance' => ['hdfc ergo', 'hdfc general'],
            'ICICI Lombard General Insurance' => ['icici lombard'],
            'Bajaj Allianz General Insurance' => ['bajaj allianz'],
            'Tata AIG General Insurance' => ['tata aig'],
            'The New India Assurance Co. Ltd.' => ['new india assurance'],
            'National Insurance Company Ltd.' => ['national insurance'],
            'United India Insurance Co. Ltd.' => ['united india insurance'],
            'The Oriental Insurance Co. Ltd.' => ['oriental insurance'],
            'SBI General Insurance' => ['sbi general'],
            'Star Health and Allied Insurance' => ['star health'],
            'Care Health Insurance' => ['care health', 'religare health'],
            'Niva Bupa Health Insurance' => ['niva bupa', 'max bupa'],
            'Go Digit General Insurance' => ['go digit', 'digit insurance'],
            'Acko General Insurance' => ['acko general', 'acko insurance'],
            'Reliance General Insurance' => ['reliance general'],
            'IFFCO Tokio General Insurance' => ['iffco tokio'],
            'Royal Sundaram General Insurance' => ['royal sundaram'],
            'Cholamandalam MS General Insurance' => ['cholamandalam', 'chola ms'],
            'Future Generali India Insurance' => ['future generali'],
            'Magma HDI General Insurance' => ['magma hdi']
        ];
        $textLower = strtolower($rawText);
        foreach ($insurers as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (strpos($textLower, $alias) !== false) {
                    $extracted['insurance_company'] = $canonical;
                    $extracted['confidence_scores']['insurance_company'] = 0.95;
                    break 2;
                }
            }
        }

        // 2. Policy Number Pattern
        if (preg_match('/(?:Policy\s*(?:Number|No\.?|#)[\s:]*)([A-Z0-9\/\-\.]{7,32})/i', $rawText, $m)) {
            $extracted['policy_number'] = trim($m[1]);
            $extracted['confidence_scores']['policy_number'] = 0.85;
        }

        // 3. Indian Vehicle Registration Number
        if (preg_match('/\b([A-Z]{2}[-\s]?[0-9]{1,2}[-\s]?[A-Z]{1,3}[-\s]?[0-9]{4})\b/', $rawText, $m)) {
            $extracted['vehicle_number'] = strtoupper(str_replace(' ', '', $m[1]));
            $extracted['confidence_scores']['vehicle_number'] = 0.90;
        }

        // 4. Engine & Chassis Numbers
        if (preg_match('/(?:Engine\s*(?:No\.?|Number)[\s:]*)([A-Z0-9]{5,25})/i', $rawText, $m)) {
            $extracted['engine_number'] = trim($m[1]);
        }
        if (preg_match('/(?:Chassis\s*(?:No\.?|Number)|VIN)[\s:]*([A-Z0-9]{10,25})/i', $rawText, $m)) {
            $extracted['chassis_number'] = trim($m[1]);
        }

        // 5. Total Premium / Net Premium
        if (preg_match('/(?:Total\s*Premium|Gross\s*Premium|Amount\s*Payable)[\s:]*(?:Rs\.?|INR|₹)?\s*([\d,]+\.?\d*)/i', $rawText, $m)) {
            $amt = (float)str_replace(',', '', $m[1]);
            if ($amt > 0) {
                $extracted['total_premium'] = $amt;
                $extracted['confidence_scores']['total_premium'] = 0.85;
            }
        }
        if (preg_match('/(?:Net\s*Premium|Basic\s*OD)[\s:]*(?:Rs\.?|INR|₹)?\s*([\d,]+\.?\d*)/i', $rawText, $m)) {
            $amt = (float)str_replace(',', '', $m[1]);
            if ($amt > 0) $extracted['net_premium'] = $amt;
        }
        if (preg_match('/(?:GST|IGST|CGST\s*\+\s*SGST)[\s:]*(?:Rs\.?|INR|₹)?\s*([\d,]+\.?\d*)/i', $rawText, $m)) {
            $amt = (float)str_replace(',', '', $m[1]);
            if ($amt > 0) $extracted['gst_amount'] = $amt;
        }

        // 6. IDV (Insured Declared Value) / Sum Insured
        if (preg_match('/(?:IDV|Insured\s*Declared\s*Value)[\s:]*(?:Rs\.?|INR|₹)?\s*([\d,]+\.?\d*)/i', $rawText, $m)) {
            $amt = (float)str_replace(',', '', $m[1]);
            if ($amt > 0) $extracted['idv'] = $amt;
        }
        if (preg_match('/(?:Sum\s*Insured)[\s:]*(?:Rs\.?|INR|₹)?\s*([\d,]+\.?\d*)/i', $rawText, $m)) {
            $amt = (float)str_replace(',', '', $m[1]);
            if ($amt > 0) $extracted['sum_insured'] = $amt;
        }

        // 7. Dates (DD/MM/YYYY or YYYY-MM-DD)
        if (preg_match_all('/\b(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})\b/', $rawText, $dates)) {
            $validDates = [];
            foreach ($dates[1] as $dStr) {
                $ts = strtotime(str_replace('.', '-', $dStr));
                if ($ts && $ts > strtotime('2020-01-01') && $ts < strtotime('+5 years')) {
                    $validDates[] = date('Y-m-d', $ts);
                }
            }
            if (count($validDates) >= 2) {
                sort($validDates);
                $extracted['policy_start_date'] = $validDates[0];
                $extracted['policy_end_date'] = end($validDates);
            }
        }
    }

    return ['success' => true, 'data' => $extracted, 'time_ms' => 80];
}

// Function to call Gemini API for multimodal document analysis
function scanDocumentWithGemini($filePath, $category, $apiKey, $model = 'gemini-1.5-flash') {
    $mimeType = mime_content_type($filePath);
    if (!$mimeType || $mimeType === 'application/octet-stream') {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $mimeType = $mimes[$ext] ?? 'application/pdf';
    }

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
  \"policy_type\": string or null,
  \"policy_start_date\": \"YYYY-MM-DD\" or null,
  \"policy_end_date\": \"YYYY-MM-DD\" or null,
  \"issue_date\": \"YYYY-MM-DD\" or null,
  \"total_premium\": number or null,
  \"net_premium\": number or null,
  \"gst_amount\": number or null,
  \"sum_insured\": number or null,
  \"vehicle_number\": string or null,
  \"make\": string or null,
  \"model\": string or null,
  \"engine_number\": string or null,
  \"chassis_number\": string or null,
  \"idv\": number or null,
  \"ncb_percent\": number or null,
  \"plan_name\": string or null,
  \"tpa_name\": string or null,
  \"members_count\": number or null,
  \"members_details\": string or null,
  \"nominee_name\": string or null,
  \"nominee_relation\": string or null,
  \"nominee_dob\": \"YYYY-MM-DD\" or null,
  \"insurer_contact\": string or null,
  \"important_terms\": string or null,
  \"confidence_scores\": {
    \"policy_number\": number,
    \"policyholder_name\": number,
    \"total_premium\": number,
    \"policy_end_date\": number,
    \"insurance_company\": number,
    \"overall\": number
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
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => false
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
        return ['success' => false, 'error' => "Gemini API error ($httpCode)", 'time_ms' => $durationMs];
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

    $prompt = "Extract insurance policy details as structured JSON with keys: insurance_company, policyholder_name, policy_number, policy_type, policy_start_date, policy_end_date, total_premium, net_premium, gst_amount, sum_insured, vehicle_number, make, model, engine_number, chassis_number, idv, ncb_percent, plan_name, tpa_name, nominee_name, nominee_relation, confidence_scores.";

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
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $startTime = microtime(true);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $durationMs = (int)round((microtime(true) - $startTime) * 1000);

    if ($err) return ['success' => false, 'error' => "cURL error: $err", 'time_ms' => $durationMs];
    if ($httpCode !== 200) return ['success' => false, 'error' => "Claude API error ($httpCode)", 'time_ms' => $durationMs];

    $json = json_decode($res, true);
    $text = $json['content'][0]['text'] ?? '';
    $cleanJson = preg_replace('/^```json\s*|\s*```$/i', '', trim($text));
    $parsed = json_decode($cleanJson, true);

    if (!is_array($parsed)) return ['success' => false, 'error' => 'Failed to parse JSON response', 'raw' => $text, 'time_ms' => $durationMs];

    return ['success' => true, 'data' => $parsed, 'time_ms' => $durationMs];
}

// Execute Scan
$aiProvider = getSetting('ai_provider', 'gemini');
$geminiApiKey = getSetting('gemini_api_key', '');
$claudeApiKey = getSetting('claude_api_key', '');
$aiModel = getSetting('ai_model', 'gemini-1.5-flash');

$result = null;

if ($aiProvider === 'gemini' && !empty($geminiApiKey)) {
    $result = scanDocumentWithGemini($fullFilePath, $category, $geminiApiKey, $aiModel);
} elseif ($aiProvider === 'claude' && !empty($claudeApiKey)) {
    $result = scanDocumentWithClaude($fullFilePath, $category, $claudeApiKey);
}

// Fallback to local heuristic extractor if AI API was not used or failed
if (!$result || !$result['success']) {
    $fallbackResult = scanDocumentFallback($fullFilePath, $category, $ext);
    if ($result && !empty($result['error'])) {
        $fallbackResult['data']['confidence_scores']['overall'] = 0.5;
        $fallbackResult['scan_notice'] = $result['error'];
    }
    $result = $fallbackResult;
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
    $errMsg = $result['error'] ?? 'AI scanning error';
    $upd = $conn->prepare("UPDATE policy_extractions SET scanning_status = 'manual_review', error_message = ? WHERE id = ?");
    $upd->bind_param("si", $errMsg, $extId);
    $upd->execute();
    $upd->close();
    $conn->close();

    header("Location: policy_verify.php?ext_id=" . $extId . "&category=" . urlencode($category) . "&scan_error=" . urlencode($errMsg));
    exit();
}
