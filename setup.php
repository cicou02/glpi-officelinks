<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * Ajoute à la fiche Document un chemin réseau UNC et génère à l'affichage
 * des liens d'ouverture native (Office URI, copie du chemin).
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

// Version du plugin.
define('PLUGIN_OFFICELINKS_VERSION', '0.1.0');

// Bornes de compatibilité GLPI : la borne max est exclusive
// (Plugin::checkGlpiVersion() compare avec « < », cf. docs/compat.md).
define('PLUGIN_OFFICELINKS_MIN_GLPI', '11.0.0');
define('PLUGIN_OFFICELINKS_MAX_GLPI', '12.0.99');

// PHP 8.2 minimum (exigé par GLPI 11 ; GLPI 12 exige 8.3).
define('PLUGIN_OFFICELINKS_MIN_PHP', '8.2');

/**
 * Initialisation du plugin : enregistrement des hooks.
 *
 * Appelée par Plugin::init() pour chaque plugin actif.
 * Étape 1 (squelette) : aucun hook n'est encore enregistré.
 *
 * @return void
 */
function plugin_init_officelinks(): void
{
    // Les hooks (fiche Document, configuration, ressources JS/CSS,
    // recherche) seront enregistrés aux étapes suivantes.
}

/**
 * Informations sur le plugin, lues par Plugin::getInfo() / checkVersions().
 *
 * @return array<string, mixed>
 */
function plugin_version_officelinks(): array
{
    return [
        'name'         => 'OfficeLinks',
        'version'      => PLUGIN_OFFICELINKS_VERSION,
        'author'       => 'OfficeLinks contributors',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/cicou02/glpi-officelinks',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_OFFICELINKS_MIN_GLPI,
                'max' => PLUGIN_OFFICELINKS_MAX_GLPI,
            ],
            'php'  => [
                'min' => PLUGIN_OFFICELINKS_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Vérification de la configuration avant activation.
 *
 * Les prérequis de version sont contrôlés par GLPI via 'requirements'.
 *
 * @param bool $verbose Affiche un message en cas d'échec.
 *
 * @return bool
 */
function plugin_officelinks_check_config($verbose = false): bool
{
    return true;
}
