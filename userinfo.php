<?php
/**
 * DXR Pass – Benutzerdaten und Status abfragen
 *
 * GET mit Header «Authorization: Bearer <access_token>»
 * Antwort: { sub, username, display_name, created_at, locked, lock_reason }
 * 401 { error: "invalid_token" }, wenn der Zugriff entfernt oder das Konto gelöscht wurde.
 */
require __DIR__ . '/lib/app.php';

allow_client_origins();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($auth), $m)) {
    // Manche Hoster entfernen den Authorization-Header – dann als Parameter
    $m = [1 => (string) ($_POST['access_token'] ?? '')];
}
$row = preg_match('/^[a-f0-9]{64}$/', $m[1])
    ? q('SELECT * FROM tokens WHERE token_hash = ?', [hash('sha256', strtolower($m[1]))])->fetch()
    : null;
$user = $row ? user_by_id((int) $row['user_id']) : null;
if (!$user) {
    json_out(['error' => 'invalid_token'], 401);
}
q('UPDATE tokens SET last_used = ? WHERE token_hash = ?', [time(), $row['token_hash']]);
q('UPDATE grants SET last_used = ? WHERE user_id = ? AND client_id = ?', [time(), $user['id'], $row['client_id']]);
json_out(public_profile($user));
