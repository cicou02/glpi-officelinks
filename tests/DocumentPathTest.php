<?php

/**
 * OfficeLinks — tests de la valeur écrite dans l'historique.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Officelinks\Tests;

use GlpiPlugin\Officelinks\DocumentPath;
use PHPUnit\Framework\TestCase;

final class DocumentPathTest extends TestCase
{
    public function testShortPathIsUnchanged(): void
    {
        $this->assertSame('', DocumentPath::forHistory(''));
        $path = '\\\\h\\DFS\\a.docx';
        $this->assertSame($path, DocumentPath::forHistory($path));
    }

    public function testExactlyMaxLengthIsUnchanged(): void
    {
        $path = '\\\\h\\' . str_repeat('a', 255 - 9) . '.docx';
        $this->assertSame(255, mb_strlen($path));
        $this->assertSame($path, DocumentPath::forHistory($path));
    }

    public function testLongPathKeepsTheEnd(): void
    {
        $end  = '\\Projet 2026\\Compte rendu de réunion.docx';
        $path = '\\\\internal.example.com\\DFS\\' . str_repeat('Dossier\\', 40) . ltrim($end, '\\');
        $result = DocumentPath::forHistory($path);

        $this->assertSame(255, mb_strlen($result));
        $this->assertStringStartsWith('…', $result);
        $this->assertStringEndsWith($end, $result);
        $this->assertSame(mb_substr($path, -254), mb_substr($result, 1));
    }

    public function testMultibyteCharactersAreNotCut(): void
    {
        $path   = '\\\\h\\' . str_repeat('é', 300) . '.docx';
        $result = DocumentPath::forHistory($path);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertSame(255, mb_strlen($result));
    }
}
