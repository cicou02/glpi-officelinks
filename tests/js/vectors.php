<?php

/**
 * OfficeLinks — vecteurs de test pour le JavaScript.
 *
 * Exécute PathNormalizer (qui fait foi) sur toutes les saisies des tests
 * PHPUnit et imprime en JSON la configuration et les résultats attendus.
 * Utilisé par tests/js/officelinks.test.js.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Officelinks\InvalidPathException;
use GlpiPlugin\Officelinks\Settings;
use GlpiPlugin\Officelinks\Tests\PathNormalizerTest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$info   = '\\\\internal.example.com\\DFS\\Informatique';
$compta = '\\\\internal.example.com\\DFS\\Compta';

// Même configuration que PathNormalizerTest::normalizer(), sous forme canonique.
$settings = Settings::fromStored((new Settings(
    [$info, $compta],
    ['I:' => $info, 'K:' => '\\\\srv-fic01\\Partage$'],
    ['\\\\srv-fic01\\Partage$' => $compta]
))->toStored());
$normalizer = $settings->createNormalizer();

$inputs = [];
foreach (PathNormalizerTest::validProvider() as $name => $case) {
    $inputs[$name] = $case[0];
}
foreach (PathNormalizerTest::invalidProvider() as $name => $case) {
    $inputs[$name] = $case[0];
}

$cases = [];
foreach ($inputs as $name => $input) {
    if (!mb_check_encoding($input, 'UTF-8')) {
        continue; // impossible à transmettre en JSON (le navigateur n'envoie que de l'UTF-8)
    }
    try {
        $expected = ['ok' => true, 'path' => $normalizer->normalize($input)];
    } catch (InvalidPathException $e) {
        $expected = ['ok' => false, 'reason' => $e->getReason()];
    }
    $cases[] = ['name' => $name, 'input' => $input, 'expected' => $expected];
}

echo json_encode([
    'config' => [
        'allowedRoots'  => $settings->allowedRoots,
        'driveMappings' => (object) $settings->driveMappings,
        'hostAliases'   => (object) $settings->hostAliases,
        'maxLength'     => 1024,
    ],
    'cases' => $cases,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
