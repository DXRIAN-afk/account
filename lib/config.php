<?php
/**
 * dartx account – Konfiguration
 *
 * Standard: SQLite (keine Einrichtung nötig, die Datenbank-Datei wird
 * automatisch im Ordner /data angelegt). Für MySQL 'driver' => 'mysql'
 * setzen und die Zugangsdaten eintragen.
 *
 * Das Admin-Passwort gehört in lib/config.local.php (nicht hier).
 */
return [
    // Name des Kontos, wie er überall angezeigt wird («Mit dartx account anmelden»)
    'name' => 'dartx account',

    'driver' => 'sqlite', // 'sqlite' oder 'mysql'
    'sqlite_path' => __DIR__ . '/../data/konto.sqlite',
    'mysql' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'dxr_pass',
        'user'     => 'DEIN_DB_BENUTZER',
        'password' => 'DEIN_DB_PASSWORT',
    ],

    'timezone' => 'Europe/Zurich',
    'session_days' => 180,

    // Administrator: meldet sich mit diesem Benutzernamen und dem Passwort
    // aus lib/config.local.php an. Das Konto wird beim ersten Mal angelegt.
    'admin' => [
        'username' => 'Admin',
        'password' => '',
    ],

    // Betreiber (erscheint in den Nutzungsbedingungen)
    'operator' => [
        'name'    => '',
        'contact' => '',
    ],

    /*
     * Apps, die sich mit dem Konto anmelden dürfen.
     * redirect_uris: genaue Rücksprung-Adressen der App (nur diese sind erlaubt).
     * Apps ohne redirect_uris erscheinen in der Übersicht, können sich aber noch nicht anmelden.
     */
    'clients' => [
        'hexa' => [
            'name'          => 'Hexa Chat',
            'description'   => 'Sicher chatten und telefonieren',
            'url'           => 'https://hexa.da-rtx.ch/',
            'redirect_uris' => ['https://hexa.da-rtx.ch/api/index.php?a=sso_callback'],
        ],
        'browser' => [
            'name'          => 'SchoolWeb Browser',
            'description'   => 'Der Browser für die Schule',
            'url'           => 'https://browser.da-rtx.ch/',
            'redirect_uris' => [],
        ],
        'pc95' => [
            'name'          => 'PC95',
            'description'   => 'Retro-PC im Browser',
            'url'           => 'https://pc95.da-rtx.ch/',
            'redirect_uris' => [],
        ],
        'schul-app' => [
            'name'          => 'Schul-App',
            'description'   => 'Hausaufgaben, Prüfungen und Noten',
            'url'           => 'https://schul-app.da-rtx.ch/',
            'redirect_uris' => ['https://schul-app.da-rtx.ch/sso.php'],
        ],
    ],
];
