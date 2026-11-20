<?php

/**
 * OfficeLinks — outil de traduction (développement uniquement).
 *
 * Remplace xgettext/msgfmt, absents des environnements de développement :
 * - extrait les chaînes du domaine « officelinks » des fichiers PHP et Twig ;
 * - lit et écrit les fichiers .po/.pot ;
 * - compile les .mo lus par GLPI (chargeur gettext de laminas-i18n).
 *
 * Utilisé par tools/locales.php (ligne de commande) et tests/LocalesTest.php.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Officelinks\Tools;

use RuntimeException;

final class Locales
{
    public const DOMAIN = 'officelinks';

    /** Dossiers et fichiers analysés, relatifs à la racine du plugin. */
    private const SOURCES = ['setup.php', 'hook.php', 'front', 'src', 'templates'];

    /**
     * Fichier .po source => fichiers .mo produits.
     * GLPI se rabat sur la langue par défaut de l'instance quand le .mo de la
     * langue de l'utilisateur manque : chaque variante reçoit donc son .mo
     * (les .mo anglais ne contiennent aucune traduction : texte source).
     */
    public const TARGETS = [
        'fr_FR.po' => ['fr_FR.mo', 'fr_CA.mo', 'fr_BE.mo'],
        'en_GB.po' => ['en_GB.mo', 'en_US.mo'],
    ];

    /**
     * Chaînes à traduire trouvées dans les sources.
     *
     * @return array<string, array{context: ?string, msgid: string, references: list<string>}>
     *         Indexé par clé (contexte + "\x04" + msgid, ou msgid).
     */
    public static function extract(string $root): array
    {
        $pattern = '/(?<![\w$>:])(?<fn>__s?|_sx|_x)\(\s*'
            . '(?:\'(?<ctx>(?:\\\\.|[^\'\\\\])*)\'\s*,\s*)?'
            . '\'(?<msg>(?:\\\\.|[^\'\\\\])*)\'\s*,\s*'
            . '\'' . self::DOMAIN . '\'\s*\)/s';

        $entries = [];
        foreach (self::sourceFiles($root) as $relative) {
            $content = (string) file_get_contents($root . '/' . $relative);
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $function   = $match['fn'][0];
                $has_ctx    = in_array($function, ['_x', '_sx'], true);
                $raw_ctx    = isset($match['ctx']) && $match['ctx'][1] !== -1 ? $match['ctx'][0] : null;
                if ($has_ctx !== ($raw_ctx !== null)) {
                    continue; // __('a', 'b', ...) mal reconnu : ignoré
                }
                $context = $raw_ctx === null ? null : self::unescapeSingleQuoted($raw_ctx);
                $msgid   = self::unescapeSingleQuoted($match['msg'][0]);
                $line    = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;
                $key     = self::key($context, $msgid);

                $entries[$key] ??= ['context' => $context, 'msgid' => $msgid, 'references' => []];
                $entries[$key]['references'][] = $relative . ':' . $line;
            }
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    /**
     * Catalogue .pot (sans traductions).
     *
     * @param array<string, array{context: ?string, msgid: string, references: list<string>}> $entries
     */
    public static function renderPot(array $entries): string
    {
        $out = self::renderHeader([
            'Project-Id-Version'        => 'OfficeLinks',
            'Language'                  => '',
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ]);
        foreach ($entries as $entry) {
            $out .= "\n#: " . implode(' ', $entry['references']) . "\n";
            if ($entry['context'] !== null) {
                $out .= 'msgctxt ' . self::quote($entry['context']) . "\n";
            }
            $out .= 'msgid ' . self::quote($entry['msgid']) . "\n";
            $out .= "msgstr \"\"\n";
        }
        return $out;
    }

    /**
     * Lecture d'un .po (sous-ensemble utile : msgctxt, msgid, msgstr,
     * chaînes sur plusieurs lignes, commentaires ignorés).
     *
     * @return array<string, array{context: ?string, msgid: string, msgstr: string}>
     *         Indexé par clé ; l'en-tête a la clé ''.
     */
    public static function parsePo(string $content): array
    {
        $entries = [];
        $current = null;
        $field   = null;

        $flush = static function () use (&$entries, &$current): void {
            if ($current !== null && $current['msgid'] !== null) {
                $key = self::key($current['context'], $current['msgid']);
                $entries[$key] = [
                    'context' => $current['context'],
                    'msgid'   => $current['msgid'],
                    'msgstr'  => $current['msgstr'] ?? '',
                ];
            }
            $current = null;
        };

        foreach (preg_split('/\R/', $content) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                if ($line === '') {
                    $flush();
                    $field = null;
                }
                continue;
            }
            if (preg_match('/^(msgctxt|msgid|msgstr)\s+(".*")$/', $line, $m) === 1) {
                if ($m[1] === 'msgctxt' || ($m[1] === 'msgid' && $current !== null && $current['msgid'] !== null)) {
                    $flush();
                }
                $current ??= ['context' => null, 'msgid' => null, 'msgstr' => null];
                $field = $m[1] === 'msgctxt' ? 'context' : $m[1];
                $current[$field] = self::unquote($m[2], $number + 1);
                continue;
            }
            if ($line[0] === '"' && $current !== null && $field !== null) {
                $current[$field] .= self::unquote($line, $number + 1);
                continue;
            }
            throw new RuntimeException(sprintf('Unsupported PO syntax at line %d: %s', $number + 1, $line));
        }
        $flush();
        return $entries;
    }

    /**
     * Fichier .mo (format GNU, petit-boutiste, sans table de hachage).
     * Les entrées non traduites sont omises, comme le fait msgfmt.
     *
     * @param array<string, array{context: ?string, msgid: string, msgstr: string}> $entries
     */
    public static function compileMo(array $entries): string
    {
        $strings = [];
        foreach ($entries as $key => $entry) {
            if ($key !== '' && $entry['msgstr'] === '') {
                continue;
            }
            $strings[$key] = $entry['msgstr'];
        }
        ksort($strings, SORT_STRING);

        $count        = count($strings);
        $originals_at = 28;
        $translations_at = $originals_at + 8 * $count;
        $data_at      = $translations_at + 8 * $count;

        $originals_table    = '';
        $translations_table = '';
        $originals_data     = '';
        $translations_data  = '';

        foreach (array_keys($strings) as $original) {
            $originals_table .= pack('VV', strlen((string) $original), $data_at + strlen($originals_data));
            $originals_data  .= $original . "\0";
        }
        $translations_start = $data_at + strlen($originals_data);
        foreach ($strings as $translation) {
            $translations_table .= pack('VV', strlen($translation), $translations_start + strlen($translations_data));
            $translations_data  .= $translation . "\0";
        }

        return pack('VVVVVVV', 0x950412de, 0, $count, $originals_at, $translations_at, 0, $data_at)
            . $originals_table . $translations_table . $originals_data . $translations_data;
    }

    /**
     * Fichiers sources, relatifs à la racine, triés.
     *
     * @return list<string>
     */
    private static function sourceFiles(string $root): array
    {
        $files = [];
        foreach (self::SOURCES as $source) {
            $path = $root . '/' . $source;
            if (is_file($path)) {
                $files[] = $source;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (preg_match('/\.(php|twig)$/', $file->getFilename()) === 1) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    public static function key(?string $context, string $msgid): string
    {
        return $context === null ? $msgid : $context . "\x04" . $msgid;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function renderHeader(array $headers): string
    {
        $value = '';
        foreach ($headers as $name => $content) {
            $value .= $name . ': ' . $content . "\n";
        }
        return "msgid \"\"\nmsgstr \"\"\n" . implode("\n", array_map(
            static fn(string $line): string => self::quote($line . "\n"),
            array_filter(explode("\n", $value), static fn(string $l): bool => $l !== '')
        )) . "\n";
    }

    /** Chaîne PHP/Twig entre apostrophes : seuls \\ et \' sont des échappements. */
    private static function unescapeSingleQuoted(string $value): string
    {
        return preg_replace('/\\\\([\\\\\'])/', '$1', $value) ?? $value;
    }

    public static function quote(string $value): string
    {
        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"'  => '\\"',
            "\n" => '\\n',
            "\t" => '\\t',
            "\r" => '\\r',
        ]) . '"';
    }

    private static function unquote(string $quoted, int $line): string
    {
        if (preg_match('/^"((?:\\\\.|[^"\\\\])*)"$/', $quoted, $m) !== 1) {
            throw new RuntimeException(sprintf('Invalid PO string at line %d.', $line));
        }
        return preg_replace_callback('/\\\\(.)/', static function (array $esc) use ($line): string {
            return match ($esc[1]) {
                '\\'    => '\\',
                '"'     => '"',
                'n'     => "\n",
                't'     => "\t",
                'r'     => "\r",
                default => throw new RuntimeException(sprintf('Unsupported escape \\%s at line %d.', $esc[1], $line)),
            };
        }, $m[1]) ?? $m[1];
    }
}
