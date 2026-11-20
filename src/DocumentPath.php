<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

namespace GlpiPlugin\Officelinks;

use DBmysql;

/**
 * Accès à la table glpi_plugin_officelinks_documents (un chemin par document).
 *
 * Seul le chemin UNC canonique est stocké ; les URI sont générées à l'affichage.
 */
final class DocumentPath
{
    public const TABLE = 'glpi_plugin_officelinks_documents';

    /**
     * Identifiant de l'option de recherche « Chemin réseau » (Document).
     *
     * GLPI ne tient aucun registre des identifiants des plugins : valeur
     * choisie hors des identifiants du cœur (littéraux <= 4000, statuts
     * 4000 + n, champs personnalisés 45000 + n). Ne JAMAIS la changer : les
     * lignes d'historique (glpi_logs.id_search_option) y font référence.
     */
    public const SEARCH_OPTION_ID = 21500;

    /** Longueur maximale d'une valeur dans glpi_logs (Log::history() tronque au-delà). */
    public const HISTORY_MAX_LENGTH = 255;

    /**
     * Valeur à écrire dans l'historique. Log::history() garde les 255
     * premiers caractères ; pour un chemin, la fin (dossier, nom du fichier)
     * est plus utile que le début (serveur, racine, connus) : on garde donc
     * la fin, précédée de « … ». Fonction pure.
     */
    public static function forHistory(string $path): string
    {
        if (mb_strlen($path, 'UTF-8') <= self::HISTORY_MAX_LENGTH) {
            return $path;
        }
        return '…' . mb_substr($path, -(self::HISTORY_MAX_LENGTH - 1), null, 'UTF-8');
    }

    /**
     * Chemin UNC canonique du document, ou null s'il n'en a pas.
     */
    public static function get(int $documents_id): ?string
    {
        if ($documents_id <= 0) {
            return null;
        }

        $iterator = self::db()->request([
            'SELECT' => ['unc_path'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['documents_id' => $documents_id],
            'LIMIT'  => 1,
        ]);
        foreach ($iterator as $row) {
            return (string) $row['unc_path'];
        }
        return null;
    }

    /**
     * Crée ou remplace le chemin du document (chemin déjà normalisé et validé).
     */
    public static function save(int $documents_id, string $unc_path): void
    {
        if ($documents_id <= 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        if (self::get($documents_id) === null) {
            self::db()->insert(self::TABLE, [
                'documents_id'  => $documents_id,
                'unc_path'      => $unc_path,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
            return;
        }

        self::db()->updateOrInsert(
            self::TABLE,
            [
                'unc_path' => $unc_path,
                'date_mod' => $now,
            ],
            ['documents_id' => $documents_id]
        );
    }

    /**
     * Supprime le chemin du document (sans effet s'il n'en a pas).
     */
    public static function delete(int $documents_id): void
    {
        if ($documents_id <= 0) {
            return;
        }
        self::db()->delete(self::TABLE, ['documents_id' => $documents_id]);
    }

    private static function db(): DBmysql
    {
        /** @var DBmysql $DB */
        global $DB;
        return $DB;
    }
}
