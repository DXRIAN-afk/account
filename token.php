<?php
/**
 * DXR Pass – Code gegen Zugangsschlüssel und Benutzerdaten eintauschen
 *
 * POST grant_type=authorization_code, code, redirect_uri, client_id, code_verifier
 * Antwort: { access_token, token_type: "Bearer", user: { sub, username, display_name, … } }
 * Der Zugangsschlüssel gilt, bis der Benutzer den Zugriff entfernt oder gelöscht wird;
 * die App fragt damit bei userinfo.php nach, ob das Konto noch aktiv ist.
 */
require __DIR__ . '/lib/app.php';

allow_client_origins();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'invalid_request', 'error_description' => 'Nur POST.'], 405);
}

$in = $_POST;
if (!$in && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false) {
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
}
$get = fn (string $k) => is_string($in[$k] ?? null) ? (string) $in[$k] : '';

if ($get('grant_type') !== 'authorization_code') {
    json_out(['error' => 'unsupported_grant_type'], 400);
}
$row = q('SELECT * FROM codes WHERE code_hash = ?', [hash('sha256', $get('code'))])->fetch();
// Code nur einmal verwendbar – sofort löschen
if ($row) {
    q('DELETE FROM codes WHERE code_hash = ?', [$row['code_hash']]);
}
$verifier = $get('code_verifier');
if (!$row || $row['created_at'] < time() - CODE_TTL || $row['client_id'] !== $get('client_id') || $row['redirect_uri'] !== $get('redirect_uri')
    || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals($row['challenge'], b64url(hash('sha256', $verifier, true)))) {
    json_out(['error' => 'invalid_grant', 'error_description' => 'Der Anmelde-Code ist ungültig oder abgelaufen.'], 400);
}
$user = user_by_id((int) $row['user_id']);
if (!$user) {
    json_out(['error' => 'invalid_grant'], 400);
}
if (!empty($user['locked_at'])) {
    json_out(['error' => 'access_denied', 'error_description' => lock_message($user)], 403);
}
$token = bin2hex(random_bytes(32));
q('INSERT INTO tokens (token_hash, client_id, user_id, created_at, last_used) VALUES (?, ?, ?, ?, ?)', [
    hash('sha256', $token), $row['client_id'], $user['id'], time(), time(),
]);
json_out(['access_token' => $token, 'token_type' => 'Bearer', 'user' => public_profile($user)]);
