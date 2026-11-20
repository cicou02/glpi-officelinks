<?php

/**
 * OfficeLinks — tests de la configuration (analyse du formulaire, stockage).
 *
 * Rappel PHP : dans une chaîne entre apostrophes, '\\' vaut un seul « \ ».
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Officelinks\Tests;

use GlpiPlugin\Officelinks\PathNormalizer;
use GlpiPlugin\Officelinks\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private const INFO   = '\\\\internal.example.com\\DFS\\Informatique';
    private const COMPTA = '\\\\internal.example.com\\DFS\\Compta';

    // ------------------------------------------------------------------
    // Stockage
    // ------------------------------------------------------------------

    public function testDefaultStoredIsEmptyAndFileLinksDisabled(): void
    {
        $this->assertSame([
            'allowed_roots'     => '[]',
            'drive_mappings'    => '{}',
            'host_aliases'      => '{}',
            'enable_file_links' => '0',
        ], Settings::defaultStored());
    }

    public function testStoredRoundTrip(): void
    {
        $settings = new Settings(
            [self::INFO, self::COMPTA],
            ['I:' => self::INFO],
            ['\\\\srv\\Partage$' => self::COMPTA],
            true
        );
        $stored = $settings->toStored();

        // JSON lisible : barres inverses échappées une fois, accents conservés.
        $this->assertSame('["\\\\\\\\internal.example.com\\\\DFS\\\\Informatique","\\\\\\\\internal.example.com\\\\DFS\\\\Compta"]', $stored['allowed_roots']);
        $this->assertSame('1', $stored['enable_file_links']);

        $this->assertEquals($settings, Settings::fromStored($stored));
    }

    public function testUnicodeIsStoredUnescaped(): void
    {
        $stored = (new Settings(['\\\\h\\Données']))->toStored();
        $this->assertStringContainsString('Données', $stored['allowed_roots']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenStoredProvider(): iterable
    {
        yield 'aucune valeur' => [[]];
        yield 'JSON illisible' => [['allowed_roots' => '[', 'drive_mappings' => 'x', 'host_aliases' => '{']];
        yield 'mauvais types' => [['allowed_roots' => '"\\\\\\\\h\\\\s"', 'drive_mappings' => '[1,2]', 'host_aliases' => 'null']];
        yield 'entrées invalides' => [[
            'allowed_roots'  => '["http://x", "C:\\\\x", "\\\\\\\\?\\\\UNC\\\\h\\\\s", 42]',
            'drive_mappings' => '{"AB:": "\\\\\\\\h\\\\s", "I:": "pas un UNC"}',
            'host_aliases'   => '{"x": "\\\\\\\\h\\\\s"}',
        ]];
    }

    /**
     * @param array<string, mixed> $stored
     */
    #[DataProvider('brokenStoredProvider')]
    public function testBrokenStoredValuesNeverWiden(array $stored): void
    {
        $settings = Settings::fromStored($stored);
        $this->assertSame([], $settings->allowedRoots);
        $this->assertSame([], $settings->driveMappings);
        $this->assertSame([], $settings->hostAliases);
        $this->assertFalse($settings->enableFileLinks);
    }

    public function testStoredEntriesAreCanonicalized(): void
    {
        $settings = Settings::fromStored([
            'allowed_roots'  => json_encode(['//h/DFS/', '\\\\H\\dfs', '\\\\h\\Autre']),
            'drive_mappings' => json_encode(['i' => '//h/DFS']),
        ]);
        // Doublons (casse indifférente) retirés, premier conservé.
        $this->assertSame(['\\\\h\\DFS', '\\\\h\\Autre'], $settings->allowedRoots);
        $this->assertSame(['I:' => '\\\\h\\DFS'], $settings->driveMappings);
    }

    // ------------------------------------------------------------------
    // Formulaire
    // ------------------------------------------------------------------

    public function testValidForm(): void
    {
        $result = Settings::fromForm(
            "  " . self::INFO . "\\\r\n\r\n//internal.example.com/DFS/Compta\n" . strtoupper(self::INFO) . "\n",
            "k = \\\\srv-fic01\\Partage$\ni: = " . self::INFO . "\n",
            "\\\\srv-fic01\\Partage$ = " . self::COMPTA,
            true
        );

        $this->assertSame([], $result['errors']);
        $settings = $result['settings'];
        $this->assertSame([self::INFO, self::COMPTA], $settings->allowedRoots);
        $this->assertSame(['I:' => self::INFO, 'K:' => '\\\\srv-fic01\\Partage$'], $settings->driveMappings);
        $this->assertSame(['\\\\srv-fic01\\Partage$' => self::COMPTA], $settings->hostAliases);
        $this->assertTrue($settings->enableFileLinks);

        // Les réglages produisent un normaliseur cohérent.
        $this->assertSame(self::COMPTA . '\\b.xlsx', $settings->createNormalizer()->normalize('K:\\b.xlsx'));
    }

    public function testEmptyFormIsValid(): void
    {
        $result = Settings::fromForm('', "\n  \n", '', false);
        $this->assertSame([], $result['errors']);
        $this->assertEquals(new Settings(), $result['settings']);
    }

    public function testFormTextRoundTrip(): void
    {
        $settings = new Settings([self::INFO, self::COMPTA], ['I:' => self::INFO], ['\\\\srv\\s' => self::COMPTA], false);
        $text     = $settings->toFormText();

        $this->assertSame('I: = ' . self::INFO, $text['drive_mappings']);

        $result = Settings::fromForm($text['allowed_roots'], $text['drive_mappings'], $text['host_aliases'], false);
        $this->assertSame([], $result['errors']);
        $this->assertEquals($settings, $result['settings']);
    }

    /**
     * @return iterable<string, array{string, string, string, string, int, string}>
     */
    public static function invalidFormProvider(): iterable
    {
        $info = self::INFO;

        yield 'racine non UNC' => ["$info\nhttp://x", '', '', Settings::FIELD_ALLOWED_ROOTS, 2, Settings::ERROR_INVALID_UNC];
        yield 'racine sans partage' => ['\\\\hôte', '', '', Settings::FIELD_ALLOWED_ROOTS, 1, Settings::ERROR_INVALID_UNC];
        yield 'racine \\\\?\\' => ['\\\\?\\UNC\\h\\s', '', '', Settings::FIELD_ALLOWED_ROOTS, 1, Settings::ERROR_INVALID_UNC];
        yield 'racine avec ..' => ['\\\\h\\s\\..\\x', '', '', Settings::FIELD_ALLOWED_ROOTS, 1, Settings::ERROR_INVALID_UNC];
        yield 'racine lettre de lecteur' => ['I:\\x', '', '', Settings::FIELD_ALLOWED_ROOTS, 1, Settings::ERROR_INVALID_UNC];
        yield 'lecteur sans =' => [$info, "I: $info", '', Settings::FIELD_DRIVE_MAPPINGS, 1, Settings::ERROR_MISSING_SEPARATOR];
        yield 'lecteur cible vide' => [$info, 'I: =', '', Settings::FIELD_DRIVE_MAPPINGS, 1, Settings::ERROR_MISSING_SEPARATOR];
        yield 'lecteur invalide' => [$info, "IJ: = $info", '', Settings::FIELD_DRIVE_MAPPINGS, 1, Settings::ERROR_INVALID_DRIVE];
        yield 'lecteur cible non UNC' => [$info, 'I: = C:\\x', '', Settings::FIELD_DRIVE_MAPPINGS, 1, Settings::ERROR_INVALID_UNC];
        yield 'lecteur en double' => [$info, "I: = $info\n\ni = $info\\x", '', Settings::FIELD_DRIVE_MAPPINGS, 3, Settings::ERROR_DUPLICATE];
        yield 'lecteur hors racines' => [$info, 'X: = \\\\attaquant\\x', '', Settings::FIELD_DRIVE_MAPPINGS, 1, Settings::ERROR_OUTSIDE_ROOTS];
        yield 'lecteur sur la racine parente' => [$info, 'X: = \\\\internal.example.com\\DFS', '', Settings::FIELD_DRIVE_MAPPINGS, 1, Settings::ERROR_OUTSIDE_ROOTS];
        yield 'alias sans =' => [$info, '', "\\\\srv\\s $info", Settings::FIELD_HOST_ALIASES, 1, Settings::ERROR_MISSING_SEPARATOR];
        yield 'alias source non UNC' => [$info, '', "srv = $info", Settings::FIELD_HOST_ALIASES, 1, Settings::ERROR_INVALID_UNC];
        yield 'alias vers soi-même' => [$info, '', "$info = " . strtolower($info), Settings::FIELD_HOST_ALIASES, 1, Settings::ERROR_SELF_ALIAS];
        yield 'alias en double' => [$info, '', "\\\\srv\\s = $info\n\\\\SRV\\S = $info", Settings::FIELD_HOST_ALIASES, 2, Settings::ERROR_DUPLICATE];
        yield 'alias hors racines' => [$info, '', '\\\\srv\\s = \\\\attaquant\\x', Settings::FIELD_HOST_ALIASES, 1, Settings::ERROR_OUTSIDE_ROOTS];
        yield 'aucune racine' => ['', "I: = $info", '', Settings::FIELD_DRIVE_MAPPINGS, 1, Settings::ERROR_OUTSIDE_ROOTS];
    }

    #[DataProvider('invalidFormProvider')]
    public function testInvalidForm(string $roots, string $drives, string $aliases, string $field, int $line, string $reason): void
    {
        $errors = Settings::fromForm($roots, $drives, $aliases, false)['errors'];
        $this->assertCount(1, $errors, print_r($errors, true));
        $this->assertSame($field, $errors[0]['field']);
        $this->assertSame($line, $errors[0]['line']);
        $this->assertSame($reason, $errors[0]['reason']);
    }

    public function testDriveTargetReachingRootThroughAliasIsAccepted(): void
    {
        $result = Settings::fromForm(self::COMPTA, 'K: = \\\\srv\\Partage$\\Budget', '\\\\srv\\Partage$ = ' . self::COMPTA, false);
        $this->assertSame([], $result['errors']);
    }

    public function testErrorsAreSortedAndKeepRawValue(): void
    {
        $errors = Settings::fromForm("bad1\n" . self::INFO, "bad2\nbad3", '', false)['errors'];
        $this->assertSame(
            [
                [Settings::FIELD_ALLOWED_ROOTS, 1, 'bad1'],
                [Settings::FIELD_DRIVE_MAPPINGS, 1, 'bad2'],
                [Settings::FIELD_DRIVE_MAPPINGS, 2, 'bad3'],
            ],
            array_map(static fn(array $e): array => [$e['field'], $e['line'], $e['value']], $errors)
        );
    }

    // ------------------------------------------------------------------
    // Fonctions publiques de PathNormalizer utilisées par la configuration
    // ------------------------------------------------------------------

    public function testCanonicalizeConfigPath(): void
    {
        $this->assertSame('\\\\h\\DFS\\x', PathNormalizer::canonicalizeConfigPath(' "//h/DFS//x/" '));
        $this->assertNull(PathNormalizer::canonicalizeConfigPath('\\\\h'));
        $this->assertNull(PathNormalizer::canonicalizeConfigPath('\\\\h\\' . str_repeat('a', 1024)));
        $this->assertNull(PathNormalizer::canonicalizeConfigPath(null));
    }

    public function testIsUnderAllowedRoot(): void
    {
        $normalizer = new PathNormalizer([self::INFO], [], ['\\\\srv\\s' => self::INFO]);
        $this->assertTrue($normalizer->isUnderAllowedRoot(self::INFO));
        $this->assertTrue($normalizer->isUnderAllowedRoot(self::INFO . '\\Sous'));
        $this->assertTrue($normalizer->isUnderAllowedRoot('\\\\SRV\\s\\Sous'));
        $this->assertFalse($normalizer->isUnderAllowedRoot(self::INFO . '-evil'));
        $this->assertFalse($normalizer->isUnderAllowedRoot('\\\\internal.example.com\\DFS'));
        $this->assertFalse((new PathNormalizer([]))->isUnderAllowedRoot(self::INFO));
    }

    public function testAliasSourceContainingEqualsSignRoundTrips(): void
    {
        // « = » est autorisé dans un nom de dossier Windows.
        $source   = '\\\\srv-fic01\\Ancien=Partage';
        $settings = new Settings([self::INFO], [], [$source => self::INFO]);
        $text     = $settings->toFormText();

        $result = Settings::fromForm($text['allowed_roots'], $text['drive_mappings'], $text['host_aliases'], false);

        $this->assertSame([], $result['errors']);
        $this->assertSame([$source => self::INFO], $result['settings']->hostAliases);
    }

    public function testDriveSeparatorWithoutSpaces(): void
    {
        $result = Settings::fromForm(self::INFO, 'I:=' . self::INFO, '', false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['I:' => self::INFO], $result['settings']->driveMappings);
    }

    public function testIgnoredStoredEntries(): void
    {
        $this->assertSame([], Settings::ignoredStoredEntries(Settings::defaultStored()));
        $this->assertSame([], Settings::ignoredStoredEntries((new Settings([self::INFO], ['I:' => self::INFO]))->toStored()));

        $stored = [
            'allowed_roots'  => json_encode([self::INFO, '\\\\h', 'pas un chemin']),
            'drive_mappings' => json_encode(['I:' => self::INFO, 'II' => self::INFO, 'K:' => 'x']),
            'host_aliases'   => '{json illisible',
        ];

        $this->assertSame(
            [
                Settings::FIELD_ALLOWED_ROOTS  => 2,
                Settings::FIELD_DRIVE_MAPPINGS => 2,
                Settings::FIELD_HOST_ALIASES   => 1,
            ],
            Settings::ignoredStoredEntries($stored)
        );
    }
}
