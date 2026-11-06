<?php
/**
 * dartx account – gemeinsamer Programmcode
 *
 * Ein Konto für alle Apps unter da-rtx.ch. Die Apps melden ihre Benutzer
 * nach dem Prinzip «Mit Google anmelden» an (OAuth 2 Authorization Code
 * mit PKCE): Die App schickt den Browser hierher, der Benutzer meldet sich
 * an, und die App erhält einen einmaligen Code, den sie gegen die
 * Benutzerdaten eintauscht. Passwörter sieht nur dartx account.
 */

const SESSION_COOKIE = 'dxrpass';
const CSRF_COOKIE = 'dxrpass_csrf';
const SCHEMA_VERSION = '1';
const CODE_TTL = 120;          // Sekunden, die ein Anmelde-Code gültig ist
const USERNAME_RULE = '/^[A-Za-z0-9_.-]{3,24}$/';
const NAME_NOTICE = 'Dieser Name ist nicht erlaubt und wurde durch «%s» ersetzt.';

$config = require __DIR__ . '/config.php';
// Optionale lokale Einstellungen (z.B. Admin-Passwort). Leere Werte werden ignoriert.
if (is_file(__DIR__ . '/config.local.php')) {
    $dropEmpty = function (array $a) use (&$dropEmpty) {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $dropEmpty($v);
            } elseif ($v === '' || $v === null) {
                unset($a[$k]);
            }
        }
        return $a;
    };
    $config = array_replace_recursive($config, $dropEmpty((array) require __DIR__ . '/config.local.php'));
}
$GLOBALS['config'] = $config;
date_default_timezone_set($config['timezone'] ?? 'Europe/Zurich');
require __DIR__ . '/namefilter.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = $GLOBALS['config'];
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if ($c['driver'] === 'mysql') {
        $m = $c['mysql'];
        $pdo = new PDO("mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset=utf8mb4", $m['user'], $m['password'], $opts);
    } else {
        $dir = dirname($c['sqlite_path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    install_schema($pdo);
    return $pdo;
}

function install_schema(PDO $pdo): void
{
    $mysql = $GLOBALS['config']['driver'] === 'mysql';
    try {
        $v = $pdo->query("SELECT value FROM meta WHERE name = 'schema_version'")->fetchColumn();
        if ($v === SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        // Tabellen fehlen noch
    }
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $s = $mysql ? 'VARCHAR(191)' : 'TEXT';
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $tables = [
        "meta (name $s PRIMARY KEY, value TEXT)",
        "users (id $id, sub $s NOT NULL UNIQUE, username $s NOT NULL UNIQUE, display_name $s NOT NULL,
            password_hash $s NOT NULL, is_admin $int NOT NULL DEFAULT 0, created_at $int NOT NULL,
            last_login $int NOT NULL DEFAULT 0, locked_at $int NULL, lock_reason $s NULL, terms_at $int NULL)",
        "sessions (token_hash CHAR(64) PRIMARY KEY, user_id $int NOT NULL, created_at $int NOT NULL, expires_at $int NOT NULL,
            user_agent $s NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
        "grants (user_id $int NOT NULL, client_id $s NOT NULL, created_at $int NOT NULL, last_used $int NOT NULL,
            PRIMARY KEY (user_id, client_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
        "codes (code_hash CHAR(64) PRIMARY KEY, client_id $s NOT NULL, user_id $int NOT NULL, redirect_uri TEXT NOT NULL,
            challenge $s NOT NULL, created_at $int NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
        "tokens (token_hash CHAR(64) PRIMARY KEY, client_id $s NOT NULL, user_id $int NOT NULL, created_at $int NOT NULL,
            last_used $int NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)",
        "login_attempts (ip $s NOT NULL, at $int NOT NULL)",
    ];
    foreach ($tables as $t) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $t$end");
    }
    $pdo->prepare('DELETE FROM meta WHERE name = ?')->execute(['schema_version']);
    $pdo->prepare('INSERT INTO meta (name, value) VALUES (?, ?)')->execute(['schema_version', SCHEMA_VERSION]);
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ---------------------------------------------------------------------------
// Helfer
// ---------------------------------------------------------------------------

function cfg(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

function base_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir . '/';
}

function set_cookie(string $name, string $value, int $expires, bool $httpOnly = true): void
{
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => base_path(),
        'secure'   => is_https(),
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
    ]);
}

function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function clean_name(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function strlen_u(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int) preg_match_all('/./us', $s);
}

function redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/** Kurze Meldung für die nächste Seite (über ein Cookie). */
function flash(?string $text = null, string $kind = 'ok'): ?array
{
    if ($text !== null) {
        set_cookie('dxrpass_flash', json_encode([$kind, $text]), time() + 60);
        return null;
    }
    $raw = $_COOKIE['dxrpass_flash'] ?? '';
    if ($raw === '') {
        return null;
    }
    set_cookie('dxrpass_flash', '', time() - 3600);
    $v = json_decode($raw, true);
    return is_array($v) && count($v) === 2 ? ['kind' => (string) $v[0], 'text' => (string) $v[1]] : null;
}

// ---------------------------------------------------------------------------
// CSRF-Schutz (Double-Submit-Cookie)
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    static $token = null;
    if ($token === null) {
        $token = $_COOKIE[CSRF_COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            set_cookie(CSRF_COOKIE, $token, 0);
        }
    }
    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    $cookie = $_COOKIE[CSRF_COOKIE] ?? '';
    if (!is_string($cookie) || $cookie === '' || !hash_equals($cookie, post('csrf'))) {
        http_response_code(400);
        render_page('Sitzung abgelaufen', '<div class="card narrow"><h1>Sitzung abgelaufen</h1>
            <p class="muted">Bitte lade die Seite neu und versuche es nochmals.</p>
            <a class="btn primary block" href="' . h(base_path()) . '">Neu laden</a></div>');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Konten und Sitzungen
// ---------------------------------------------------------------------------

function admin_username(): string
{
    return strtolower(trim((string) (cfg('admin')['username'] ?? '')));
}

function find_user(string $username): ?array
{
    $u = q('SELECT * FROM users WHERE username = ?', [strtolower($username)])->fetch();
    return $u ?: null;
}

function user_by_id(int $id): ?array
{
    $u = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    return $u ?: null;
}

function create_user(string $username, string $displayName, string $password, bool $admin = false): int
{
    q('INSERT INTO users (sub, username, display_name, password_hash, is_admin, created_at, terms_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        bin2hex(random_bytes(12)), strtolower($username), $displayName, password_hash($password, PASSWORD_DEFAULT),
        $admin ? 1 : 0, time(), time(),
    ]);
    return (int) db()->lastInsertId();
}

/** Administrator beim ersten Anmelden mit dem Passwort aus config.local.php anlegen. */
function provision_admin(string $username, string $password): ?array
{
    $configured = (string) (cfg('admin')['password'] ?? '');
    if ($configured === '' || strtolower($username) !== admin_username() || !hash_equals($configured, $password)) {
        return null;
    }
    $u = find_user($username);
    if ($u) {
        return !empty($u['is_admin']) ? $u : null;
    }
    $id = create_user($username, trim((string) cfg('admin')['username']), $password, true);
    return user_by_id($id);
}

function login_throttle(): void
{
    q('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
    $n = (int) q('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [client_ip()])->fetchColumn();
    if ($n >= 10) {
        throw new UserError('Zu viele Anmeldeversuche. Bitte warte 15 Minuten.');
    }
}

function login_failed(): void
{
    q('INSERT INTO login_attempts (ip, at) VALUES (?, ?)', [client_ip(), time()]);
}

function start_session(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + 86400 * (int) cfg('session_days', 180);
    q('INSERT INTO sessions (token_hash, user_id, created_at, expires_at, user_agent) VALUES (?, ?, ?, ?, ?)', [
        hash('sha256', $token), $userId, time(), $expires, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
    ]);
    q('DELETE FROM sessions WHERE expires_at < ?', [time()]);
    q('UPDATE users SET last_login = ? WHERE id = ?', [time(), $userId]);
    set_cookie(SESSION_COOKIE, $token, $expires);
}

function end_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (is_string($token) && $token !== '') {
        q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    }
    set_cookie(SESSION_COOKIE, '', time() - 3600);
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $row = q('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ?',
            [hash('sha256', $token), time()])->fetch();
        $user = $row ?: null;
    }
    return $user;
}

function lock_message(array $u): string
{
    $reason = trim((string) ($u['lock_reason'] ?? ''));
    return 'Dein Konto wurde gesperrt.' . ($reason !== '' ? ' Grund: ' . $reason : '');
}

function delete_account(int $userId): void
{
    // Sitzungen, Freigaben, Codes und Tokens werden über ON DELETE CASCADE mitgelöscht
    foreach (['sessions', 'grants', 'codes', 'tokens'] as $t) {
        q("DELETE FROM $t WHERE user_id = ?", [$userId]);
    }
    q('DELETE FROM users WHERE id = ?', [$userId]);
}

// ---------------------------------------------------------------------------
// Apps (OAuth-Clients)
// ---------------------------------------------------------------------------

function clients(): array
{
    return (array) cfg('clients', []);
}

function client(string $id): ?array
{
    $c = clients()[$id] ?? null;
    return is_array($c) ? $c + ['id' => $id, 'redirect_uris' => []] : null;
}

/** Ursprung (Schema + Host) einer Adresse, z.B. https://hexa.da-rtx.ch */
function origin_of(string $url): string
{
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

/** CORS für Apps, die direkt aus dem Browser anfragen (z.B. reine JavaScript-Apps). */
function allow_client_origins(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    foreach (clients() as $c) {
        foreach ((array) ($c['redirect_uris'] ?? []) as $uri) {
            if (origin_of($uri) === $origin) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Headers: Authorization, Content-Type');
                header('Access-Control-Allow-Methods: GET, POST');
                header('Vary: Origin');
                return;
            }
        }
    }
}

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Daten, die eine App über den Benutzer erhält. */
function public_profile(array $u): array
{
    return [
        'sub'          => $u['sub'],
        'username'     => $u['username'],
        'display_name' => $u['display_name'],
        'created_at'   => (int) $u['created_at'],
        'locked'       => !empty($u['locked_at']),
        'lock_reason'  => !empty($u['locked_at']) ? (string) ($u['lock_reason'] ?? '') : '',
        // Administrator von dartx account – die Apps machen ihn auch bei sich zum Administrator
        'is_admin'     => !empty($u['is_admin']),
    ];
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// Seitenaufbau
// ---------------------------------------------------------------------------

class UserError extends Exception
{
}

function logo_svg(int $size = 40): string
{
    return '<svg class="logo" width="' . $size . '" height="' . $size . '" viewBox="0 0 512 512" aria-hidden="true">
      <rect width="512" height="512" rx="116" fill="#1F2023"/>
      <rect x="196" y="190" width="214" height="150" rx="34" fill="none" stroke="#E8781F" stroke-width="18"/>
      <rect x="96" y="146" width="236" height="162" rx="38" fill="#E8781F"/>
      <circle cx="170" cy="210" r="30" fill="#1F2023"/>
      <path d="M124 278 a46 40 0 0 1 92 0 Z" fill="#1F2023"/>
      <rect x="236" y="196" width="66" height="16" rx="8" fill="#1F2023"/>
      <rect x="236" y="232" width="48" height="16" rx="8" fill="#1F2023" opacity=".7"/>
    </svg>';
}

function render_page(string $title, string $body, array $opts = []): void
{
    header('Content-Type: text/html; charset=utf-8');
    $name = h(cfg('name'));
    $base = h(base_path());
    $wide = !empty($opts['wide']) ? ' wide' : '';
    $flash = flash();
    $flashHtml = $flash ? '<div class="flash ' . h($flash['kind']) . '" role="status">' . h($flash['text']) . '</div>' : '';
    echo '<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#1F2023">
  <title>' . h($title) . ' – ' . $name . '</title>
  <link rel="icon" href="' . $base . 'assets/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="' . $base . 'assets/style.css?v=1">
</head>
<body>
  <main class="page' . $wide . '">
    <a class="brand" href="' . $base . '">' . logo_svg(40) . '<span>' . $name . '</span></a>
    ' . $flashHtml . $body . '
    <footer class="foot"><a href="' . $base . '?p=terms">Nutzungsbedingungen &amp; Datenschutz</a></footer>
  </main>
</body>
</html>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $first = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $s .= $first($p, 0, 1);
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($s ?: '?') : strtoupper($s ?: '?');
}

function format_date(int $ts): string
{
    return $ts ? date('d.m.Y', $ts) : '–';
}

function ago(int $ts): string
{
    if (!$ts) {
        return 'nie';
    }
    $d = time() - $ts;
    if ($d < 60) {
        return 'gerade eben';
    }
    if ($d < 3600) {
        return 'vor ' . intdiv($d, 60) . ' Min.';
    }
    if ($d < 86400) {
        return 'vor ' . intdiv($d, 3600) . ' Std.';
    }
    if ($d < 86400 * 30) {
        return 'vor ' . intdiv($d, 86400) . ' Tagen';
    }
    return date('d.m.Y', $ts);
}
