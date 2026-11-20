<?php

/**
 * OfficeLinks — installation et désinstallation.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

use GlpiPlugin\Officelinks\DocumentPath;
use GlpiPlugin\Officelinks\PluginConfig;
use GlpiPlugin\Officelinks\Settings;

/**
 * Installation (et réinstallation) du plugin.
 *
 * Idempotente : la table n'est créée que si elle n'existe pas et seules
 * les clés de configuration absentes reçoivent leur valeur par défaut.
 *
 * @param array<string, mixed> $args Paramètres transmis par Plugin::install().
 *
 * @return bool
 */
function plugin_officelinks_install(array $args = []): bool
{
    /** @var DBmysql $DB */
    global $DB;

    if (!$DB->tableExists(DocumentPath::TABLE)) {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        // SQL brut autorisé uniquement pour CREATE/DROP TABLE.
        $DB->doQuery(
            "CREATE TABLE `" . DocumentPath::TABLE . "` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `documents_id` int {$sign} NOT NULL DEFAULT '0',
                `unc_path` varchar(1024) NOT NULL DEFAULT '',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `documents_id` (`documents_id`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    // Valeurs par défaut (tout vide, liens file:// désactivés) sans écraser
    // une configuration existante. L'autoloader du plugin est actif ici
    // (Plugin::install() appelle Plugin::load(), cf. docs/compat.md).
    $current = Config::getConfigurationValues(PluginConfig::CONTEXT);
    $missing = array_diff_key(Settings::defaultStored(), $current);
    if ($missing !== []) {
        Config::setConfigurationValues(PluginConfig::CONTEXT, $missing);
    }

    return true;
}

/**
 * Options de recherche ajoutées par le plugin.
 *
 * « Chemin réseau » sur Document : affichable comme colonne et utilisable
 * comme critère dans Gestion > Documents. Jointure « child » :
 * glpi_documents.id = glpi_plugin_officelinks_documents.documents_id.
 * Sert aussi de libellé aux lignes d'historique des changements de chemin.
 *
 * @param string $itemtype
 *
 * @return array<int, array<string, mixed>>
 */
function plugin_officelinks_getAddSearchOptionsNew($itemtype): array
{
    if ($itemtype !== Document::class) {
        return [];
    }

    return [
        [
            'id'            => DocumentPath::SEARCH_OPTION_ID,
            'table'         => DocumentPath::TABLE,
            'field'         => 'unc_path',
            'name'          => __('Network path', 'officelinks'),
            'datatype'      => 'string',
            // Indispensable : sans 'itemtype', GLPI déduit la classe de la
            // table (getItemTypeForTable()), ne trouve aucune classe pour
            // glpi_plugin_officelinks_documents et l'affichage de la colonne
            // échoue (TypeError dans getItemForItemtype(null)). Document ne
            // traite pas le champ unc_path dans getSpecificValueToDisplay() :
            // la valeur brute est affichée, échappée par GLPI.
            'itemtype'      => Document::class,
            // Pas de modification en masse : elle contournerait la validation.
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ],
    ];
}

/**
 * Désinstallation : suppression de la table et de toute la configuration.
 *
 * @return bool
 */
function plugin_officelinks_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $DB->dropTable(DocumentPath::TABLE, true);

    // Supprime toutes les clés du contexte, y compris d'éventuelles
    // clés ajoutées par une version ultérieure.
    $keys = array_keys(Config::getConfigurationValues(PluginConfig::CONTEXT));
    if ($keys !== []) {
        Config::deleteConfigurationValues(PluginConfig::CONTEXT, $keys);
    }

    return true;
}
