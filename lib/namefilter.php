<?php
/**
 * DXR Pass – Filter für anstössige Namen (gleiche Liste wie Hexa Chat)
 *
 * Prüft Anzeigenamen, gespeicherte Kontaktnamen und Gruppennamen auf
 * beleidigende, sexuelle oder rassistische Begriffe – auch in getarnter
 * Schreibweise (z.B. "Wixxxer", "H.u.r.e", "N1gg3r", "Wichs3r").
 * Treffer werden durch «User» + 6 Zufallsziffern ersetzt.
 *
 * Die Listen unten können jederzeit ergänzt werden. Einträge werden
 * automatisch normalisiert (Gross/klein, Zahlen statt Buchstaben,
 * doppelte Buchstaben usw. spielen keine Rolle).
 */

// Begriff kommt irgendwo in einem Wort vor
const NAME_FILTER_CONTAINS = [
    // Beleidigungen
    'hurensohn', 'huresohn', 'hurenkind', 'wichser', 'arschloch', 'missgeburt', 'schlampe', 'fotze',
    'bastard', 'motherfucker', 'schafseckel', 'schofseckel', 'drecksau', 'hurenbock',
    // Sexuelles
    'kinderporno', 'vergewaltig', 'pornhub', 'titten', 'pimmel', 'vagina', 'penis', 'bitch', 'whore',
    'blowjob', 'wichsen', 'orgasm',
    // Rassismus und Hetze
    'nigger', 'neger', 'kanake', 'zigeuner', 'schlitzauge', 'untermensch', 'judensau', 'schwuchtel',
    'hitler', 'neonazi', 'siegheil', 'heilhitler', 'retard',
];

// Wort beginnt mit dem Begriff
const NAME_FILTER_PREFIX = [
    'hure', 'arsch', 'spast', 'fotz', 'wix', 'porn', 'ficken', 'ficker', 'fucker', 'fucking',
];

// Wort entspricht genau dem Begriff
const NAME_FILTER_EXACT = [
    'fick', 'fickt', 'fuck', 'sex', 'sexy', 'nutte', 'nutten', 'nazi', 'nazis', 'nigga', 'niggas',
    'slut', 'cunt', 'faggot', 'opfer', 'mongo', 'spasti', 'figge', 'figger', 'kz',
];

/** Kleinbuchstaben, Ersatzzeichen (0→o, 1→i, @→a …) und Akzente vereinheitlichen. */
function name_filter_normalize(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = strtr($s, [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'á' => 'a',
        'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ì' => 'i', 'í' => 'i', 'ô' => 'o', 'ò' => 'o', 'ó' => 'o', 'ù' => 'u',
        'ú' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
        '0' => 'o', '1' => 'i', '!' => 'i', '|' => 'i', '3' => 'e', '€' => 'e', '4' => 'a', '@' => 'a', '5' => 's',
        '$' => 's', '7' => 't', '+' => 't', '8' => 'b', '9' => 'g',
    ]);
    // Alles ausser Buchstaben trennt Wörter
    return trim(preg_replace('/[^a-z]+/', ' ', $s));
}

/** Gleich klingende Schreibweisen zusammenführen und doppelte Buchstaben entfernen. */
function name_filter_squash(string $word): string
{
    $word = str_replace(['cks', 'chs', 'ks', 'ck', 'ph', 'qu', 'q', 'y'], ['x', 'x', 'x', 'k', 'f', 'kw', 'k', 'i'], $word);
    return preg_replace('/(.)\1+/', '$1', $word);
}

function name_is_offensive(string $name): bool
{
    $tokens = array_values(array_filter(explode(' ', name_filter_normalize($name))));
    if (!$tokens) {
        return false;
    }
    $words = array_map('name_filter_squash', $tokens);
    // Getarnte Schreibweise wie "H u r e" oder "N.i.g.g.e.r": Buchstaben zusammenziehen
    $short = count(array_filter($tokens, function ($t) {
        return strlen($t) <= 2;
    }));
    $spaced = count($tokens) >= 3 && $short * 2 >= count($tokens);
    $compact = name_filter_squash(implode('', $tokens));
    if ($spaced) {
        $words[] = $compact;
    }

    foreach (NAME_FILTER_CONTAINS as $stem) {
        $stem = name_filter_squash($stem);
        foreach ($words as $w) {
            if (strpos($w, $stem) !== false) {
                return true;
            }
        }
        // Lange Begriffe auch über Wortgrenzen hinweg ("Huren Sohn")
        if (strlen($stem) >= 6 && strpos($compact, $stem) !== false) {
            return true;
        }
    }
    foreach (NAME_FILTER_PREFIX as $stem) {
        $stem = name_filter_squash($stem);
        foreach ($words as $w) {
            if (strpos($w, $stem) === 0) {
                return true;
            }
        }
    }
    foreach (NAME_FILTER_EXACT as $stem) {
        if (in_array(name_filter_squash($stem), $words, true)) {
            return true;
        }
    }
    return false;
}

/** Ersatzname für anstössige Namen, z.B. "User324623" (6 zufällige Ziffern). */
function invented_name(): string
{
    return 'User' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
