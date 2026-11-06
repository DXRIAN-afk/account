# dartx account

Ein Konto für alle Apps unter **da-rtx.ch** – wie «Mit Google anmelden».
Man registriert sich einmal unter `account.da-rtx.ch` und meldet sich danach bei
Hexa Chat, SchoolWeb Browser, PC95 und der Schul-App mit **«Mit dartx account anmelden»** an.

## Funktionen

- **Registrieren und Anmelden** mit Benutzername und Passwort (sicher gehasht)
- **Einmal anmelden genügt:** Wer bei dartx account angemeldet ist, kommt in jede App mit einem Klick
- **Mein Konto:** Anzeigename, Passwort ändern, verbundene Apps sehen und den Zugriff entfernen, Konto löschen
- **Administration:** alle Konten, Suche, Sperren (mit Grund), Entsperren, Löschen – eine Sperre gilt in **allen** Apps
- **Namensfilter:** beleidigende Namen werden automatisch durch «User» + 6 Ziffern ersetzt
- Schutz vor zu vielen Anmeldeversuchen, CSRF-Schutz, keine Werbung, kein Tracking

## Installation per FTP

1. Den **Inhalt** dieses Repositorys in den Ordner der Subdomain `account.da-rtx.ch` hochladen,
   auch die versteckten Dateien `.htaccess` (im Hauptordner, in `lib/` und in `data/`).
2. Der Ordner `data/` muss für PHP beschreibbar sein (FTP-Rechte `755`, falls nötig `775`).
3. In `lib/config.local.php` das **Admin-Passwort** eintragen und hochladen.
4. `https://account.da-rtx.ch` öffnen und dich mit **Elix** und diesem Passwort anmelden –
   das Admin-Konto wird beim ersten Mal angelegt. Unter «Administration» siehst du alle Konten.

Die Datenbank (SQLite) entsteht automatisch. Für MySQL in `lib/config.php` `'driver' => 'mysql'` setzen.

## Apps anbinden

Die Apps stehen in `lib/config.php` unter `clients`. Eine App darf sich nur anmelden,
wenn ihre genaue Rücksprung-Adresse unter `redirect_uris` steht:

```php
'hexa' => [
    'name'          => 'Hexa Chat',
    'url'           => 'https://hexa.da-rtx.ch/',
    'redirect_uris' => ['https://hexa.da-rtx.ch/api/index.php?a=sso_callback'],
],
```

Hexa Chat ist bereits angebunden. SchoolWeb Browser, PC95 und Schul-App sind eingetragen,
aber noch ohne Rücksprung-Adresse – sie folgen.

### Ablauf für Entwickler (OAuth 2 Authorization Code mit PKCE)

1. Die App erzeugt einen zufälligen `code_verifier` und leitet den Browser weiter zu
   `https://account.da-rtx.ch/authorize.php?response_type=code&client_id=…&redirect_uri=…&state=…&code_challenge=…&code_challenge_method=S256`
   (`code_challenge` = Base64url von SHA-256 des `code_verifier`).
2. dartx account meldet den Benutzer an und leitet zurück zu `redirect_uri?code=…&state=…`.
3. Die App tauscht den Code ein: `POST https://account.da-rtx.ch/token.php` mit
   `grant_type=authorization_code`, `code`, `redirect_uri`, `client_id`, `code_verifier`.
   Antwort: `{ "access_token": "…", "token_type": "Bearer", "user": { "sub", "username", "display_name", "locked", … } }`
4. Mit dem `access_token` fragt die App bei `GET https://account.da-rtx.ch/userinfo.php`
   (Header `Authorization: Bearer …`) regelmässig nach, ob das Konto noch aktiv ist.
   `401` bedeutet: Zugriff entfernt oder Konto gelöscht; `"locked": true` bedeutet: gesperrt.

`sub` ist die feste, zufällige Konto-Nummer – daran erkennt eine App den Benutzer wieder.
Geheime Schlüssel sind nicht nötig; Sicherheit entsteht durch PKCE und die fest eingetragenen Rücksprung-Adressen.

## Dateien

```
index.php           Anmelden, Registrieren, Mein Konto, Administration, Nutzungsbedingungen
authorize.php       «Mit dartx account anmelden» – Bestätigung und Rücksprung zur App
token.php           Code gegen Zugangsschlüssel und Benutzerdaten eintauschen
userinfo.php        Benutzerdaten und Status (aktiv / gesperrt) abfragen
lib/                Programmcode und Einstellungen (für Besucher gesperrt)
data/               Datenbank (für Besucher gesperrt)
assets/             Design, Logo, Schrift Fredoka (SIL Open Font License)
```
