<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

namespace GlpiPlugin\Officelinks;

use Glpi\Cache\CacheManager;
use Glpi\Kernel\Kernel;

/**
 * Opérations de maintenance liées à l'installation et à la mise à jour.
 */
final class Maintenance
{
    /**
     * Vide le cache des templates Twig compilés.
     *
     * En production, GLPI ne recompile pas un template modifié : après une
     * mise à jour du plugin, l'ancien écran resterait affiché jusqu'à un
     * « php bin/console cache:clear ». Appelée par plugin_officelinks_install(),
     * que GLPI exécute aussi pour le bouton « Mettre à jour ».
     *
     * - GLPI 12 : CacheManager::resetCompiledTemplates() (GLPI l'appelle
     *   déjà lui-même à l'installation d'un plugin ; sans effet de bord).
     * - GLPI 11 : la méthode n'existe pas ; même suppression que
     *   CacheManager::resetAllCaches(), limitée aux templates (on ne vide
     *   pas le cache Symfony au milieu d'une requête web).
     *
     * @return bool false si un fichier n'a pas pu être supprimé.
     */
    public static function resetTemplateCache(): bool
    {
        $manager = new CacheManager();
        if (method_exists($manager, 'resetCompiledTemplates')) {
            return (bool) $manager->resetCompiledTemplates();
        }

        $directory = Kernel::getCacheRootDir() . '/templates';
        if (!is_dir($directory)) {
            return true;
        }

        $success = true;
        foreach (glob($directory . '/*/*.php') ?: [] as $file) {
            $success = @unlink($file) && $success;
        }
        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $subdirectory) {
            $success = @rmdir($subdirectory) && $success;
        }
        return $success;
    }
}
