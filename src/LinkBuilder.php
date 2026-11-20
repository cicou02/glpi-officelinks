<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

namespace GlpiPlugin\Officelinks;

use InvalidArgumentException;

/**
 * Génération des liens d'ouverture à l'affichage, à partir du chemin UNC
 * canonique stocké. Classe pure, sans dépendance à GLPI.
 *
 * Format validé sur le parc (Edge, Microsoft 365) :
 *   ms-word:ofe|u|file://hôte/DFS/dossier/Nom%20du%20fichier.docx
 *
 * Registre extensible : une entrée = extensions, application, icône Tabler,
 * schéma d'URI Office.
 */
final class LinkBuilder
{
    /** Ouverture en édition. */
    public const MODE_EDIT = 'ofe';

    /** Ouverture en lecture seule. */
    public const MODE_VIEW = 'ofv';

    /**
     * @var array<string, array{application: string, icon: string, scheme: string, extensions: list<string>}>
     */
    private const OFFICE_TYPES = [
        'word' => [
            'application' => 'Word',
            'icon'        => 'ti-file-type-doc',
            'scheme'      => 'ms-word',
            'extensions'  => ['doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'rtf'],
        ],
        'excel' => [
            'application' => 'Excel',
            'icon'        => 'ti-file-type-xls',
            'scheme'      => 'ms-excel',
            'extensions'  => ['xls', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'csv'],
        ],
        'powerpoint' => [
            'application' => 'PowerPoint',
            'icon'        => 'ti-file-type-ppt',
            'scheme'      => 'ms-powerpoint',
            'extensions'  => ['ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'potx'],
        ],
        'visio' => [
            'application' => 'Visio',
            'icon'        => 'ti-sitemap',
            'scheme'      => 'ms-visio',
            'extensions'  => ['vsd', 'vsdx', 'vsdm'],
        ],
    ];

    /**
     * Type Office du fichier, ou null s'il n'a pas d'application associée
     * (pdf, html, txt, images...).
     *
     * @return array{key: string, application: string, icon: string, scheme: string}|null
     */
    public static function officeType(string $canonical): ?array
    {
        $extension = self::extension($canonical);
        foreach (self::OFFICE_TYPES as $key => $type) {
            if (in_array($extension, $type['extensions'], true)) {
                return [
                    'key'         => $key,
                    'application' => $type['application'],
                    'icon'        => $type['icon'],
                    'scheme'      => $type['scheme'],
                ];
            }
        }
        return null;
    }

    /**
     * URI Office (ms-word:ofe|u|file://...), ou null pour un type non Office.
     *
     * @param string $canonical Chemin renvoyé par PathNormalizer::normalize().
     * @param string $mode      MODE_EDIT ou MODE_VIEW.
     */
    public static function officeUri(string $canonical, string $mode = self::MODE_EDIT): ?string
    {
        if ($mode !== self::MODE_EDIT && $mode !== self::MODE_VIEW) {
            throw new InvalidArgumentException('Unknown Office URI mode.');
        }
        $type = self::officeType($canonical);
        if ($type === null) {
            return null;
        }
        return $type['scheme'] . ':' . $mode . '|u|' . PathNormalizer::toFileUri($canonical);
    }

    /**
     * URI file:// du dossier contenant le fichier (« Afficher dans
     * l'Explorateur »), avec la barre finale.
     */
    public static function folderUri(string $canonical): string
    {
        $position = strrpos($canonical, '\\');
        if ($position === false || $position < 2) {
            throw new InvalidArgumentException('A canonical UNC path is expected.');
        }
        return PathNormalizer::toFileUri(substr($canonical, 0, $position)) . '/';
    }

    /**
     * Nom du fichier (dernier segment).
     */
    public static function fileName(string $canonical): string
    {
        $position = strrpos($canonical, '\\');
        return $position === false ? $canonical : substr($canonical, $position + 1);
    }

    /**
     * Toutes les extensions Office connues, par type (pour le JavaScript).
     *
     * @return array<string, array{application: string, extensions: list<string>}>
     */
    public static function officeExtensions(): array
    {
        $result = [];
        foreach (self::OFFICE_TYPES as $key => $type) {
            $result[$key] = ['application' => $type['application'], 'extensions' => $type['extensions']];
        }
        return $result;
    }

    private static function extension(string $canonical): string
    {
        $name     = self::fileName($canonical);
        $position = strrpos($name, '.');
        return $position === false ? '' : mb_strtolower(substr($name, $position + 1), 'UTF-8');
    }
}
