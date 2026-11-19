<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

namespace GlpiPlugin\Officelinks;

use InvalidArgumentException;
use Normalizer;

/**
 * Normalisation, validation et encodage des chemins réseau.
 *
 * Classe pure, sans dépendance à GLPI (seulement les extensions mbstring et
 * intl, exigées par GLPI 11 et 12). C'est la seule logique qui fait foi :
 * le JavaScript ne la reproduit que pour l'aperçu.
 *
 * Sécurité : un chemin UNC placé dans un lien ms-word: peut faire fuiter le
 * hash NetNTLM de l'utilisateur vers l'hôte cible. Seuls les chemins situés
 * sous une racine de $allowedRoots sont donc acceptés, et une liste vide
 * refuse tout.
 */
final class PathNormalizer
{
    /** Longueur maximale du chemin canonique (= taille de la colonne unc_path). */
    public const MAX_LENGTH = 1024;

    /** Longueur maximale de la saisie brute, en octets (avant décodage). */
    private const MAX_INPUT_BYTES = 4096;

    /** Caractères interdits dans un nom de fichier ou de dossier Windows. */
    private const FORBIDDEN_CHARS = ['\\', '/', '<', '>', ':', '"', '|', '?', '*'];

    /** Nombre maximal de passes de décodage %XX pour détecter un « .. » masqué. */
    private const MAX_DECODE_PASSES = 5;

    /** @var list<list<string>> Racines autorisées (segments), de la plus longue à la plus courte. */
    private array $allowedRoots = [];

    /** @var array<string, list<string>> Lettre (« I: ») => segments de la racine UNC. */
    private array $driveMappings = [];

    /** @var list<array{from: list<string>, to: list<string>}> Alias, du plus long au plus court. */
    private array $hostAliases = [];

    /**
     * Les entrées de configuration invalides sont ignorées : une racine
     * invalide n'autorise rien, un lecteur ou un alias invalide ne traduit
     * rien. La page de configuration est chargée de les signaler.
     *
     * @param iterable<mixed>        $allowedRoots  Liste de racines UNC.
     * @param iterable<mixed, mixed> $driveMappings Lettre de lecteur => racine UNC.
     * @param iterable<mixed, mixed> $hostAliases   Chemin d'un serveur cible => chemin DFS.
     */
    public function __construct(iterable $allowedRoots, iterable $driveMappings = [], iterable $hostAliases = [])
    {
        foreach ($allowedRoots as $root) {
            $segments = self::tryParseConfigUnc($root);
            if ($segments !== null) {
                $this->allowedRoots[] = $segments;
            }
        }
        usort($this->allowedRoots, static fn(array $a, array $b): int => count($b) <=> count($a));

        foreach ($driveMappings as $drive => $target) {
            $letter   = self::normalizeDriveKey($drive);
            $segments = self::tryParseConfigUnc($target);
            if ($letter !== null && $segments !== null) {
                $this->driveMappings[$letter] = $segments;
            }
        }

        foreach ($hostAliases as $from => $to) {
            $from_segments = self::tryParseConfigUnc($from);
            $to_segments   = self::tryParseConfigUnc($to);
            if ($from_segments !== null && $to_segments !== null) {
                $this->hostAliases[] = ['from' => $from_segments, 'to' => $to_segments];
            }
        }
        usort($this->hostAliases, static fn(array $a, array $b): int => count($b['from']) <=> count($a['from']));
    }

    /**
     * Indique si la saisie est vide (espaces et guillemets seuls).
     * Côté GLPI, une saisie vide supprime le lien au lieu d'être validée.
     */
    public static function isBlank(string $input): bool
    {
        return self::stripWrapping($input) === '';
    }

    /**
     * Normalise et valide une saisie ; renvoie le chemin UNC canonique.
     *
     * @throws InvalidPathException
     */
    public function normalize(string $input): string
    {
        if (strlen($input) > self::MAX_INPUT_BYTES) {
            throw new InvalidPathException(InvalidPathException::TOO_LONG, 'Input is too long.');
        }

        // 1. Espaces et guillemets autour (« Copier en tant que chemin d'accès »).
        $path = self::stripWrapping($input);
        if ($path === '') {
            throw new InvalidPathException(InvalidPathException::EMPTY, 'Path is empty.');
        }

        // 2. Formes acceptées : file://, lettre de lecteur, \\hôte ou //hôte.
        if (preg_match('#^file:#i', $path) === 1) {
            $path = self::fromFileUri($path);
        }
        self::assertSafeCharacters($path);

        if (preg_match('#^([A-Za-z]):[\\\\/](.*)$#s', $path, $matches) === 1) {
            $segments = $this->fromDrive(strtoupper($matches[1]) . ':', $matches[2]);
        } elseif (preg_match('#^[\\\\/]{2}#', $path) === 1) {
            $segments = self::parseUnc($path);
        } else {
            throw new InvalidPathException(InvalidPathException::NOT_UNC, 'Not a UNC path.');
        }

        // Serveur cible => espace de noms DFS.
        $segments = $this->applyHostAliases($segments);

        // 4. Racine autorisée (segment par segment, sans tenir compte de la casse).
        $segments = $this->applyAllowedRoot($segments);

        // 5. Fichiers uniquement.
        if (!self::hasFileExtension($segments[count($segments) - 1])) {
            throw new InvalidPathException(InvalidPathException::FOLDER, 'Path does not designate a file.');
        }

        $canonical = '\\\\' . implode('\\', $segments);
        if (mb_strlen($canonical, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidPathException(InvalidPathException::TOO_LONG, 'Path is too long.');
        }

        return $canonical;
    }

    /**
     * Convertit un chemin UNC canonique en URI file://, chaque segment étant
     * encodé séparément avec rawurlencode() (UTF-8 : espaces, accents, #, %, &).
     *
     * Exemple : \\h.example.com\DFS\a b.docx => file://h.example.com/DFS/a%20b.docx
     *
     * @param string $canonical Chemin renvoyé par normalize().
     */
    public static function toFileUri(string $canonical): string
    {
        if (!str_starts_with($canonical, '\\\\') || str_contains($canonical, '/')) {
            throw new InvalidArgumentException('A canonical UNC path is expected.');
        }

        $segments = explode('\\', substr($canonical, 2));
        if (count($segments) < 2 || in_array('', $segments, true)) {
            throw new InvalidArgumentException('A canonical UNC path is expected.');
        }

        return 'file://' . implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * Supprime les blancs (espace, tabulation, retours à la ligne) et une
     * paire de guillemets droits autour de la saisie.
     */
    private static function stripWrapping(string $input): string
    {
        $blanks = " \t\r\n";
        $path   = trim($input, $blanks);
        if (strlen($path) >= 2 && $path[0] === '"' && $path[strlen($path) - 1] === '"') {
            $path = trim(substr($path, 1, -1), $blanks);
        }
        return $path;
    }

    /**
     * file://hôte/partage/... => //hôte/partage/... ; file:///I:/... => I:/...
     * Les séquences %XX sont décodées (une passe, comme le ferait Windows).
     */
    private static function fromFileUri(string $uri): string
    {
        if (preg_match('#^file:(//.*)$#is', $uri, $matches) !== 1) {
            throw new InvalidPathException(InvalidPathException::NOT_UNC, 'Malformed file URI.');
        }
        if (preg_match('#[?\#]#', $matches[1]) === 1) {
            // Requête ou fragment : pas un chemin de fichier.
            throw new InvalidPathException(InvalidPathException::FORBIDDEN_CHAR, 'Query or fragment in file URI.');
        }

        $decoded = rawurldecode($matches[1]);

        // file:///I:/dossier => I:/dossier
        if (preg_match('#^/+([A-Za-z]:[\\\\/].*)$#s', $decoded, $drive) === 1) {
            return $drive[1];
        }
        return $decoded;
    }

    /**
     * UTF-8 valide, sans caractère de contrôle ni de mise en forme
     * bidirectionnelle (qui permettrait de maquiller l'extension affichée).
     */
    private static function assertSafeCharacters(string $path): void
    {
        if (!mb_check_encoding($path, 'UTF-8')) {
            throw new InvalidPathException(InvalidPathException::INVALID_ENCODING, 'Invalid UTF-8.');
        }
        if (preg_match('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $path) === 1) {
            throw new InvalidPathException(InvalidPathException::CONTROL_CHAR, 'Control character in path.');
        }
    }

    /**
     * Lettre de lecteur => racine UNC configurée + reste du chemin.
     *
     * @return list<string>
     */
    private function fromDrive(string $letter, string $rest): array
    {
        if (!isset($this->driveMappings[$letter])) {
            throw new InvalidPathException(InvalidPathException::UNMAPPED_DRIVE, 'Unmapped drive letter.');
        }
        // Le reste est analysé comme la suite d'un chemin UNC fictif.
        $rest_segments = self::splitSegments($rest);
        if ($rest_segments === []) {
            throw new InvalidPathException(InvalidPathException::FOLDER, 'Path does not designate a file.');
        }
        return array_merge($this->driveMappings[$letter], $rest_segments);
    }

    /**
     * Analyse un chemin commençant par deux séparateurs (\ ou /).
     *
     * @return list<string> Segments : hôte, partage, puis le reste.
     */
    private static function parseUnc(string $path): array
    {
        $body = ltrim(str_replace('/', '\\', $path), '\\');

        // Préfixes de périphérique Win32 : \\?\..., \\.\...
        $host = explode('\\', $body, 2)[0];
        if ($host === '?' || $host === '.') {
            throw new InvalidPathException(InvalidPathException::DEVICE_PATH, 'Win32 device path.');
        }

        $segments = self::splitSegments($body);
        if (count($segments) < 2) {
            throw new InvalidPathException(InvalidPathException::NOT_UNC, 'UNC path without share.');
        }
        return $segments;
    }

    /**
     * Découpe, fusionne les séparateurs doublés, retire « . », rejette « .. ».
     *
     * @return list<string>
     */
    private static function splitSegments(string $path): array
    {
        $normalized = str_replace('/', '\\', $path);
        if ($normalized !== '' && str_ends_with($normalized, '\\')) {
            throw new InvalidPathException(InvalidPathException::FOLDER, 'Path ends with a separator.');
        }

        $segments = [];
        foreach (explode('\\', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue; // séparateurs doublés, segment courant
            }
            self::assertValidSegment($segment);
            $segments[] = $segment;
        }
        return $segments;
    }

    /**
     * Rejette les segments dangereux, y compris sous forme encodée (%2e%2e,
     * %252e...) ou en caractères de compatibilité Unicode (points et barres
     * pleine chasse, ramenés à l'ASCII par certaines conversions Windows).
     */
    private static function assertValidSegment(string $segment): void
    {
        $compat = Normalizer::normalize($segment, Normalizer::FORM_KC);
        if ($compat === false) {
            throw new InvalidPathException(InvalidPathException::INVALID_ENCODING, 'Invalid Unicode.');
        }

        // Segment composé uniquement de points et d'espaces (« .. », « ... », « .. »).
        $decoded = $compat;
        for ($i = 0; $i < self::MAX_DECODE_PASSES; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        $decoded = Normalizer::normalize($decoded, Normalizer::FORM_KC);
        if ($decoded === false || trim($decoded, ' .') === '') {
            throw new InvalidPathException(InvalidPathException::DOT_SEGMENT, 'Dot segment in path.');
        }

        foreach (self::FORBIDDEN_CHARS as $char) {
            if (str_contains($compat, $char)) {
                throw new InvalidPathException(InvalidPathException::FORBIDDEN_CHAR, 'Forbidden character in path.');
            }
        }

        // Windows retire les points et espaces finaux : le nom réel serait différent.
        if (str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
            throw new InvalidPathException(InvalidPathException::FORBIDDEN_CHAR, 'Trailing dot or space.');
        }
    }

    /**
     * @param list<string> $segments
     *
     * @return list<string>
     */
    private function applyHostAliases(array $segments): array
    {
        foreach ($this->hostAliases as $alias) {
            if (self::startsWithSegments($segments, $alias['from'])) {
                return array_merge($alias['to'], array_slice($segments, count($alias['from'])));
            }
        }
        return $segments;
    }

    /**
     * Vérifie la racine et la réécrit avec la casse de la configuration.
     *
     * @param list<string> $segments
     *
     * @return list<string>
     */
    private function applyAllowedRoot(array $segments): array
    {
        if ($this->allowedRoots === []) {
            throw new InvalidPathException(InvalidPathException::NO_ALLOWED_ROOTS, 'No allowed root configured.');
        }

        foreach ($this->allowedRoots as $root) {
            if (self::startsWithSegments($segments, $root)) {
                if (count($segments) === count($root)) {
                    // La racine elle-même est un dossier.
                    throw new InvalidPathException(InvalidPathException::FOLDER, 'Path is an allowed root.');
                }
                return array_merge($root, array_slice($segments, count($root)));
            }
        }

        throw new InvalidPathException(InvalidPathException::ROOT_NOT_ALLOWED, 'Path is outside allowed roots.');
    }

    /**
     * Comparaison segment par segment, insensible à la casse (correspondance
     * simple, comme Windows) : \\h\DFS-evil ne commence pas par \\h\DFS.
     *
     * @param list<string> $segments
     * @param list<string> $prefix
     */
    private static function startsWithSegments(array $segments, array $prefix): bool
    {
        if (count($segments) < count($prefix)) {
            return false;
        }
        foreach ($prefix as $i => $expected) {
            if (self::foldCase($segments[$i]) !== self::foldCase($expected)) {
                return false;
            }
        }
        return true;
    }

    private static function foldCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_UPPER_SIMPLE, 'UTF-8');
    }

    /**
     * Le dernier segment doit porter une extension (« nom.ext »).
     */
    private static function hasFileExtension(string $name): bool
    {
        return preg_match('/^.+\.[^.\s]+$/su', $name) === 1;
    }

    /**
     * Racine UNC de configuration (hôte + partage au minimum) en segments,
     * ou null si l'entrée est invalide.
     *
     * @return list<string>|null
     */
    private static function tryParseConfigUnc(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }
        $path = self::stripWrapping($value);
        try {
            self::assertSafeCharacters($path);
            if (preg_match('#^[\\\\/]{2}#', $path) !== 1) {
                return null;
            }
            // Une racine se termine souvent par « \ » : on le tolère ici.
            return self::parseUnc(rtrim($path, '\\/'));
        } catch (InvalidPathException) {
            return null;
        }
    }

    /**
     * Forme canonique d'un chemin UNC de configuration (racine, cible de
     * lecteur, alias) : hôte et partage au minimum, séparateurs « \ »,
     * sans « \ » final. Renvoie null si l'entrée est invalide.
     *
     * Utilisée par la page de configuration ; le constructeur applique la
     * même règle et ignore les entrées refusées.
     */
    public static function canonicalizeConfigPath(mixed $value): ?string
    {
        $segments = self::tryParseConfigUnc($value);
        if ($segments === null) {
            return null;
        }
        $canonical = '\\\\' . implode('\\', $segments);
        return mb_strlen($canonical, 'UTF-8') <= self::MAX_LENGTH ? $canonical : null;
    }

    /**
     * Indique si un dossier UNC canonique (après application des alias
     * d'hôtes) est une racine autorisée ou se trouve sous l'une d'elles.
     * Sert à signaler un lecteur ou un alias qui ne mènerait à rien.
     */
    public function isUnderAllowedRoot(string $canonical): bool
    {
        $segments = self::tryParseConfigUnc($canonical);
        if ($segments === null) {
            return false;
        }
        $segments = $this->applyHostAliases($segments);
        foreach ($this->allowedRoots as $root) {
            if (self::startsWithSegments($segments, $root)) {
                return true;
            }
        }
        return false;
    }

    /**
     * « i », « I: », « i:\ » => « I: » ; null si ce n'est pas une lettre.
     */
    public static function normalizeDriveKey(mixed $key): ?string
    {
        if (!is_string($key)) {
            return null;
        }
        if (preg_match('#^\s*([A-Za-z]):?[\\\\/]?\s*$#', $key, $matches) !== 1) {
            return null;
        }
        return strtoupper($matches[1]) . ':';
    }
}
