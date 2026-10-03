<?php
$body = file_get_contents('php://input');
$input = json_decode($body, true);
$name = $input['name'] ?? '';
if ($name === 'rate-limit') {
    http_response_code(429); header('Retry-After: 5'); echo 'sensitive body'; return;
}
if ($name === 'redirect') {
    http_response_code(307); header('Location: /redirect-target'); return;
}
if ($_SERVER['REQUEST_URI'] === '/redirect-target') { http_response_code(500); return; }
if ($name === 'timeout') { usleep(500000); }
http_response_code($name === 'wrong-status' ? 200 : 202);
if ($name === 'invalid') { echo '{}'; return; }
header('Content-Type: application/json');
echo json_encode([
    'success' => true, 'accepted' => true, 'channels' => ['updates'],
    'publicationId' => json_encode(['uri' => $_SERVER['REQUEST_URI'], 'body' => $input, 'headers' => getallheaders()]),
    'duplicate' => $name === 'duplicate',
]);
