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
 * Chemin refusé par PathNormalizer.
 *
 * Le motif (getReason()) est une constante stable : la couche GLPI s'en sert
 * pour afficher un message traduit. Le message de l'exception, en anglais,
 * n'est destiné qu'aux journaux et aux tests.
 */
final class InvalidPathException extends InvalidArgumentException
{
    /** Saisie vide (après suppression des espaces et guillemets). */
    public const EMPTY = 'empty';

    /** Saisie ou chemin final trop long. */
    public const TOO_LONG = 'too_long';

    /** Encodage invalide (UTF-8 incorrect). */
    public const INVALID_ENCODING = 'invalid_encoding';

    /** Caractère de contrôle ou de mise en forme bidirectionnelle. */
    public const CONTROL_CHAR = 'control_char';

    /** Ni chemin UNC, ni URI file://, ni lettre de lecteur. */
    public const NOT_UNC = 'not_unc';

    /** Préfixe de périphérique Win32 (\\?\ ou \\.\). */
    public const DEVICE_PATH = 'device_path';

    /** Lettre de lecteur sans correspondance configurée. */
    public const UNMAPPED_DRIVE = 'unmapped_drive';

    /** Segment « .. » (ou équivalent encodé / composé de points). */
    public const DOT_SEGMENT = 'dot_segment';

    /** Caractère interdit dans un nom Windows, ou espace/point final. */
    public const FORBIDDEN_CHAR = 'forbidden_char';

    /** Chemin de dossier (v1 : fichiers uniquement). */
    public const FOLDER = 'folder';

    /** Aucune racine autorisée n'est configurée. */
    public const NO_ALLOWED_ROOTS = 'no_allowed_roots';

    /** Le chemin n'est sous aucune racine autorisée. */
    public const ROOT_NOT_ALLOWED = 'root_not_allowed';

    private string $reason;

    public function __construct(string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
        $this->reason = $reason;
    }

    /**
     * Motif du refus (une des constantes de cette classe).
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
