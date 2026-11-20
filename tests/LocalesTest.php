<?php

/**
 * OfficeLinks — cohérence des traductions.
 *
 * Échoue si une chaîne a été ajoutée sans relancer `php tools/locales.php`,
 * si une traduction française manque ou perd un paramètre (%s, %1$s...), ou
 * si un .mo ne correspond plus à son .po.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Officelinks\Tests;

use GlpiPlugin\Officelinks\Tools\Locales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return array<string, array{context: ?string, msgid: string, msgstr: string}>
     */
    private static function po(string $file): array
    {
        return Locales::parsePo((string) file_get_contents(self::root() . '/locales/' . $file));
    }

    public function testPotIsUpToDate(): void
    {
        $expected = Locales::renderPot(Locales::extract(self::root()));
        $this->assertSame(
            $expected,
            file_get_contents(self::root() . '/locales/officelinks.pot'),
            'Relancer : php tools/locales.php'
        );
    }

    public function testExtractionFindsKnownStrings(): void
    {
        $entries = Locales::extract(self::root());
        $this->assertArrayHasKey('Network path', $entries);
        $this->assertArrayHasKey('Open in %s', $entries);
        $this->assertArrayHasKey('invalid UNC path (\\\\server\\share expected)', $entries);
        // Chaîne du cœur (domaine « glpi ») : non extraite.
        $this->assertArrayNotHasKey('button' . "\x04" . 'Save', $entries);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function poProvider(): iterable
    {
        foreach (array_keys(Locales::TARGETS) as $po) {
            yield $po => [$po];
        }
    }

    #[DataProvider('poProvider')]
    public function testPoContainsEveryString(string $po): void
    {
        $entries = self::po($po);
        foreach (array_keys(Locales::extract(self::root())) as $key) {
            $this->assertArrayHasKey($key, $entries, "Chaîne absente de $po : $key");
        }
    }

    public function testFrenchIsComplete(): void
    {
        foreach (self::po('fr_FR.po') as $key => $entry) {
            if ($key === '') {
                continue;
            }
            $this->assertNotSame('', $entry['msgstr'], 'Traduction manquante : ' . $entry['msgid']);
        }
    }

    public function testPlaceholdersArePreserved(): void
    {
        foreach (self::po('fr_FR.po') as $key => $entry) {
            if ($key === '' || $entry['msgstr'] === '') {
                continue;
            }
            preg_match_all('/%(?:\d+\$)?[sd]/', $entry['msgid'], $source);
            preg_match_all('/%(?:\d+\$)?[sd]/', $entry['msgstr'], $translated);
            $expected = $source[0];
            $actual   = $translated[0];
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, 'Paramètres différents : ' . $entry['msgid']);
        }
    }

    public function testEnglishFallbackHasNoTranslation(): void
    {
        foreach (self::po('en_GB.po') as $key => $entry) {
            if ($key !== '') {
                $this->assertSame('', $entry['msgstr'], 'en_GB doit garder le texte source : ' . $entry['msgid']);
            }
        }
    }

    #[DataProvider('poProvider')]
    public function testMoFilesAreCompiled(string $po): void
    {
        $expected = Locales::compileMo(self::po($po));
        foreach (Locales::TARGETS[$po] as $mo) {
            $this->assertSame(
                $expected,
                file_get_contents(self::root() . '/locales/' . $mo),
                "$mo n'est pas à jour : php tools/locales.php compile"
            );
        }
    }

    public function testPoRoundTrip(): void
    {
        $po = "msgid \"\"\nmsgstr \"\"\n\"Language: fr_FR\\n\"\n\n"
            . "#: a.php:1\nmsgctxt \"button\"\nmsgid \"Say \\\"hi\\\" \\\\o/\"\nmsgstr \"Dis \"\n\"« salut »\"\n";
        $entries = Locales::parsePo($po);
        $this->assertSame("Language: fr_FR\n", $entries['']['msgstr']);
        $this->assertSame('Dis « salut »', $entries["button\x04" . 'Say "hi" \\o/']['msgstr']);
    }
}
