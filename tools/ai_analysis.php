<?php
/**
 * ai_analysis.php — Streaming AI scan interpreter
 *
 * POST params:
 *   csrf    — CSRF token
 *   scan_id — ID from scans table
 *
 * Streams Server-Sent Events:
 *   data: {"text":"..."}          — incremental markdown text
 *   data: {"done":true}           — stream finished, analysis saved
 *   data: {"cached":true,"text"}  — returned from DB cache (no API call)
 *   data: {"error":"..."}         — something went wrong
 */

require_once __DIR__ . '/../config/app.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid CSRF token']); exit;
}

$scan_id = (int)($_POST['scan_id'] ?? 0);
if (!$scan_id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing scan_id']); exit;
}

// Fetch scan — user must own it
$stmt = db()->prepare('SELECT id, target_url, score, grade, scanned_at, result_json, ai_analysis FROM scans WHERE id = ? AND user_id = ?');
$stmt->execute([$scan_id, (int)$user['id']]);
$scan = $stmt->fetch();

if (!$scan) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Scan not found or access denied']); exit;
}

// ── SSE headers ───────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// Kill output buffering so chunks flush immediately
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// Helper: send an SSE event
function sse(array $payload): void {
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

// ── Return cached analysis if it exists ───────────────────────────────────────
if (!empty($scan['ai_analysis'])) {
    sse(['cached' => true, 'text' => $scan['ai_analysis']]);
    exit;
}

// ── Check API key ─────────────────────────────────────────────────────────────
$api_key = getenv('ANTHROPIC_API_KEY') ?: '';
if (!$api_key) {
    sse(['error' => 'ANTHROPIC_API_KEY is not set in .env — AI analysis is unavailable.']);
    exit;
}

// ── Build prompt from scan findings ──────────────────────────────────────────
$result   = json_decode($scan['result_json'], true) ?? [];
$findings = $result['findings'] ?? [];

// Only send critical/high/medium findings to keep prompt small + costs low
$relevant = array_filter($findings, fn($f) =>
    in_array($f['severity'] ?? '', ['critical','high','medium'])
    && ($f['status'] ?? '') === 'fail'
);
$passed_count = count(array_filter($findings, fn($f) => ($f['status'] ?? '') === 'pass'));

$prompt  = "TARGET: {$scan['target_url']}  SCORE: {$scan['score']}/100  GRADE: {$scan['grade']}\n";
$prompt .= "PASSED: {$passed_count}  ISSUES: " . count($relevant) . " (critical/high/medium)\n\n";

foreach (array_slice($relevant, 0, 20) as $f) {   // cap at 20 findings
    $sev   = strtoupper($f['severity'] ?? 'info');
    $title = $f['title']  ?? '';
    $detail= mb_substr($f['detail'] ?? '', 0, 120); // trim long details
    $fix   = mb_substr($f['remediation'] ?? '', 0, 80);
    $prompt .= "[$sev] $title: $detail" . ($fix ? " | FIX: $fix" : '') . "\n";
}

$system = "You are a cybersecurity consultant. Analyse this scan and respond with ONLY these 4 sections — keep each section brief:\n\n## Summary\n1-2 sentences. Business risk, non-technical.\n\n## Top Risks\nBullet list of the 3-5 worst issues. What can an attacker actually do?\n\n## Fix Order\nNumbered list: fix this first, then this. One line per fix.\n\n## What's Good\n1-3 bullet points of passing controls.\n\nBe direct. No disclaimers. Total response under 400 words.";

// ── Stream from Anthropic API ─────────────────────────────────────────────────
$full_text  = '';
$parse_buf  = '';
$curl_error = '';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL        => 'https://api.anthropic.com/v1/messages',
    CURLOPT_POST       => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 600,
        'stream'     => true,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]),
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$full_text, &$parse_buf) {
        $parse_buf .= $data;

        // Process complete lines from the buffer
        while (($nl = strpos($parse_buf, "\n")) !== false) {
            $line      = substr($parse_buf, 0, $nl);
            $parse_buf = substr($parse_buf, $nl + 1);
            $line      = trim($line);

            if (!str_starts_with($line, 'data: ')) continue;
            $json = substr($line, 6);
            if ($json === '[DONE]') continue;

            $event = json_decode($json, true);
            if (!$event) continue;

            $type = $event['type'] ?? '';

            // Content delta
            if ($type === 'content_block_delta') {
                $text = $event['delta']['text'] ?? '';
                if ($text !== '') {
                    $full_text .= $text;
                    sse(['text' => $text]);
                }
            }

            // Error from API
            if ($type === 'error') {
                $msg = $event['error']['message'] ?? 'Unknown API error';
                sse(['error' => $msg]);
            }
        }

        return strlen($data);
    },
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

curl_exec($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    sse(['error' => 'Connection failed: ' . $curl_error]);
    exit;
}

if ($full_text) {
    // Save to DB
    try {
        db()->prepare('UPDATE scans SET ai_analysis = ? WHERE id = ?')
            ->execute([$full_text, $scan_id]);
    } catch (Exception $e) {}

    sse(['done' => true]);
} else {
    sse(['error' => 'No response received from AI. Check ANTHROPIC_API_KEY.']);
}
