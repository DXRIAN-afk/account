<?php
/**
 * dartx account – Anmeldung für eine App («Mit dartx account anmelden»)
 *
 * Die App leitet hierher weiter mit:
 *   response_type=code, client_id, redirect_uri, state,
 *   code_challenge (Base64url SHA-256 des code_verifier), code_challenge_method=S256
 * Ist der Benutzer angemeldet und bestätigt, geht es zurück zu redirect_uri
 * mit ?code=…&state=…. Die App tauscht den Code bei token.php ein.
 */
require __DIR__ . '/lib/app.php';

// Die Parameter stehen immer in der Adresse (auch beim Bestätigen per Formular)
$get = fn (string $k) => is_string($_GET[$k] ?? null) ? (string) $_GET[$k] : '';

$client = client($get('client_id'));
$redirectUri = $get('redirect_uri');
$state = $get('state');
$challenge = $get('code_challenge');

function fail_page(string $text): void
{
    http_response_code(400);
    render_page('Anmeldung nicht möglich', '<div class="card narrow"><h1>Anmeldung nicht möglich</h1>
      <p class="muted">' . h($text) . '</p><a class="btn ghost block" href="' . h(base_path()) . '">Zu meinem Konto</a></div>');
    exit;
}

// Fehler bei App oder Rücksprung-Adresse nie zurückleiten (Schutz vor Umleitung auf fremde Seiten)
if (!$client) {
    fail_page('Diese App ist nicht bekannt.');
}
if (!in_array($redirectUri, (array) $client['redirect_uris'], true)) {
    fail_page('Die Rücksprung-Adresse dieser App ist nicht freigegeben.');
}

function back(string $redirectUri, array $params): void
{
    redirect($redirectUri . (strpos($redirectUri, '?') === false ? '?' : '&') . http_build_query($params));
}

if ($get('response_type') !== 'code' || $get('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
    back($redirectUri, ['error' => 'invalid_request', 'state' => $state]);
}

// Diese Anfrage (ohne Formularfelder) – für «Weiter» nach Anmeldung oder Kontowechsel
$query = http_build_query([
    'response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => $redirectUri,
    'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
]);
$self = 'authorize.php?' . $query;

$me = current_user();
if (!$me || $get('prompt') === 'login') {
    if ($me) {
        end_session();
    }
    redirect(base_path() . '?p=login&continue=' . rawurlencode($self));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('decision') === 'switch') {
        end_session();
        redirect(base_path() . '?p=login&continue=' . rawurlencode($self));
    }
    if (post('decision') !== 'allow') {
        back($redirectUri, ['error' => 'access_denied', 'state' => $state]);
    }
}

if (!empty($me['locked_at'])) {
    render_page('Konto gesperrt', '<div class="card narrow"><h1>Konto gesperrt</h1><p class="muted">' . h(lock_message($me)) . '</p>
      <form method="post" action="' . h(base_path()) . '">' . csrf_field() . '<input type="hidden" name="action" value="logout">
      <button class="btn ghost block">Abmelden</button></form></div>');
    exit;
}

$granted = q('SELECT 1 FROM grants WHERE user_id = ? AND client_id = ?', [$me['id'], $client['id']])->fetchColumn();

if (!$granted && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Erstes Mal bei dieser App: kurz bestätigen, mit welchem Konto es weitergeht
    render_page('Weiter zu ' . $client['name'], '<div class="card narrow consent">
      <h1>Weiter zu ' . h($client['name']) . '</h1>
      <div class="who"><span class="avatar">' . h(initials($me['display_name'])) . '</span>
        <span><b>' . h($me['display_name']) . '</b><small>@' . h($me['username']) . '</small></span></div>
      <p class="muted small">' . h($client['name']) . ' erhält deinen Benutzernamen und deinen Anzeigenamen. Dein Passwort bleibt bei ' . h(cfg('name')) . '.</p>
      <form method="post" action="' . h(base_path() . $self) . '" class="form">' . csrf_field() . '
        <button class="btn primary block" name="decision" value="allow">Weiter als ' . h($me['display_name']) . '</button>
        <button class="btn ghost block" name="decision" value="switch">Anderes Konto verwenden</button>
        <button class="btn link block" name="decision" value="deny">Abbrechen</button>
      </form></div>');
    exit;
}

// Freigabe merken und einmaligen Code ausstellen
if ($granted) {
    q('UPDATE grants SET last_used = ? WHERE user_id = ? AND client_id = ?', [time(), $me['id'], $client['id']]);
} else {
    q('INSERT INTO grants (user_id, client_id, created_at, last_used) VALUES (?, ?, ?, ?)', [$me['id'], $client['id'], time(), time()]);
}
$code = bin2hex(random_bytes(32));
q('DELETE FROM codes WHERE created_at < ?', [time() - CODE_TTL]);
q('INSERT INTO codes (code_hash, client_id, user_id, redirect_uri, challenge, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
    hash('sha256', $code), $client['id'], $me['id'], $redirectUri, $challenge, time(),
]);
back($redirectUri, ['code' => $code, 'state' => $state]);
