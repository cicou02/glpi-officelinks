<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

namespace GlpiPlugin\Officelinks;

/**
 * Configuration du plugin : conversion entre la saisie du formulaire,
 * l'objet en mémoire et les valeurs stockées (texte JSON dans glpi_configs).
 *
 * Classe pure, sans dépendance à GLPI : les erreurs sont renvoyées sous
 * forme de motifs (constantes ERROR_*), traduits par la couche GLPI.
 */
final class Settings
{
    /** Chemin UNC invalide (hôte et partage au minimum). */
    public const ERROR_INVALID_UNC = 'invalid_unc';

    /** Lettre de lecteur invalide. */
    public const ERROR_INVALID_DRIVE = 'invalid_drive';

    /** Ligne sans séparateur « = ». */
    public const ERROR_MISSING_SEPARATOR = 'missing_separator';

    /** Lettre de lecteur ou source d'alias déjà définie. */
    public const ERROR_DUPLICATE = 'duplicate';

    /** Cible de lecteur ou d'alias en dehors des racines autorisées. */
    public const ERROR_OUTSIDE_ROOTS = 'outside_roots';

    /** Alias dont la source et la cible sont identiques. */
    public const ERROR_SELF_ALIAS = 'self_alias';

    public const FIELD_ALLOWED_ROOTS  = 'allowed_roots';
    public const FIELD_DRIVE_MAPPINGS = 'drive_mappings';
    public const FIELD_HOST_ALIASES   = 'host_aliases';

    /**
     * @param list<string>          $allowedRoots    Racines UNC canoniques.
     * @param array<string, string> $driveMappings   « I: » => racine UNC canonique.
     * @param array<string, string> $hostAliases     Chemin serveur cible => chemin DFS (canoniques).
     * @param bool                  $enableFileLinks Bouton « Afficher dans l'Explorateur ».
     */
    public function __construct(
        public readonly array $allowedRoots = [],
        public readonly array $driveMappings = [],
        public readonly array $hostAliases = [],
        public readonly bool $enableFileLinks = false,
    ) {
    }

    /**
     * Valeurs par défaut stockées à l'installation (tout vide, liens file:// désactivés).
     *
     * @return array<string, string>
     */
    public static function defaultStored(): array
    {
        return (new self())->toStored();
    }

    /**
     * Lecture des valeurs stockées. Tolérante : une valeur absente ou un JSON
     * illisible donne une liste vide, et les entrées invalides sont écartées
     * (donc jamais d'élargissement de ce qui est accepté).
     *
     * @param array<string, mixed> $stored Résultat de Config::getConfigurationValues().
     */
    public static function fromStored(array $stored): self
    {
        $roots = [];
        foreach (self::decodeJson($stored['allowed_roots'] ?? null) as $root) {
            $canonical = PathNormalizer::canonicalizeConfigPath($root);
            if ($canonical !== null) {
                $roots[] = $canonical;
            }
        }

        $drives = [];
        foreach (self::decodeJson($stored['drive_mappings'] ?? null) as $letter => $target) {
            $key       = PathNormalizer::normalizeDriveKey($letter);
            $canonical = PathNormalizer::canonicalizeConfigPath($target);
            if ($key !== null && $canonical !== null) {
                $drives[$key] = $canonical;
            }
        }

        $aliases = [];
        foreach (self::decodeJson($stored['host_aliases'] ?? null) as $from => $to) {
            $from_canonical = PathNormalizer::canonicalizeConfigPath($from);
            $to_canonical   = PathNormalizer::canonicalizeConfigPath($to);
            if ($from_canonical !== null && $to_canonical !== null) {
                $aliases[$from_canonical] = $to_canonical;
            }
        }

        return new self(
            self::uniqueCaseInsensitive($roots),
            $drives,
            $aliases,
            ($stored['enable_file_links'] ?? '0') === '1'
        );
    }

    /**
     * Valeurs à stocker (toutes des chaînes).
     *
     * @return array<string, string>
     */
    public function toStored(): array
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        return [
            'allowed_roots'     => json_encode($this->allowedRoots, $flags),
            'drive_mappings'    => json_encode((object) $this->driveMappings, $flags),
            'host_aliases'      => json_encode((object) $this->hostAliases, $flags),
            'enable_file_links' => $this->enableFileLinks ? '1' : '0',
        ];
    }

    /**
     * Analyse le formulaire de configuration.
     *
     * Formats (une entrée par ligne, lignes vides ignorées) :
     * - racines : \\hôte\partage[\dossier...]
     * - lecteurs : I: = \\hôte\partage[\dossier...]
     * - alias : \\serveur\partage = \\hôte\espace-de-noms[\dossier...]
     *
     * @return array{settings: self, errors: list<array{field: string, line: int, value: string, reason: string}>}
     *         Les réglages ne doivent être enregistrés que si errors est vide.
     */
    public static function fromForm(string $roots_text, string $drives_text, string $aliases_text, bool $enable_file_links): array
    {
        $errors = [];

        // Racines autorisées.
        $roots = [];
        foreach (self::lines($roots_text) as $line_no => $line) {
            $canonical = PathNormalizer::canonicalizeConfigPath($line);
            if ($canonical === null) {
                $errors[] = self::error(self::FIELD_ALLOWED_ROOTS, $line_no, $line, self::ERROR_INVALID_UNC);
                continue;
            }
            $roots[] = $canonical;
        }
        $roots = self::uniqueCaseInsensitive($roots);

        // Alias d'hôtes.
        $aliases     = [];
        $alias_lines = []; // source repliée => [n° de ligne, ligne]
        foreach (self::lines($aliases_text) as $line_no => $line) {
            $pair = self::splitPair($line);
            if ($pair === null) {
                $errors[] = self::error(self::FIELD_HOST_ALIASES, $line_no, $line, self::ERROR_MISSING_SEPARATOR);
                continue;
            }
            $from = PathNormalizer::canonicalizeConfigPath($pair[0]);
            $to   = PathNormalizer::canonicalizeConfigPath($pair[1]);
            if ($from === null || $to === null) {
                $errors[] = self::error(self::FIELD_HOST_ALIASES, $line_no, $line, self::ERROR_INVALID_UNC);
                continue;
            }
            if (self::foldCase($from) === self::foldCase($to)) {
                $errors[] = self::error(self::FIELD_HOST_ALIASES, $line_no, $line, self::ERROR_SELF_ALIAS);
                continue;
            }
            if (isset($alias_lines[self::foldCase($from)])) {
                $errors[] = self::error(self::FIELD_HOST_ALIASES, $line_no, $line, self::ERROR_DUPLICATE);
                continue;
            }
            $alias_lines[self::foldCase($from)] = [$line_no, $line];
            $aliases[$from] = $to;
        }

        // Lecteurs.
        $drives      = [];
        $drive_lines = []; // lettre => [n° de ligne, ligne]
        foreach (self::lines($drives_text) as $line_no => $line) {
            $pair = self::splitPair($line);
            if ($pair === null) {
                $errors[] = self::error(self::FIELD_DRIVE_MAPPINGS, $line_no, $line, self::ERROR_MISSING_SEPARATOR);
                continue;
            }
            $letter = PathNormalizer::normalizeDriveKey($pair[0]);
            if ($letter === null) {
                $errors[] = self::error(self::FIELD_DRIVE_MAPPINGS, $line_no, $line, self::ERROR_INVALID_DRIVE);
                continue;
            }
            $target = PathNormalizer::canonicalizeConfigPath($pair[1]);
            if ($target === null) {
                $errors[] = self::error(self::FIELD_DRIVE_MAPPINGS, $line_no, $line, self::ERROR_INVALID_UNC);
                continue;
            }
            if (isset($drives[$letter])) {
                $errors[] = self::error(self::FIELD_DRIVE_MAPPINGS, $line_no, $line, self::ERROR_DUPLICATE);
                continue;
            }
            $drive_lines[$letter] = [$line_no, $line];
            $drives[$letter]      = $target;
        }
        ksort($drives);

        // Une cible hors des racines autorisées ne mènerait qu'à des refus.
        $roots_only = new PathNormalizer($roots);
        foreach ($aliases as $from => $to) {
            if (!$roots_only->isUnderAllowedRoot($to)) {
                [$line_no, $line] = $alias_lines[self::foldCase($from)];
                $errors[] = self::error(self::FIELD_HOST_ALIASES, $line_no, $line, self::ERROR_OUTSIDE_ROOTS);
            }
        }
        $with_aliases = new PathNormalizer($roots, [], $aliases);
        foreach ($drives as $letter => $target) {
            if (!$with_aliases->isUnderAllowedRoot($target)) {
                [$line_no, $line] = $drive_lines[$letter];
                $errors[] = self::error(self::FIELD_DRIVE_MAPPINGS, $line_no, $line, self::ERROR_OUTSIDE_ROOTS);
            }
        }

        usort($errors, static fn(array $a, array $b): int => [$a['field'], $a['line']] <=> [$b['field'], $b['line']]);

        return [
            'settings' => new self($roots, $drives, $aliases, $enable_file_links),
            'errors'   => $errors,
        ];
    }

    /**
     * Texte des champs du formulaire, une entrée par ligne.
     *
     * @return array{allowed_roots: string, drive_mappings: string, host_aliases: string}
     */
    public function toFormText(): array
    {
        $drives = [];
        foreach ($this->driveMappings as $letter => $target) {
            $drives[] = $letter . ' = ' . $target;
        }
        $aliases = [];
        foreach ($this->hostAliases as $from => $to) {
            $aliases[] = $from . ' = ' . $to;
        }
        return [
            self::FIELD_ALLOWED_ROOTS  => implode("\n", $this->allowedRoots),
            self::FIELD_DRIVE_MAPPINGS => implode("\n", $drives),
            self::FIELD_HOST_ALIASES   => implode("\n", $aliases),
        ];
    }

    /**
     * Normaliseur construit à partir de ces réglages.
     */
    public function createNormalizer(): PathNormalizer
    {
        return new PathNormalizer($this->allowedRoots, $this->driveMappings, $this->hostAliases);
    }

    /**
     * Lignes non vides, indexées par numéro de ligne (à partir de 1).
     *
     * @return array<int, string>
     */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[$index + 1] = $line;
            }
        }
        return $lines;
    }

    /**
     * « gauche = droite » ; coupe au premier « = ».
     *
     * @return array{string, string}|null
     */
    private static function splitPair(string $line): ?array
    {
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return null;
        }
        return [trim($parts[0]), trim($parts[1])];
    }

    /**
     * @return array<mixed>
     */
    private static function decodeJson(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function uniqueCaseInsensitive(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $unique[self::foldCase($value)] ??= $value;
        }
        return array_values($unique);
    }

    private static function foldCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_UPPER_SIMPLE, 'UTF-8');
    }

    /**
     * @return array{field: string, line: int, value: string, reason: string}
     */
    private static function error(string $field, int $line, string $value, string $reason): array
    {
        return ['field' => $field, 'line' => $line, 'value' => $value, 'reason' => $reason];
    }
}
