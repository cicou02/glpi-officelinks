<?php

/**
 * OfficeLinks — mise à jour des traductions (développement uniquement).
 *
 *   php tools/locales.php            extraction + mise à jour des .po + compilation
 *   php tools/locales.php extract    locales/officelinks.pot seulement
 *   php tools/locales.php compile    .mo seulement (à partir des .po)
 *
 * Les .po (locales/fr_FR.po, locales/en_GB.po) sont les sources à éditer ;
 * les .mo sont générés et versionnés (GLPI ne lit que les .mo).
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Officelinks\Tools\Locales;

require dirname(__DIR__) . '/vendor/autoload.php';

$root    = dirname(__DIR__);
$locales = $root . '/locales';
$command = $argv[1] ?? 'all';

if (!in_array($command, ['all', 'extract', 'compile'], true)) {
    fwrite(STDERR, "Usage: php tools/locales.php [all|extract|compile]\n");
    exit(2);
}

$entries = Locales::extract($root);

if ($command !== 'compile') {
    file_put_contents($locales . '/officelinks.pot', Locales::renderPot($entries));
    echo count($entries), " chaînes extraites dans locales/officelinks.pot\n";
}

if ($command === 'all') {
    foreach (array_keys(Locales::TARGETS) as $po_file) {
        $path     = $locales . '/' . $po_file;
        $existing = is_file($path) ? Locales::parsePo((string) file_get_contents($path)) : [];
        $header   = $existing['']['msgstr'] ?? '';

        $out = "msgid \"\"\nmsgstr \"\"\n";
        foreach (array_filter(explode("\n", $header), static fn($l) => $l !== '') as $line) {
            $out .= Locales::quote($line . "\n") . "\n";
        }

        $missing = [];
        foreach ($entries as $key => $entry) {
            $msgstr = $existing[$key]['msgstr'] ?? '';
            if (!isset($existing[$key])) {
                $missing[] = $entry['msgid'];
            }
            $out .= "\n#: " . implode(' ', $entry['references']) . "\n";
            if ($entry['context'] !== null) {
                $out .= 'msgctxt ' . Locales::quote($entry['context']) . "\n";
            }
            $out .= 'msgid ' . Locales::quote($entry['msgid']) . "\n";
            $out .= 'msgstr ' . Locales::quote($msgstr) . "\n";
        }
        $obsolete = array_diff_key($existing, $entries, ['' => true]);

        file_put_contents($path, $out);
        echo $po_file, ' : ', count($missing), ' nouvelle(s), ', count($obsolete), " obsolète(s) retirée(s)\n";
        foreach ($missing as $msgid) {
            echo '  + ', $msgid, "\n";
        }
        foreach ($obsolete as $entry) {
            echo '  - ', $entry['msgid'], "\n";
        }
    }
}

if ($command !== 'extract') {
    foreach (Locales::TARGETS as $po_file => $mo_files) {
        $entries_po = Locales::parsePo((string) file_get_contents($locales . '/' . $po_file));
        $mo         = Locales::compileMo($entries_po);
        foreach ($mo_files as $mo_file) {
            file_put_contents($locales . '/' . $mo_file, $mo);
        }
        echo $po_file, ' -> ', implode(', ', $mo_files), "\n";
    }
}
