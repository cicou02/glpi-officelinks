<?php

/**
 * OfficeLinks — installation et désinstallation.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

/** Contexte de configuration du plugin dans la table glpi_configs. */
const PLUGIN_OFFICELINKS_CONFIG_CONTEXT = 'plugin:officelinks';

/** Table des chemins UNC associés aux documents. */
const PLUGIN_OFFICELINKS_TABLE = 'glpi_plugin_officelinks_documents';

/**
 * Configuration par défaut, volontairement vide.
 *
 * Les listes sont stockées en JSON (glpi_configs.value est un texte).
 * allowed_roots vide => tout enregistrement de chemin est refusé.
 *
 * @return array<string, string>
 */
function plugin_officelinks_default_config(): array
{
    return [
        'allowed_roots'     => '[]', // liste de racines UNC autorisées
        'drive_mappings'    => '{}', // lettre de lecteur => racine UNC
        'host_aliases'      => '{}', // chemin serveur cible => chemin d'espace de noms DFS
        'enable_file_links' => '0',  // liens file:// désactivés par défaut
    ];
}

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

    if (!$DB->tableExists(PLUGIN_OFFICELINKS_TABLE)) {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        // SQL brut autorisé uniquement pour CREATE/DROP TABLE.
        $DB->doQuery(
            "CREATE TABLE `" . PLUGIN_OFFICELINKS_TABLE . "` (
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

    // Valeurs par défaut sans écraser une configuration existante.
    $current = Config::getConfigurationValues(PLUGIN_OFFICELINKS_CONFIG_CONTEXT);
    $missing = array_diff_key(plugin_officelinks_default_config(), $current);
    if ($missing !== []) {
        Config::setConfigurationValues(PLUGIN_OFFICELINKS_CONFIG_CONTEXT, $missing);
    }

    return true;
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

    $DB->dropTable(PLUGIN_OFFICELINKS_TABLE, true);

    // Supprime toutes les clés du contexte, y compris d'éventuelles
    // clés ajoutées par une version ultérieure.
    $keys = array_keys(Config::getConfigurationValues(PLUGIN_OFFICELINKS_CONFIG_CONTEXT));
    if ($keys !== []) {
        Config::deleteConfigurationValues(PLUGIN_OFFICELINKS_CONFIG_CONTEXT, $keys);
    }

    return true;
}
