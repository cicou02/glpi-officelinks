<?php

/**
 * OfficeLinks — tests de la génération des liens.
 *
 * Rappel PHP : dans une chaîne entre apostrophes, '\\' vaut un seul « \ ».
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Officelinks\Tests;

use GlpiPlugin\Officelinks\LinkBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkBuilderTest extends TestCase
{
    private const DIR = '\\\\internal.example.com\\DFS\\Informatique\\Info\\Docs\\Linux';

    public function testFormatValidatedOnWorkstations(): void
    {
        $this->assertSame(
            'ms-word:ofe|u|file://internal.example.com/DFS/Informatique/Info/Docs/Linux/Installation%20de%20Unattended-Upgrade.docx',
            LinkBuilder::officeUri(self::DIR . '\\Installation de Unattended-Upgrade.docx')
        );
    }

    public function testExcelUri(): void
    {
        $this->assertSame(
            'ms-excel:ofe|u|file://internal.example.com/DFS/Informatique/Info/Docs/Linux/a.xlsx',
            LinkBuilder::officeUri(self::DIR . '\\a.xlsx')
        );
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function typeProvider(): iterable
    {
        foreach (['doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'rtf'] as $ext) {
            yield $ext => [$ext, 'Word', 'ms-word'];
        }
        foreach (['xls', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'csv'] as $ext) {
            yield $ext => [$ext, 'Excel', 'ms-excel'];
        }
        foreach (['ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'potx'] as $ext) {
            yield $ext => [$ext, 'PowerPoint', 'ms-powerpoint'];
        }
        foreach (['vsd', 'vsdx', 'vsdm'] as $ext) {
            yield $ext => [$ext, 'Visio', 'ms-visio'];
        }
        yield 'majuscules' => ['DOCX', 'Word', 'ms-word'];
        foreach (['pdf', 'html', 'txt', 'png', 'zip', 'msg', 'docx.exe'] as $ext) {
            yield $ext => [$ext, null, null];
        }
    }

    #[DataProvider('typeProvider')]
    public function testRegistry(string $extension, ?string $application, ?string $scheme): void
    {
        $path = self::DIR . '\\fichier.' . $extension;
        $type = LinkBuilder::officeType($path);
        $this->assertSame($application, $type['application'] ?? null);

        $uri = LinkBuilder::officeUri($path);
        if ($scheme === null) {
            $this->assertNull($uri);
        } else {
            $this->assertStringStartsWith($scheme . ':ofe|u|file://', (string) $uri);
        }
    }

    public function testNoExtensionHasNoType(): void
    {
        $this->assertNull(LinkBuilder::officeType(self::DIR . '\\Makefile'));
        $this->assertNull(LinkBuilder::officeType('\\\\h\\docx\\fichier'));
    }

    public function testSegmentsAreEncodedOneByOne(): void
    {
        $this->assertSame(
            'ms-word:ofe|u|file://h/DFS/R%26D%20%231/50%25%20%C3%A9t%C3%A9.docx',
            LinkBuilder::officeUri('\\\\h\\DFS\\R&D #1\\50% été.docx')
        );
    }

    public function testFolderUri(): void
    {
        $this->assertSame(
            'file://internal.example.com/DFS/Informatique/Info/Docs/Linux/',
            LinkBuilder::folderUri(self::DIR . '\\a.pdf')
        );
        $this->assertSame('file://h/Partage%24/', LinkBuilder::folderUri('\\\\h\\Partage$\\a.pdf'));
    }

    public function testFolderUriRejectsNonCanonical(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LinkBuilder::folderUri('a.docx');
    }

    public function testFileName(): void
    {
        $this->assertSame('a b.docx', LinkBuilder::fileName(self::DIR . '\\a b.docx'));
    }

    public function testOfficeExtensionsForJavaScript(): void
    {
        $types = LinkBuilder::officeExtensions();
        $this->assertSame(['word', 'excel', 'powerpoint', 'visio'], array_keys($types));
        $this->assertSame('Excel', $types['excel']['application']);
        $this->assertContains('csv', $types['excel']['extensions']);
    }
}
