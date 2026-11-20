<?php

/**
 * OfficeLinks — plugin GLPI.
 *
 * @license GPL-3.0-or-later
 * @link    https://github.com/cicou02/glpi-officelinks
 */

namespace GlpiPlugin\Officelinks;

use Document;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Hooks de la fiche Document : champ « Chemin réseau », validation,
 * persistance et purge.
 *
 * Aucune sous-classe de CommonDBTM : les méthodes reçoivent l'objet Document
 * transmis par Plugin::doHook().
 */
final class DocumentHooks
{
    /**
     * Nom du champ du formulaire. Le préfixe « _ » garantit que GLPI ne
     * l'écrit jamais dans glpi_documents (CommonDBTM::add()/update()).
     */
    public const FIELD = '_officelinks_path';

    /**
     * post_item_form : affiche le champ sur la fiche Document uniquement.
     *
     * Appel standard (non indexé par type) : reçoit
     * ['item' => CommonGLPI, 'options' => array].
     *
     * @param mixed $params
     */
    public static function showField($params): void
    {
        $item = is_array($params) ? ($params['item'] ?? null) : null;
        if (!$item instanceof Document) {
            return;
        }

        $id      = $item->isNewItem() ? 0 : (int) $item->getID();
        $path    = $id > 0 ? DocumentPath::get($id) : null;
        $canedit = $id > 0
            ? Document::canUpdate() && $item->canUpdateItem()
            : Document::canCreate();

        if (!$canedit && $path === null) {
            return;
        }

        TemplateRenderer::getInstance()->display('@officelinks/document_field.html.twig', [
            'field'      => self::FIELD,
            'path'       => $path ?? '',
            'canedit'    => $canedit,
            'max_length' => PathNormalizer::MAX_LENGTH,
        ]);
    }

    /**
     * pre_item_add (Document) : normalise et valide le chemin saisi.
     */
    public static function preItemAdd(Document $item): void
    {
        self::normalizeInput($item);
    }

    /**
     * pre_item_update (Document) : normalise, valide, et s'assure que
     * item_update sera bien appelé.
     *
     * CommonDBTM::update() ne déclenche item_update que si une colonne de
     * glpi_documents change. Quand seul le chemin change, on met donc à jour
     * date_mod (sans l'historiser) ; si date_mod vaut déjà l'heure courante
     * (deux enregistrements dans la même seconde), on enregistre tout de suite.
     *
     * Un chemin enregistré mais devenu invalide (racine retirée de la
     * configuration) reste en place tant qu'il n'est pas modifié ou vidé.
     */
    public static function preItemUpdate(Document $item): void
    {
        if (!is_array($item->input) || !array_key_exists(self::FIELD, $item->input)) {
            return;
        }

        // Valeur renvoyée telle quelle par le formulaire : rien à faire. On ne
        // la revalide pas, pour qu'un changement de configuration (racine
        // retirée) n'empêche pas de modifier le reste du document.
        $id  = (int) $item->getID();
        $old = DocumentPath::get($id) ?? '';
        if ($item->input[self::FIELD] === $old) {
            unset($item->input[self::FIELD]);
            return;
        }

        if (!self::normalizeInput($item)) {
            return;
        }

        $new = $item->input[self::FIELD];
        if ($new === $old) {
            unset($item->input[self::FIELD]);
            return;
        }

        $now = $_SESSION['glpi_currenttime'] ?? null;
        if (is_string($now) && ($item->fields['date_mod'] ?? null) !== $now) {
            $item->input['date_mod'] = $now;
            if (!in_array('date_mod', $item->history_blacklist, true)) {
                $item->history_blacklist[] = 'date_mod';
            }
            return;
        }

        self::persist($id, $new);
        unset($item->input[self::FIELD]);
    }

    /**
     * item_add (Document) : enregistre le chemin du nouveau document.
     */
    public static function itemAdd(Document $item): void
    {
        $path = $item->input[self::FIELD] ?? null;
        if (is_string($path) && $path !== '') {
            DocumentPath::save((int) $item->getID(), $path);
        }
    }

    /**
     * item_update (Document) : enregistre ou supprime le chemin.
     */
    public static function itemUpdate(Document $item): void
    {
        $id   = (int) $item->getID();
        $path = $item->input[self::FIELD] ?? null;
        if (is_string($path)) {
            self::persist($id, $path);
        }
    }

    /**
     * item_purge (Document) : supprime le chemin du document purgé.
     */
    public static function itemPurge(Document $item): void
    {
        DocumentPath::delete((int) $item->getID());
    }

    /**
     * Normalise $item->input[FIELD] en place.
     *
     * @return bool true si le champ est présent et valide (chaîne canonique
     *              ou chaîne vide = suppression), false s'il est absent ou refusé
     *              (dans ce cas $item->input vaut false et un message est affiché).
     */
    private static function normalizeInput(Document $item): bool
    {
        if (!is_array($item->input) || !array_key_exists(self::FIELD, $item->input)) {
            return false;
        }

        $raw = $item->input[self::FIELD];
        if (!is_string($raw)) {
            self::reject(new InvalidPathException(InvalidPathException::NOT_UNC), '');
            $item->input = false;
            return false;
        }

        if (PathNormalizer::isBlank($raw)) {
            $item->input[self::FIELD] = '';
            return true;
        }

        try {
            $item->input[self::FIELD] = PluginConfig::load()->createNormalizer()->normalize($raw);
            return true;
        } catch (InvalidPathException $e) {
            self::reject($e, $raw);
            $item->input = false;
            return false;
        }
    }

    private static function persist(int $id, string $path): void
    {
        if ($path === '') {
            DocumentPath::delete($id);
        } else {
            DocumentPath::save($id, $path);
        }
    }

    /**
     * Message d'erreur (échappé : les messages après redirection sont
     * affichés sans échappement).
     */
    private static function reject(InvalidPathException $e, string $raw): void
    {
        $message = sprintf(
            __('Network path rejected: %1$s (%2$s)', 'officelinks'),
            self::reasonMessage($e->getReason()),
            mb_strimwidth(trim($raw), 0, 200, '…', 'UTF-8')
        );
        Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
    }

    /**
     * Texte traduit pour un motif de refus.
     */
    public static function reasonMessage(string $reason): string
    {
        return match ($reason) {
            InvalidPathException::EMPTY            => __('the path is empty', 'officelinks'),
            InvalidPathException::TOO_LONG         => __('the path is too long', 'officelinks'),
            InvalidPathException::INVALID_ENCODING => __('the path contains invalid characters', 'officelinks'),
            InvalidPathException::CONTROL_CHAR     => __('the path contains control characters', 'officelinks'),
            InvalidPathException::NOT_UNC          => __('a UNC path (\\\\server\\share\\...) or a mapped drive letter is expected', 'officelinks'),
            InvalidPathException::DEVICE_PATH      => __('\\\\?\\ and \\\\.\\ paths are not allowed', 'officelinks'),
            InvalidPathException::UNMAPPED_DRIVE   => __('this drive letter is not configured', 'officelinks'),
            InvalidPathException::DOT_SEGMENT      => __('".." is not allowed in the path', 'officelinks'),
            InvalidPathException::FORBIDDEN_CHAR   => __('the path contains a character forbidden by Windows', 'officelinks'),
            InvalidPathException::FOLDER           => __('the path must designate a file, not a folder', 'officelinks'),
            InvalidPathException::NO_ALLOWED_ROOTS => __('no allowed root is configured, contact your administrator', 'officelinks'),
            InvalidPathException::ROOT_NOT_ALLOWED => __('the path is outside the allowed roots', 'officelinks'),
            default                                => $reason,
        };
    }
}
