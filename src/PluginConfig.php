<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

namespace GlpiPlugin\Officelinks;

use Config;

/**
 * Lien entre Settings (pur) et la table glpi_configs de GLPI.
 */
final class PluginConfig
{
    /** Contexte de configuration du plugin dans glpi_configs. */
    public const CONTEXT = 'plugin:officelinks';

    /**
     * Réglages enregistrés (entrées invalides écartées).
     */
    public static function load(): Settings
    {
        return Settings::fromStored(Config::getConfigurationValues(self::CONTEXT));
    }

    /**
     * Enregistre les réglages (toutes les clés).
     */
    public static function save(Settings $settings): void
    {
        Config::setConfigurationValues(self::CONTEXT, $settings->toStored());
    }

    /**
     * Message traduit (texte brut, à échapper à l'affichage) pour une erreur
     * renvoyée par Settings::fromForm().
     *
     * @param array{field: string, line: int, value: string, reason: string} $error
     */
    public static function formErrorMessage(array $error): string
    {
        $field = match ($error['field']) {
            Settings::FIELD_ALLOWED_ROOTS  => __('Allowed roots', 'officelinks'),
            Settings::FIELD_DRIVE_MAPPINGS => __('Drive letter mappings', 'officelinks'),
            Settings::FIELD_HOST_ALIASES   => __('Host aliases', 'officelinks'),
            default                        => $error['field'],
        };

        $reason = match ($error['reason']) {
            Settings::ERROR_INVALID_UNC       => __('invalid UNC path (\\\\server\\share expected)', 'officelinks'),
            Settings::ERROR_INVALID_DRIVE     => __('invalid drive letter (A: to Z: expected)', 'officelinks'),
            Settings::ERROR_MISSING_SEPARATOR => __('expected format: source = target', 'officelinks'),
            Settings::ERROR_DUPLICATE         => __('already defined on a previous line', 'officelinks'),
            Settings::ERROR_OUTSIDE_ROOTS     => __('target is not under an allowed root', 'officelinks'),
            Settings::ERROR_SELF_ALIAS        => __('source and target are identical', 'officelinks'),
            default                           => $error['reason'],
        };

        return sprintf(
            __('%1$s, line %2$d: %3$s (%4$s)', 'officelinks'),
            $field,
            $error['line'],
            $reason,
            $error['value']
        );
    }
}
