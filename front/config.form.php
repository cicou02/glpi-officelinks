<?php

/**
 * OfficeLinks — page de configuration.
 *
 * Amorçage assuré par le routeur de GLPI (inc/includes.php est obsolète en
 * 12 et sans effet en 11). Protection CSRF : jeton ajouté par
 * Html::closeForm() en 11, en-têtes Sec-Fetch-Site/Origin en 12.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Officelinks\PluginConfig;
use GlpiPlugin\Officelinks\Settings;

/** @var array<string, mixed> $CFG_GLPI */
global $CFG_GLPI;

if (!Plugin::isPluginActive('officelinks')) {
    throw new NotFoundHttpException();
}

Session::checkRight('config', UPDATE);

$form_url = $CFG_GLPI['root_doc'] . '/plugins/officelinks/front/config.form.php';
$errors   = [];

if (isset($_POST['update'])) {
    $roots_text   = is_string($_POST['allowed_roots'] ?? null) ? $_POST['allowed_roots'] : '';
    $drives_text  = is_string($_POST['drive_mappings'] ?? null) ? $_POST['drive_mappings'] : '';
    $aliases_text = is_string($_POST['host_aliases'] ?? null) ? $_POST['host_aliases'] : '';
    $file_links   = ($_POST['enable_file_links'] ?? '0') === '1';

    $result = Settings::fromForm($roots_text, $drives_text, $aliases_text, $file_links);

    if ($result['errors'] === []) {
        PluginConfig::save($result['settings']);
        // Les messages après redirection sont affichés sans échappement.
        Session::addMessageAfterRedirect(__s('Configuration saved.', 'officelinks'), false, INFO);
        Html::redirect($form_url);
    }

    // Rien n'est enregistré : on réaffiche la saisie avec les erreurs.
    $errors = array_map([PluginConfig::class, 'formErrorMessage'], $result['errors']);
    $form   = [
        Settings::FIELD_ALLOWED_ROOTS  => $roots_text,
        Settings::FIELD_DRIVE_MAPPINGS => $drives_text,
        Settings::FIELD_HOST_ALIASES   => $aliases_text,
    ];
} else {
    $settings   = PluginConfig::load();
    $form       = $settings->toFormText();
    $file_links = $settings->enableFileLinks;
}

Html::header(__('OfficeLinks', 'officelinks'), '', 'config', 'plugin');

echo '<form method="post" action="' . htmlescape($form_url) . '">';
TemplateRenderer::getInstance()->display('@officelinks/config.html.twig', [
    'form'       => $form,
    'file_links' => $file_links,
    'errors'     => $errors,
]);
Html::closeForm();

Html::footer();
