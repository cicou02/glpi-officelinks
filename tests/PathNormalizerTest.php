<?php

/**
 * OfficeLinks — tests de la normalisation, de la validation et de l'encodage.
 *
 * Rappel PHP : dans une chaîne entre apostrophes, '\\' vaut un seul « \ ».
 * '\\\\h\\DFS' représente donc le chemin \\h\DFS.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Officelinks\Tests;

use GlpiPlugin\Officelinks\InvalidPathException;
use GlpiPlugin\Officelinks\PathNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathNormalizerTest extends TestCase
{
    private const INFO   = '\\\\internal.example.com\\DFS\\Informatique';
    private const COMPTA = '\\\\internal.example.com\\DFS\\Compta';

    private static function normalizer(): PathNormalizer
    {
        return new PathNormalizer(
            [self::INFO, self::COMPTA],
            [
                'I:' => self::INFO,
                'k'  => '\\\\srv-fic01\\Partage$',
            ],
            [
                // Serveur cible => espace de noms DFS.
                '\\\\srv-fic01\\Partage$' => self::COMPTA,
            ]
        );
    }

    // ------------------------------------------------------------------
    // Chemins acceptés
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validProvider(): iterable
    {
        $doc = self::INFO . '\\Info\\Docs\\Linux\\Installation de Unattended-Upgrade.docx';

        yield 'UNC canonique' => [$doc, $doc];
        yield 'guillemets de « Copier en tant que chemin d\'accès »' => ['"' . $doc . '"', $doc];
        yield 'espaces et retour à la ligne autour' => ["  \t\"" . $doc . "\"\r\n", $doc];
        yield 'barres obliques //hôte/...' => [
            '//internal.example.com/DFS/Informatique/Info/a.docx',
            self::INFO . '\\Info\\a.docx',
        ];
        yield 'séparateurs mélangés et doublés, segment .' => [
            '\\\\internal.example.com/DFS\\\\Informatique//.\\Info\\\\\\a.docx',
            self::INFO . '\\Info\\a.docx',
        ];
        yield 'racine réécrite avec la casse de la configuration' => [
            '\\\\INTERNAL.EXAMPLE.COM\\dfs\\INFORMATIQUE\\Sous Dossier\\A.DOCX',
            self::INFO . '\\Sous Dossier\\A.DOCX',
        ];
        yield 'URI file:// avec %XX' => [
            'file://internal.example.com/DFS/Informatique/Info/Docs/Linux/Installation%20de%20Unattended-Upgrade.docx',
            $doc,
        ];
        yield 'URI FILE:// en majuscules' => [
            'FILE://internal.example.com/DFS/Informatique/a%20b.xlsx',
            self::INFO . '\\a b.xlsx',
        ];
        yield 'URI file:///I:/... (lecteur mappé)' => [
            'file:///I:/Info/a%C3%A9.docx',
            self::INFO . '\\Info\\aé.docx',
        ];
        yield 'lettre de lecteur mappée' => ['I:\\Info\\a.docx', self::INFO . '\\Info\\a.docx'];
        yield 'lettre de lecteur minuscule et barres obliques' => ['i:/Info/a.docx', self::INFO . '\\Info\\a.docx'];
        yield 'lecteur vers serveur cible puis alias DFS' => ['K:\\Budget\\2026.xlsx', self::COMPTA . '\\Budget\\2026.xlsx'];
        yield 'alias d\'hôte (casse indifférente)' => [
            '\\\\SRV-FIC01\\partage$\\Budget\\2026.xlsx',
            self::COMPTA . '\\Budget\\2026.xlsx',
        ];
        yield 'Unicode et caractères spéciaux' => [
            self::INFO . '\\Données\\Été 2026 #1 & 50% (copie).docx',
            self::INFO . '\\Données\\Été 2026 #1 & 50% (copie).docx',
        ];
        yield 'type non Office' => [self::INFO . '\\Procédure.pdf', self::INFO . '\\Procédure.pdf'];
        yield 'nom contenant deux points consécutifs' => [self::INFO . '\\a..b.docx', self::INFO . '\\a..b.docx'];
        yield 'longueur maximale exacte (1024)' => [
            self::INFO . '\\' . str_repeat('a', 1024 - strlen(self::INFO) - 6) . '.docx',
            self::INFO . '\\' . str_repeat('a', 1024 - strlen(self::INFO) - 6) . '.docx',
        ];
    }

    #[DataProvider('validProvider')]
    public function testValidPaths(string $input, string $expected): void
    {
        $this->assertSame($expected, self::normalizer()->normalize($input));
    }

    public function testNormalizeIsIdempotent(): void
    {
        $normalizer = self::normalizer();
        $once       = $normalizer->normalize('i:/Info//./Données/a b.docx');
        $this->assertSame($once, $normalizer->normalize($once));
    }

    public function testMostSpecificRootWins(): void
    {
        $normalizer = new PathNormalizer([
            '\\\\h\\DFS',
            '\\\\h\\DFS\\Informatique',
        ]);
        // Casse de la racine la plus longue appliquée.
        $this->assertSame(
            '\\\\h\\DFS\\Informatique\\a.docx',
            $normalizer->normalize('\\\\H\\dfs\\informatique\\a.docx')
        );
    }

    public function testRootWithTrailingSeparatorInConfig(): void
    {
        $normalizer = new PathNormalizer(['  "\\\\h\\DFS\\"  ']);
        $this->assertSame('\\\\h\\DFS\\a.docx', $normalizer->normalize('\\\\h\\DFS\\a.docx'));
    }

    // ------------------------------------------------------------------
    // Chemins refusés
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidProvider(): iterable
    {
        // Hôtes et racines non autorisés (fuite NetNTLM).
        yield 'hôte de l\'attaquant' => ['\\\\attaquant\\x\\a.docx', InvalidPathException::ROOT_NOT_ALLOWED];
        yield 'attaquant en file://' => ['file://attaquant/x/a.docx', InvalidPathException::ROOT_NOT_ALLOWED];
        yield 'attaquant en //' => ['//attaquant/x/a.docx', InvalidPathException::ROOT_NOT_ALLOWED];
        yield 'WebDAV' => ['\\\\attaquant@SSL@443\\DavWWWRoot\\a.docx', InvalidPathException::ROOT_NOT_ALLOWED];
        yield 'hôte voisin' => [
            '\\\\internal.example.com.attaquant.net\\DFS\\Informatique\\a.docx',
            InvalidPathException::ROOT_NOT_ALLOWED,
        ];
        yield 'préfixe de racine partiel' => [
            '\\\\internal.example.com\\DFS\\Informatique-evil\\a.docx',
            InvalidPathException::ROOT_NOT_ALLOWED,
        ];
        yield 'racine tronquée' => ['\\\\internal.example.com\\DFS\\Info\\a.docx', InvalidPathException::ROOT_NOT_ALLOWED];
        yield 'parent de la racine' => ['\\\\internal.example.com\\DFS\\a.docx', InvalidPathException::ROOT_NOT_ALLOWED];
        yield 'homoglyphe cyrillique dans l\'hôte' => [
            "\\\\internal.\u{0435}xample.com\\DFS\\Informatique\\a.docx",
            InvalidPathException::ROOT_NOT_ALLOWED,
        ];
        yield 'hôte avec point final' => [
            '\\\\internal.example.com.\\DFS\\Informatique\\a.docx',
            InvalidPathException::FORBIDDEN_CHAR,
        ];

        // Remontée de répertoire.
        yield '..' => [self::INFO . '\\..\\..\\attaquant\\x\\a.docx', InvalidPathException::DOT_SEGMENT];
        yield '.. en fin' => [self::INFO . '\\a\\..', InvalidPathException::DOT_SEGMENT];
        yield '.. avec barres obliques' => ['//internal.example.com/DFS/Informatique/../Compta2/a.docx', InvalidPathException::DOT_SEGMENT];
        yield '%2e%2e' => [self::INFO . '\\%2e%2e\\a.docx', InvalidPathException::DOT_SEGMENT];
        yield '%2E%2E' => [self::INFO . '\\%2E%2E\\a.docx', InvalidPathException::DOT_SEGMENT];
        yield '.%2e' => [self::INFO . '\\.%2e\\a.docx', InvalidPathException::DOT_SEGMENT];
        yield '%252e%252e (double encodage)' => [self::INFO . '\\%252e%252e\\a.docx', InvalidPathException::DOT_SEGMENT];
        yield '%2e%2e dans file://' => [
            'file://internal.example.com/DFS/Informatique/%2e%2e/%2e%2e/attaquant/a.docx',
            InvalidPathException::DOT_SEGMENT,
        ];
        yield '%2e (segment courant encodé)' => [self::INFO . '\\%2e\\a.docx', InvalidPathException::DOT_SEGMENT];
        yield '...' => [self::INFO . '\\...\\a.docx', InvalidPathException::DOT_SEGMENT];
        yield '.. suivi d\'un espace' => [self::INFO . '\\.. \\a.docx', InvalidPathException::DOT_SEGMENT];
        yield 'points pleine chasse' => [self::INFO . "\\\u{FF0E}\u{FF0E}\\a.docx", InvalidPathException::DOT_SEGMENT];
        yield 'barre inverse pleine chasse' => [
            self::INFO . "\\a\u{FF3C}..\u{FF3C}b.docx",
            InvalidPathException::FORBIDDEN_CHAR,
        ];

        // Préfixes de périphérique Win32.
        yield '\\\\?\\UNC\\' => ['\\\\?\\UNC\\internal.example.com\\DFS\\Informatique\\a.docx', InvalidPathException::DEVICE_PATH];
        yield '\\\\?\\C:' => ['\\\\?\\C:\\a.docx', InvalidPathException::DEVICE_PATH];
        yield '\\\\.\\' => ['\\\\.\\UNC\\internal.example.com\\DFS\\Informatique\\a.docx', InvalidPathException::DEVICE_PATH];
        yield '//?/' => ['//?/UNC/internal.example.com/DFS/Informatique/a.docx', InvalidPathException::DEVICE_PATH];
        yield '//./' => ['//./pipe/x.docx', InvalidPathException::DEVICE_PATH];

        // Lecteurs.
        yield 'lettre de lecteur non mappée' => ['Z:\\Info\\a.docx', InvalidPathException::UNMAPPED_DRIVE];
        yield 'lecteur local en file:///' => ['file:///C:/Windows/a.docx', InvalidPathException::UNMAPPED_DRIVE];
        yield 'chemin relatif au lecteur' => ['I:a.docx', InvalidPathException::NOT_UNC];

        // Pas des chemins UNC.
        yield 'http' => ['http://internal.example.com/DFS/a.docx', InvalidPathException::NOT_UNC];
        yield 'https' => ['https://internal.example.com/DFS/a.docx', InvalidPathException::NOT_UNC];
        yield 'javascript:' => ['javascript:alert(1)', InvalidPathException::NOT_UNC];
        yield 'JavaScript: avec espaces' => ['  JavaScript:alert(document.cookie)//a.docx', InvalidPathException::NOT_UNC];
        yield 'ms-word: déjà formé' => [
            'ms-word:ofe|u|file://internal.example.com/DFS/Informatique/a.docx',
            InvalidPathException::NOT_UNC,
        ];
        yield 'data:' => ['data:text/html,<script>alert(1)</script>', InvalidPathException::NOT_UNC];
        yield 'file: sans //' => ['file:a.docx', InvalidPathException::NOT_UNC];
        yield 'chemin local absolu' => ['\\Windows\\a.docx', InvalidPathException::NOT_UNC];
        yield 'chemin relatif' => ['Info\\a.docx', InvalidPathException::NOT_UNC];
        yield 'hôte seul' => ['\\\\internal.example.com', InvalidPathException::NOT_UNC];

        // Saisie vide.
        yield 'chaîne vide' => ['', InvalidPathException::EMPTY];
        yield 'espaces seuls' => ["  \t ", InvalidPathException::EMPTY];
        yield 'guillemets seuls' => ['""', InvalidPathException::EMPTY];

        // Longueur.
        yield 'chemin de 1025 caractères' => [
            self::INFO . '\\' . str_repeat('a', 1025 - strlen(self::INFO) - 6) . '.docx',
            InvalidPathException::TOO_LONG,
        ];
        yield 'longueur en caractères et non en octets' => [
            self::INFO . '\\' . str_repeat('é', 1025 - strlen(self::INFO) - 6) . '.docx',
            InvalidPathException::TOO_LONG,
        ];
        yield 'saisie démesurée' => [self::INFO . '\\' . str_repeat('a\\', 5000) . 'a.docx', InvalidPathException::TOO_LONG];

        // Caractères.
        yield 'octet nul' => [self::INFO . "\\a\0.docx", InvalidPathException::CONTROL_CHAR];
        yield 'tabulation interne' => [self::INFO . "\\a\tb.docx", InvalidPathException::CONTROL_CHAR];
        yield 'retour à la ligne interne' => [self::INFO . "\\a\nb.docx", InvalidPathException::CONTROL_CHAR];
        yield '%00 dans file://' => ['file://internal.example.com/DFS/Informatique/a%00.docx', InvalidPathException::CONTROL_CHAR];
        yield '%0A dans file://' => ['file://internal.example.com/DFS/Informatique/a%0A.docx', InvalidPathException::CONTROL_CHAR];
        yield 'contrôle C1' => [self::INFO . "\\a\u{0085}.docx", InvalidPathException::CONTROL_CHAR];
        yield 'RLO (extension maquillée)' => [self::INFO . "\\facture\u{202E}xcod.exe", InvalidPathException::CONTROL_CHAR];
        yield 'ALM U+061C (Cf)' => [self::INFO . "\\facture\u{061C}.docx", InvalidPathException::CONTROL_CHAR];
        yield 'espace sans chasse U+200B' => [self::INFO . "\\a\u{200B}b.docx", InvalidPathException::CONTROL_CHAR];
        yield 'BOM U+FEFF' => [self::INFO . "\\\u{FEFF}a.docx", InvalidPathException::CONTROL_CHAR];
        yield 'trait d\'union conditionnel U+00AD' => [self::INFO . "\\a\u{00AD}b.docx", InvalidPathException::CONTROL_CHAR];
        yield 'séparateur de ligne U+2028' => [self::INFO . "\\a\u{2028}b.docx", InvalidPathException::CONTROL_CHAR];
        yield 'séparateur de paragraphe U+2029' => [self::INFO . "\\a\u{2029}b.docx", InvalidPathException::CONTROL_CHAR];
        yield 'U+200B encodé dans file://' => ['file://internal.example.com/DFS/Informatique/a%E2%80%8B.docx', InvalidPathException::CONTROL_CHAR];
        yield 'UTF-8 invalide' => [self::INFO . "\\a\xFF.docx", InvalidPathException::INVALID_ENCODING];
        yield '%FF dans file://' => ['file://internal.example.com/DFS/Informatique/a%FF.docx', InvalidPathException::INVALID_ENCODING];
        yield 'flux de données alternatif' => [self::INFO . '\\a.docx:Zone.Identifier', InvalidPathException::FORBIDDEN_CHAR];
        yield 'deux-points pleine chasse' => [self::INFO . "\\a.docx\u{FF1A}x.docx", InvalidPathException::FORBIDDEN_CHAR];
        yield 'joker *' => [self::INFO . '\\*.docx', InvalidPathException::FORBIDDEN_CHAR];
        yield 'joker ?' => [self::INFO . '\\a?.docx', InvalidPathException::FORBIDDEN_CHAR];
        yield 'chevrons' => [self::INFO . '\\<script>.docx', InvalidPathException::FORBIDDEN_CHAR];
        yield 'barre verticale' => [self::INFO . '\\a|b.docx', InvalidPathException::FORBIDDEN_CHAR];
        yield 'yen (best fit cp932)' => [self::INFO . "\\\u{00A5}..\u{00A5}a.docx", InvalidPathException::FORBIDDEN_CHAR];
        yield 'won (best fit cp949)' => [self::INFO . "\\a\u{20A9}b.docx", InvalidPathException::FORBIDDEN_CHAR];
        yield 'barre de division U+2215' => [self::INFO . "\\a\u{2215}b.docx", InvalidPathException::FORBIDDEN_CHAR];
        yield 'différence ensembliste U+2216' => [self::INFO . "\\a\u{2216}b.docx", InvalidPathException::FORBIDDEN_CHAR];
        yield 'guillemet interne' => [self::INFO . '\\a"b.docx', InvalidPathException::FORBIDDEN_CHAR];
        yield 'point final de dossier' => [self::INFO . '\\dossier.\\a.docx', InvalidPathException::FORBIDDEN_CHAR];
        yield 'espace final de fichier' => [self::INFO . "\\a.docx \\b.docx", InvalidPathException::FORBIDDEN_CHAR];
        yield 'requête dans file://' => ['file://internal.example.com/DFS/Informatique/a.docx?x=1', InvalidPathException::FORBIDDEN_CHAR];
        yield 'fragment dans file://' => ['file://internal.example.com/DFS/Informatique/a.docx#x', InvalidPathException::FORBIDDEN_CHAR];

        // Dossiers (v1 : fichiers uniquement).
        yield 'séparateur final' => [self::INFO . '\\Info\\', InvalidPathException::FOLDER];
        yield 'racine elle-même' => [self::INFO, InvalidPathException::FOLDER];
        yield 'dossier sans extension' => [self::INFO . '\\Info', InvalidPathException::FOLDER];
        yield 'lecteur seul' => ['I:\\', InvalidPathException::FOLDER];
        yield 'nom commençant par un point seul' => [self::INFO . '\\.docx', InvalidPathException::FOLDER];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidPaths(string $input, string $reason): void
    {
        try {
            $result = self::normalizer()->normalize($input);
            $this->fail(sprintf('Chemin accepté à tort : %s', $result));
        } catch (InvalidPathException $e) {
            $this->assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function testEmptyAllowedRootsRejectsEverything(): void
    {
        $normalizer = new PathNormalizer([]);
        try {
            $normalizer->normalize(self::INFO . '\\a.docx');
            $this->fail('Chemin accepté sans racine configurée.');
        } catch (InvalidPathException $e) {
            $this->assertSame(InvalidPathException::NO_ALLOWED_ROOTS, $e->getReason());
        }
    }

    public function testInvalidRootsAreIgnoredNotWidened(): void
    {
        // Aucune entrée valide => jamais « tout accepter ».
        $normalizer = new PathNormalizer(['', 'http://x', '\\\\hôte-seul', '\\\\?\\UNC\\h\\s', 'C:\\', 42, null, ['x']]);
        try {
            $normalizer->normalize('\\\\h\\s\\a.docx');
            $this->fail('Chemin accepté sans racine valide.');
        } catch (InvalidPathException $e) {
            $this->assertSame(InvalidPathException::NO_ALLOWED_ROOTS, $e->getReason());
        }
    }

    public function testDriveTargetMustStillBeUnderAllowedRoot(): void
    {
        $normalizer = new PathNormalizer([self::INFO], ['X:' => '\\\\attaquant\\partage']);
        try {
            $normalizer->normalize('X:\\a.docx');
            $this->fail('Lecteur hors racine accepté.');
        } catch (InvalidPathException $e) {
            $this->assertSame(InvalidPathException::ROOT_NOT_ALLOWED, $e->getReason());
        }
    }

    public function testInvalidDriveMappingsAreIgnored(): void
    {
        $normalizer = new PathNormalizer([self::INFO], ['I:' => 'pas un UNC', 'AB:' => self::INFO, 3 => self::INFO]);
        try {
            $normalizer->normalize('I:\\a.docx');
            $this->fail('Correspondance invalide utilisée.');
        } catch (InvalidPathException $e) {
            $this->assertSame(InvalidPathException::UNMAPPED_DRIVE, $e->getReason());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function driveKeyProvider(): iterable
    {
        foreach (['I:', 'i:', 'I', 'i', 'I:\\', ' i: '] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('driveKeyProvider')]
    public function testDriveKeyVariants(string $key): void
    {
        $normalizer = new PathNormalizer([self::INFO], [$key => self::INFO]);
        $this->assertSame(self::INFO . '\\a.docx', $normalizer->normalize('I:\\a.docx'));
    }

    public function testLongestHostAliasWins(): void
    {
        $normalizer = new PathNormalizer(
            ['\\\\dfs\\A', '\\\\dfs\\B'],
            [],
            [
                '\\\\srv\\share'         => '\\\\dfs\\A',
                '\\\\srv\\share\\compta' => '\\\\dfs\\B',
            ]
        );
        $this->assertSame('\\\\dfs\\B\\x.xlsx', $normalizer->normalize('\\\\srv\\share\\Compta\\x.xlsx'));
        $this->assertSame('\\\\dfs\\A\\rh\\x.xlsx', $normalizer->normalize('\\\\srv\\share\\rh\\x.xlsx'));
    }

    public function testHostAliasTargetMustBeAllowed(): void
    {
        $normalizer = new PathNormalizer([self::INFO], [], ['\\\\srv\\s' => '\\\\attaquant\\x']);
        try {
            $normalizer->normalize('\\\\srv\\s\\a.docx');
            $this->fail('Alias hors racine accepté.');
        } catch (InvalidPathException $e) {
            $this->assertSame(InvalidPathException::ROOT_NOT_ALLOWED, $e->getReason());
        }
    }

    // ------------------------------------------------------------------
    // Saisie vide
    // ------------------------------------------------------------------

    public function testIsBlank(): void
    {
        $this->assertTrue(PathNormalizer::isBlank(''));
        $this->assertTrue(PathNormalizer::isBlank("  \t\r\n"));
        $this->assertTrue(PathNormalizer::isBlank(' "" '));
        $this->assertFalse(PathNormalizer::isBlank('"a"'));
        $this->assertFalse(PathNormalizer::isBlank('\\\\h\\s\\a.docx'));
    }

    // ------------------------------------------------------------------
    // Encodage file://
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fileUriProvider(): iterable
    {
        yield 'exemple validé sur le parc' => [
            self::INFO . '\\Info\\Docs\\Linux\\Installation de Unattended-Upgrade.docx',
            'file://internal.example.com/DFS/Informatique/Info/Docs/Linux/Installation%20de%20Unattended-Upgrade.docx',
        ];
        yield 'accents (UTF-8)' => [
            self::INFO . '\\Données\\Été.xlsx',
            'file://internal.example.com/DFS/Informatique/Donn%C3%A9es/%C3%89t%C3%A9.xlsx',
        ];
        yield '# % & + espace' => [
            self::INFO . '\\#1 50% R&D + x.docx',
            'file://internal.example.com/DFS/Informatique/%231%2050%25%20R%26D%20%2B%20x.docx',
        ];
        yield 'partage administratif $' => [
            '\\\\srv\\Partage$\\a.pptx',
            'file://srv/Partage%24/a.pptx',
        ];
        yield 'caractères non réservés conservés' => [
            '\\\\h\\s\\a-b_c.d~e.docx',
            'file://h/s/a-b_c.d~e.docx',
        ];
    }

    #[DataProvider('fileUriProvider')]
    public function testToFileUri(string $canonical, string $expected): void
    {
        $this->assertSame($expected, PathNormalizer::toFileUri($canonical));
    }

    public function testFileUriRoundTrip(): void
    {
        $normalizer = self::normalizer();
        $canonical  = $normalizer->normalize(self::INFO . '\\Données\\#1 50% R&D (v2).docx');
        $this->assertSame($canonical, $normalizer->normalize(PathNormalizer::toFileUri($canonical)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCanonicalProvider(): iterable
    {
        yield 'vide' => [''];
        yield 'lecteur' => ['I:\\a.docx'];
        yield 'barres obliques' => ['//h/s/a.docx'];
        yield 'hôte seul' => ['\\\\h'];
        yield 'séparateur doublé' => ['\\\\h\\\\s\\a.docx'];
        yield 'http' => ['http://h/s/a.docx'];
    }

    #[DataProvider('nonCanonicalProvider')]
    public function testToFileUriRejectsNonCanonical(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathNormalizer::toFileUri($input);
    }
}
