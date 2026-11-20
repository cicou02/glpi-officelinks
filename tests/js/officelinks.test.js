/**
 * OfficeLinks — tests du JavaScript (node --test tests/js).
 *
 * L'aperçu JavaScript doit donner le même résultat que PathNormalizer (PHP)
 * sur toutes les saisies des tests PHPUnit : les valeurs attendues sont
 * calculées par PHP (tests/js/vectors.php), jamais écrites à la main.
 *
 * @license GPL-3.0-or-later
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const officelinks = require('../../public/js/officelinks.js');

const vectors = JSON.parse(execFileSync('php', [path.join(__dirname, 'vectors.php')], {encoding: 'utf8', maxBuffer: 16 * 1024 * 1024}));

test('vecteurs PHP disponibles', () => {
    assert.ok(vectors.cases.length > 80);
});

for (const testCase of vectors.cases) {
    test(`même résultat que PHP : ${testCase.name}`, () => {
        assert.deepEqual(officelinks.normalize(testCase.input, vectors.config), testCase.expected);
    });
}

test('aucune racine : tout est refusé', () => {
    assert.deepEqual(
        officelinks.normalize('\\\\h\\s\\a.docx', {allowedRoots: [], driveMappings: {}, hostAliases: {}}),
        {ok: false, reason: 'no_allowed_roots'}
    );
});

test('isBlank', () => {
    assert.equal(officelinks.isBlank('  "" '), true);
    assert.equal(officelinks.isBlank('\\\\h\\s\\a.docx'), false);
});

test('suggestedName : nom du fichier sans extension', () => {
    assert.equal(officelinks.suggestedName('"\\\\h\\DFS\\Info\\Installation de Unattended-Upgrade.docx"'), 'Installation de Unattended-Upgrade');
    assert.equal(officelinks.suggestedName('file://h/DFS/R%C3%A9union%20budget.xlsx'), 'Réunion budget');
    assert.equal(officelinks.suggestedName('I:/a/archive.tar.gz'), 'archive.tar');
    assert.equal(officelinks.suggestedName('\\\\h\\DFS\\Makefile'), 'Makefile');
    assert.equal(officelinks.suggestedName('\\\\h\\DFS\\.htaccess'), '.htaccess');
    assert.equal(officelinks.suggestedName('\\\\h\\DFS\\dossier\\'), '');
    assert.equal(officelinks.suggestedName('   '), '');
});

test('officeApplication', () => {
    const types = {
        word: {application: 'Word', extensions: ['docx']},
        excel: {application: 'Excel', extensions: ['xlsx', 'csv']},
    };
    assert.equal(officelinks.officeApplication('\\\\h\\s\\A.DOCX', types), 'Word');
    assert.equal(officelinks.officeApplication('\\\\h\\s\\a.csv', types), 'Excel');
    assert.equal(officelinks.officeApplication('\\\\h\\s\\a.pdf', types), null);
});

test('compareVersions', () => {
    assert.equal(officelinks.compareVersions('v1.0.0', '0.1.0'), 1);
    assert.equal(officelinks.compareVersions('1.0.0', '1.0.0'), 0);
    assert.equal(officelinks.compareVersions('v1.0', '1.0.0'), 0);
    assert.equal(officelinks.compareVersions('1.2.10', '1.2.9'), 1);
    assert.equal(officelinks.compareVersions('1.2.9', '1.10.0'), -1);
    assert.equal(officelinks.compareVersions('V2.0.0-rc1', '1.9.9'), 1);
    assert.equal(officelinks.compareVersions('0.1.0', '0.1.1'), -1);
});
