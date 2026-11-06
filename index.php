<?php
/**
 * dartx account – Seiten: Anmelden, Registrieren, Konto, Administration
 * Aufruf: index.php?p=<seite>
 */
require __DIR__ . '/lib/app.php';

$page = (string) ($_GET['p'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$me = current_user();

/** Nach dem Anmelden zurück zur App – nur zu authorize.php erlaubt (kein offener Redirect). */
function continue_url(): string
{
    $c = (string) ($_GET['continue'] ?? $_POST['continue'] ?? '');
    return preg_match('~^authorize\.php\?[^\s<>"\']*$~', $c) ? $c : '';
}

function go_on(string $continue): void
{
    redirect(base_path() . ($continue !== '' ? $continue : ''));
}

try {
    if ($method === 'POST') {
        check_csrf();
        $action = post('action');

        if ($action === 'login') {
            login_throttle();
            $username = post('username');
            $password = (string) ($_POST['password'] ?? '');
            $u = find_user($username);
            if (!$u || !password_verify($password, $u['password_hash'])) {
                $u = provision_admin($username, $password);
            }
            if (!$u) {
                login_failed();
                throw new UserError('Benutzername oder Passwort ist falsch.');
            }
            if (!empty($u['locked_at'])) {
                throw new UserError(lock_message($u));
            }
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            }
            start_session((int) $u['id']);
            go_on(continue_url());
        }

        if ($action === 'register') {
            login_throttle();
            $username = post('username');
            $display = clean_name(post('display_name'));
            $password = (string) ($_POST['password'] ?? '');
            if (!preg_match(USERNAME_RULE, $username)) {
                throw new UserError('Benutzername: 3–24 Zeichen, nur Buchstaben, Zahlen, _ . -');
            }
            if (strtolower($username) === admin_username() || find_user($username)) {
                throw new UserError('Dieser Benutzername ist bereits vergeben.');
            }
            if (name_is_offensive($username)) {
                throw new UserError('Dieser Benutzername ist nicht erlaubt. Bitte wähle einen anderen.');
            }
            if ($display === '') {
                $display = $username;
            }
            if (strlen_u($display) > 40) {
                throw new UserError('Der Anzeigename darf höchstens 40 Zeichen lang sein.');
            }
            if (strlen($password) < 8) {
                throw new UserError('Das Passwort muss mindestens 8 Zeichen lang sein.');
            }
            if ($password !== (string) ($_POST['password2'] ?? '')) {
                throw new UserError('Die Passwörter stimmen nicht überein.');
            }
            if (empty($_POST['terms'])) {
                throw new UserError('Bitte akzeptiere die Nutzungsbedingungen und die Datenschutzerklärung.');
            }
            $notice = null;
            if (name_is_offensive($display)) {
                $display = invented_name();
                $notice = sprintf(NAME_NOTICE, $display);
            }
            $id = create_user($username, $display, $password);
            start_session($id);
            flash($notice ?? 'Willkommen bei ' . cfg('name') . '! Dein Konto ist bereit.', $notice ? 'warn' : 'ok');
            go_on(continue_url());
        }

        if ($action === 'logout') {
            end_session();
            flash('Du wurdest abgemeldet.');
            redirect(base_path());
        }

        // Ab hier nur angemeldet
        if (!$me) {
            redirect(base_path());
        }

        if ($action === 'profile') {
            $display = clean_name(post('display_name'));
            if ($display === '' || strlen_u($display) > 40) {
                throw new UserError('Der Anzeigename muss 1–40 Zeichen lang sein.');
            }
            $msg = 'Gespeichert.';
            if (name_is_offensive($display)) {
                $display = invented_name();
                $msg = sprintf(NAME_NOTICE, $display);
            }
            q('UPDATE users SET display_name = ? WHERE id = ?', [$display, $me['id']]);
            flash($msg, $msg === 'Gespeichert.' ? 'ok' : 'warn');
            redirect(base_path());
        }

        if ($action === 'password') {
            if (!password_verify((string) ($_POST['old'] ?? ''), $me['password_hash'])) {
                throw new UserError('Das aktuelle Passwort ist falsch.');
            }
            $new = (string) ($_POST['new'] ?? '');
            if (strlen($new) < 8) {
                throw new UserError('Das neue Passwort muss mindestens 8 Zeichen lang sein.');
            }
            if ($new !== (string) ($_POST['new2'] ?? '')) {
                throw new UserError('Die neuen Passwörter stimmen nicht überein.');
            }
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            // Andere Geräte abmelden
            q('DELETE FROM sessions WHERE user_id = ?', [$me['id']]);
            start_session((int) $me['id']);
            flash('Passwort geändert. Andere Geräte wurden abgemeldet.');
            redirect(base_path());
        }

        if ($action === 'revoke') {
            $cid = post('client');
            q('DELETE FROM grants WHERE user_id = ? AND client_id = ?', [$me['id'], $cid]);
            q('DELETE FROM tokens WHERE user_id = ? AND client_id = ?', [$me['id'], $cid]);
            $c = client($cid);
            flash('Zugriff für ' . ($c['name'] ?? $cid) . ' entfernt. Du wirst dort beim nächsten Öffnen abgemeldet.');
            redirect(base_path());
        }

        if ($action === 'delete') {
            if (!empty($me['is_admin'])) {
                throw new UserError('Das Administratorkonto kann nicht gelöscht werden.');
            }
            if (!password_verify((string) ($_POST['password'] ?? ''), $me['password_hash'])) {
                throw new UserError('Das Passwort ist falsch.');
            }
            delete_account((int) $me['id']);
            set_cookie(SESSION_COOKIE, '', time() - 3600);
            flash('Dein Konto wurde gelöscht.');
            redirect(base_path());
        }

        // Administration
        if (in_array($action, ['admin_lock', 'admin_unlock', 'admin_delete'], true)) {
            if (empty($me['is_admin'])) {
                throw new UserError('Keine Berechtigung.');
            }
            $target = user_by_id((int) post('user'));
            if (!$target || !empty($target['is_admin'])) {
                throw new UserError('Dieses Konto kann nicht geändert werden.');
            }
            if ($action === 'admin_lock') {
                q('UPDATE users SET locked_at = ?, lock_reason = ? WHERE id = ?', [time(), substr(post('reason'), 0, 200), $target['id']]);
                q('DELETE FROM sessions WHERE user_id = ?', [$target['id']]);
                flash($target['display_name'] . ' wurde gesperrt – in allen Apps.');
            } elseif ($action === 'admin_unlock') {
                q('UPDATE users SET locked_at = NULL, lock_reason = NULL WHERE id = ?', [$target['id']]);
                flash($target['display_name'] . ' wurde entsperrt.');
            } else {
                delete_account((int) $target['id']);
                flash($target['display_name'] . ' wurde gelöscht.');
            }
            redirect(base_path() . '?p=admin' . (post('q') !== '' ? '&q=' . rawurlencode(post('q')) : ''));
        }

        throw new UserError('Unbekannte Aktion.');
    }
} catch (UserError $e) {
    $error = $e->getMessage();
    if ($action === 'login' || $action === 'register') {
        $page = $action;
    } else {
        flash($error, 'error');
        redirect(base_path() . ($page !== '' ? '?p=' . rawurlencode($page) : ''));
    }
}

// ---------------------------------------------------------------------------
// Seiten
// ---------------------------------------------------------------------------

$error = $error ?? null;
$errorHtml = $error ? '<div class="flash error" role="alert">' . h($error) . '</div>' : '';
$continue = continue_url();
$contField = $continue !== '' ? '<input type="hidden" name="continue" value="' . h($continue) . '">' : '';
$contQs = $continue !== '' ? '&continue=' . rawurlencode($continue) : '';
$name = h(cfg('name'));

/** App, zu der man nach dem Anmelden zurückkehrt (für den Hinweis oben). */
function continue_client(string $continue): ?array
{
    if ($continue === '') {
        return null;
    }
    parse_str((string) parse_url($continue, PHP_URL_QUERY), $qs);
    return client((string) ($qs['client_id'] ?? ''));
}
$target = continue_client($continue);
$targetHint = $target ? '<p class="hint">Weiter zu <b>' . h($target['name']) . '</b></p>' : '';

if ($page === 'terms') {
    $op = cfg('operator', []);
    $opName = trim((string) ($op['name'] ?? '')) ?: 'der Betreiber von da-rtx.ch';
    $opContact = trim((string) ($op['contact'] ?? ''));
    render_page('Nutzungsbedingungen', '<article class="card prose">
      <h1>Nutzungsbedingungen &amp; Datenschutz</h1>
      <p class="muted">Für ' . $name . ', das gemeinsame Konto für die Apps unter da-rtx.ch.</p>
      <h2>1. Wozu dient ' . $name . '?</h2>
      <p>Mit einem Konto meldest du dich bei allen angeschlossenen Apps an (z.B. Hexa Chat, SchoolWeb Browser, PC95, Schul-App). Für jede App gelten zusätzlich ihre eigenen Nutzungsbedingungen.</p>
      <h2>2. Welche Daten werden gespeichert?</h2>
      <ul>
        <li>Benutzername, Anzeigename und dein Passwort – nur als sicherer Hash, niemand kann es auslesen.</li>
        <li>Zeitpunkt der Registrierung und der letzten Anmeldung, angemeldete Geräte (Browser-Typ).</li>
        <li>Mit welchen Apps du dich angemeldet hast.</li>
      </ul>
      <p>Eine App erhält nur deinen Benutzernamen, deinen Anzeigenamen und eine zufällige Konto-Nummer – nie dein Passwort. Es gibt keine Werbung und keine Weitergabe an Dritte.</p>
      <h2>3. Regeln</h2>
      <p>Benutzer- und Anzeigenamen dürfen nicht beleidigend, sexuell oder rassistisch sein; solche Namen werden automatisch ersetzt. Bei Verstössen kann ' . h($opName) . ' Konten sperren oder löschen – eine Sperre gilt in allen Apps.</p>
      <h2>4. Deine Rechte</h2>
      <p>Du kannst deine Daten jederzeit einsehen, den Zugriff einzelner Apps entfernen und dein Konto selbst löschen. Es gilt das Schweizer Datenschutzgesetz (DSG).' . ($opContact !== '' ? ' Kontakt: ' . h($opContact) . '.' : '') . '</p>
      <h2>5. Haftung</h2>
      <p>' . h($opName) . ' betreibt den Dienst unentgeltlich und ohne Gewähr für ständige Verfügbarkeit. Die Haftung ist im gesetzlich zulässigen Rahmen ausgeschlossen (Art. 100 OR).</p>
      <p><a class="btn ghost" href="' . h(base_path()) . '">Zurück</a></p>
    </article>');
    exit;
}

if (!$me || $page === 'login' || $page === 'register') {
    if ($me && $continue !== '') {
        go_on($continue);
    }
    if ($me) {
        redirect(base_path());
    }
    $isReg = $page === 'register';
    $old = fn ($k) => h($_POST[$k] ?? '');
    if ($isReg) {
        render_page('Konto erstellen', '<div class="card narrow">
          <h1>Konto erstellen</h1>
          <p class="muted">Ein Konto für alle Apps von <span class="nowrap">da-rtx.ch</span>.</p>' . $targetHint . $errorHtml . '
          <form method="post" class="form" autocomplete="on">' . csrf_field() . $contField . '
            <input type="hidden" name="action" value="register">
            <label class="field"><span>Benutzername</span>
              <input name="username" value="' . $old('username') . '" required minlength="3" maxlength="24" pattern="[A-Za-z0-9_.\-]{3,24}" autocomplete="username" autocapitalize="off" spellcheck="false">
              <small>3–24 Zeichen: Buchstaben, Zahlen, _ . -</small></label>
            <label class="field"><span>Anzeigename</span>
              <input name="display_name" value="' . $old('display_name') . '" maxlength="40" autocomplete="nickname" placeholder="So sehen dich andere">
              <small>Freiwillig – sonst wird der Benutzername verwendet.</small></label>
            <label class="field"><span>Passwort</span>
              <input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
            <label class="field"><span>Passwort wiederholen</span>
              <input name="password2" type="password" required minlength="8" autocomplete="new-password"></label>
            <label class="check"><input type="checkbox" name="terms" value="1" required>
              <span>Ich akzeptiere die <a href="' . h(base_path()) . '?p=terms" target="_blank">Nutzungsbedingungen und die Datenschutzerklärung</a>.</span></label>
            <button class="btn primary block" type="submit">Konto erstellen</button>
          </form>
          <p class="switch">Schon ein Konto? <a href="' . h(base_path()) . '?p=login' . $contQs . '">Anmelden</a></p>
        </div>');
    } else {
        render_page('Anmelden', '<div class="card narrow">
          <h1>Anmelden</h1>
          <p class="muted">Mit deinem ' . $name . ' bei allen Apps von <span class="nowrap">da-rtx.ch</span>.</p>' . $targetHint . $errorHtml . '
          <form method="post" class="form" autocomplete="on">' . csrf_field() . $contField . '
            <input type="hidden" name="action" value="login">
            <label class="field"><span>Benutzername</span>
              <input name="username" value="' . $old('username') . '" required autocomplete="username" autocapitalize="off" spellcheck="false" autofocus></label>
            <label class="field"><span>Passwort</span>
              <input name="password" type="password" required autocomplete="current-password"></label>
            <button class="btn primary block" type="submit">Anmelden</button>
          </form>
          <p class="switch">Noch kein Konto? <a href="' . h(base_path()) . '?p=register' . $contQs . '">Konto erstellen</a></p>
        </div>');
    }
    exit;
}

// ---------------------------------------------------------------- Administration
if ($page === 'admin') {
    if (empty($me['is_admin'])) {
        redirect(base_path());
    }
    $search = trim((string) ($_GET['q'] ?? ''));
    $params = [];
    $where = '';
    if ($search !== '') {
        $where = 'WHERE username LIKE ? OR display_name LIKE ?';
        $params = ['%' . $search . '%', '%' . $search . '%'];
    }
    $users = q("SELECT u.*, (SELECT COUNT(*) FROM grants g WHERE g.user_id = u.id) AS apps
                FROM users u $where ORDER BY u.created_at DESC LIMIT 500", $params)->fetchAll();
    $total = (int) q('SELECT COUNT(*) FROM users')->fetchColumn();
    $active = (int) q('SELECT COUNT(*) FROM users WHERE last_login > ?', [time() - 86400 * 7])->fetchColumn();
    $locked = (int) q('SELECT COUNT(*) FROM users WHERE locked_at IS NOT NULL')->fetchColumn();
    $perApp = [];
    foreach (q('SELECT client_id, COUNT(*) AS n FROM grants GROUP BY client_id')->fetchAll() as $r) {
        $perApp[$r['client_id']] = (int) $r['n'];
    }
    $qs = $search !== '' ? '<input type="hidden" name="q" value="' . h($search) . '">' : '';
    $rows = '';
    foreach ($users as $u) {
        $status = !empty($u['is_admin']) ? '<span class="badge">Admin</span>'
            : (!empty($u['locked_at']) ? '<span class="badge bad" title="' . h($u['lock_reason']) . '">Gesperrt</span>' : '<span class="badge ok">Aktiv</span>');
        $actions = '';
        if (empty($u['is_admin'])) {
            if (!empty($u['locked_at'])) {
                $actions = '<form method="post" class="inline">' . csrf_field() . $qs . '<input type="hidden" name="action" value="admin_unlock">
                    <input type="hidden" name="user" value="' . (int) $u['id'] . '"><button class="btn ghost sm">Entsperren</button></form>';
            } else {
                $actions = '<form method="post" class="inline lock-form">' . csrf_field() . $qs . '<input type="hidden" name="action" value="admin_lock">
                    <input type="hidden" name="user" value="' . (int) $u['id'] . '">
                    <input name="reason" placeholder="Grund (freiwillig)" maxlength="200">
                    <button class="btn ghost sm">Sperren</button></form>';
            }
            $actions .= '<form method="post" class="inline" onsubmit="return confirm(\'Konto von ' . h(addslashes($u['display_name'])) . ' endgültig löschen?\')">'
                . csrf_field() . $qs . '<input type="hidden" name="action" value="admin_delete">
                <input type="hidden" name="user" value="' . (int) $u['id'] . '"><button class="btn danger sm">Löschen</button></form>';
        }
        $rows .= '<tr>
          <td><span class="person"><span class="avatar sm">' . h(initials($u['display_name'])) . '</span>
            <span><b>' . h($u['display_name']) . '</b><small>@' . h($u['username']) . '</small></span></span></td>
          <td>' . $status . '</td>
          <td>' . (int) $u['apps'] . '</td>
          <td>' . h(format_date((int) $u['created_at'])) . '</td>
          <td>' . h(ago((int) $u['last_login'])) . '</td>
          <td><div class="actions">' . $actions . '</div></td>
        </tr>';
    }
    $apps = '';
    foreach (clients() as $cid => $c) {
        $ready = !empty($c['redirect_uris']);
        $apps .= '<li><span class="grow"><b>' . h($c['name']) . '</b><small>' . h($c['url'] ?? '') . '</small></span>
          <span class="badge ' . ($ready ? 'ok' : '') . '">' . ($ready ? 'Angebunden' : 'Noch nicht angebunden') . '</span>
          <span class="count">' . ($perApp[$cid] ?? 0) . ' Benutzer</span></li>';
    }
    render_page('Administration', '<section class="card">
      <div class="head-row"><h1>Administration</h1><a class="btn ghost sm" href="' . h(base_path()) . '">Zu meinem Konto</a></div>
      <div class="kpis">
        <div><b>' . $total . '</b><span>Konten</span></div>
        <div><b>' . $active . '</b><span>Aktiv (7 Tage)</span></div>
        <div><b>' . $locked . '</b><span>Gesperrt</span></div>
      </div>
    </section>
    <section class="card">
      <h2>Apps</h2>
      <ul class="apps admin-apps">' . $apps . '</ul>
    </section>
    <section class="card">
      <div class="head-row"><h2>Konten</h2>
        <form method="get" class="search"><input type="hidden" name="p" value="admin">
          <input type="search" name="q" value="' . h($search) . '" placeholder="Name suchen"></form></div>
      <p class="muted small">Eine Sperre gilt in allen Apps: Gesperrte Personen können sich nirgends mehr anmelden und werden beim nächsten Öffnen abgemeldet.</p>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Person</th><th>Status</th><th>Apps</th><th>Seit</th><th>Zuletzt</th><th></th></tr></thead>
        <tbody>' . ($rows ?: '<tr><td colspan="6" class="muted">Keine Konten gefunden.</td></tr>') . '</tbody>
      </table></div>
    </section>', ['wide' => true]);
    exit;
}

// ---------------------------------------------------------------- Mein Konto
$grants = [];
foreach (q('SELECT * FROM grants WHERE user_id = ? ORDER BY last_used DESC', [$me['id']])->fetchAll() as $g) {
    $grants[$g['client_id']] = $g;
}
$appCards = '';
foreach (clients() as $cid => $c) {
    $g = $grants[$cid] ?? null;
    $appCards .= '<li><a href="' . h($c['url'] ?? '#') . '" class="app-link">
        <span class="app-ico">' . h(initials($c['name'])) . '</span>
        <span><b>' . h($c['name']) . '</b><small>' . h($c['description'] ?? '') . '</small></span></a>'
        . ($g ? '<span class="app-meta">Verbunden · ' . h(ago((int) $g['last_used'])) . '</span>
          <form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="revoke">
          <input type="hidden" name="client" value="' . h($cid) . '"><button class="btn ghost sm">Zugriff entfernen</button></form>'
        : '<span class="app-meta">Noch nicht verwendet</span>') . '</li>';
}
$adminLink = !empty($me['is_admin']) ? '<a class="btn ghost sm" href="' . h(base_path()) . '?p=admin">Administration</a>' : '';
render_page('Mein Konto', '<section class="card profile">
    <span class="avatar xl">' . h(initials($me['display_name'])) . '</span>
    <div><h1>' . h($me['display_name']) . '</h1><p class="muted">@' . h($me['username']) . ' · dabei seit ' . h(format_date((int) $me['created_at'])) . '</p></div>
    <div class="profile-actions">' . $adminLink . '
      <form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button class="btn ghost sm">Abmelden</button></form></div>
  </section>

  <section class="card">
    <h2>Deine Apps</h2>
    <p class="muted small">Melde dich überall mit «Mit ' . $name . ' anmelden» an.</p>
    <ul class="apps">' . $appCards . '</ul>
  </section>

  <section class="card">
    <h2>Profil</h2>
    <form method="post" class="form row-form">' . csrf_field() . '<input type="hidden" name="action" value="profile">
      <label class="field grow"><span>Anzeigename</span><input name="display_name" value="' . h($me['display_name']) . '" maxlength="40" required></label>
      <button class="btn primary">Speichern</button>
    </form>
    <p class="muted small">Die Apps übernehmen den neuen Namen bei der nächsten Anmeldung.</p>
  </section>

  <section class="card">
    <details><summary><h2>Passwort ändern</h2></summary>
    <form method="post" class="form">' . csrf_field() . '<input type="hidden" name="action" value="password">
      <label class="field"><span>Aktuelles Passwort</span><input name="old" type="password" required autocomplete="current-password"></label>
      <label class="field"><span>Neues Passwort</span><input name="new" type="password" required minlength="8" autocomplete="new-password"></label>
      <label class="field"><span>Neues Passwort wiederholen</span><input name="new2" type="password" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary">Passwort ändern</button>
    </form></details>
  </section>' . (empty($me['is_admin']) ? '

  <section class="card">
    <details><summary><h2>Konto löschen</h2></summary>
    <p class="muted small">Dein ' . $name . ' wird endgültig gelöscht. Die Apps behalten ihre eigenen Daten; dort kannst du dich danach nicht mehr mit diesem Konto anmelden.</p>
    <form method="post" class="form" onsubmit="return confirm(\'Konto wirklich endgültig löschen?\')">' . csrf_field() . '<input type="hidden" name="action" value="delete">
      <label class="field"><span>Passwort zur Bestätigung</span><input name="password" type="password" required autocomplete="current-password"></label>
      <button class="btn danger">Konto endgültig löschen</button>
    </form></details>
  </section>' : ''));
