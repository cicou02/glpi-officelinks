# OfficeLinks — plugin GLPI

## Objectif

Plugin GLPI (clé technique `officelinks`, nom affiché « OfficeLinks ») qui ajoute à la fiche **Document** un champ « Chemin réseau » contenant un chemin UNC vers le partage DFS de l'entreprise, et qui génère à l'affichage des liens d'ouverture native :

- protocoles Office URI (`ms-word:`, `ms-excel:`…) pour les fichiers Office, ouverts en édition directement depuis le DFS ;
- copie du chemin (et, en option, affichage dans l'Explorateur) pour les autres types.

Aucun binaire n'est stocké dans GLPI. Le but est d'indexer les documents, d'accélérer les recherches et de centraliser les points d'accès dans GLPI.

## Hors périmètre — décisions arrêtées, ne pas les proposer

- Modifier le cœur de GLPI ou utiliser le champ natif `link` (« Lien web ») : `Document::prepareInputForAdd/Update()` le valide avec `Toolbox::isValidWebUrl()`, qui n'accepte que http(s).
- Monter le DFS sur le serveur GLPI, explorateur de fichiers côté serveur, vérification de l'existence des fichiers, détection de liens morts.
- Gestionnaire de protocole Windows maison (`dfs://`…), extension de navigateur, native messaging.
- Sélecteur de fichiers navigateur : aucune API web n'expose le chemin absolu d'un fichier.
- Ouverture des PDF/HTML dans le navigateur : impossible depuis `file://` sans serveur web, donc hors périmètre v1.

## Compatibilité : GLPI 11.0.x ET 12.0.x avec le même code

- PHP 8.2 minimum (GLPI 11 exige 8.2, GLPI 12 exige 8.3) : n'utiliser aucune fonctionnalité postérieure à PHP 8.2.
- `plugin_version_officelinks()` : `requirements` → glpi `min` 11.0.0, `max` 12.0.99 ; php `min` 8.2.
- Sources de référence clonées en lecture seule, **non versionnées** (`.gitignore`) :
  - `.glpi-src/11.0.8` (tag `11.0.8`, version de production)
  - `.glpi-src/12.0.1` (tag `12.0.1`)
- **Avant d'utiliser une API du cœur** (classe, méthode, hook, macro Twig, constante), vérifier dans les DEUX arborescences qu'elle existe avec la même signature. Ne jamais se fier à la mémoire pour une API GLPI : elles ont beaucoup changé entre 10, 11 et 12. Tenir à jour `docs/compat.md` (API utilisée → fichier:ligne en 11 et en 12).

Règles de compatibilité déjà identifiées :

- **CSRF** : ne jamais écrire `_glpi_csrf_token`, `csrf_token()`, `fields.csrfField()` ni d'en-tête `X-Glpi-Csrf-Token` (dépréciés en 12, la protection y passe par les en-têtes `Sec-Fetch-Site`/`Origin`). Un formulaire propre au plugin se ferme avec `Html::closeForm()`, qui ajoute le jeton en 11 et ne l'ajoute plus en 12. Le champ sur la fiche Document est envoyé par le formulaire natif : rien à faire.
- **Pas d'endpoint AJAX en v1** : toute la logique côté client est en JavaScript statique.
- **Éviter de sous-classer `CommonDBTM` / `CommonGLPI`** (typage des paramètres renforcé en 12). Si c'est indispensable (par exemple pour la jointure de recherche), ne surcharger aucune méthode dont la signature diffère entre 11 et 12.
- **Base de données** : `$DB->request()`, `insert()`, `updateOrInsert()`, `delete()` avec des tableaux de critères. SQL brut uniquement pour `CREATE`/`DROP TABLE` à l'installation et la désinstallation.
- **Ressources web** : elles vivent dans `public/` et sont appelées sans `/public` dans l'URL (`/plugins/officelinks/js/...`). Les scripts PHP de page vont dans `front/`.

## Structure

Le dossier d'installation dans GLPI doit s'appeler `officelinks` (et non `glpi-officelinks`).

```
setup.php            version, prérequis, enregistrement des hooks
hook.php             install / uninstall, options de recherche
src/                 PSR-4, namespace GlpiPlugin\Officelinks (à confirmer dans les sources)
templates/           Twig, espace de noms @officelinks
public/js/ public/css/
front/config.form.php
locales/             domaine gettext `officelinks`, source en anglais, traduction fr_FR
tests/               PHPUnit (normalisation, validation, encodage)
docs/compat.md
README.md CHANGELOG.md LICENSE (GPL-3.0-or-later)
```

## Données

- Table `glpi_plugin_officelinks_documents` : `id`, `documents_id` (unique, référence `glpi_documents`), `unc_path` VARCHAR(1024), `date_creation`, `date_mod`. Charset, collation et signe de clé via les helpers de `DBConnection`.
- Configuration via `Config` (contexte `plugin:officelinks`) :
  - `allowed_roots` : liste de racines UNC autorisées ;
  - `drive_mappings` : lettre de lecteur → racine UNC (ex. `I:` → `\\internal.example.com\DFS\Informatique`) ;
  - `host_aliases` : chemin d'un serveur cible → chemin d'espace de noms DFS ;
  - `enable_file_links` : booléen, **désactivé par défaut**.
- On stocke **uniquement le chemin UNC canonique**. L'URI est générée à l'affichage, pour pouvoir changer les règles de génération sans migrer de données.
- La désinstallation supprime la table et la configuration.
- Aucune valeur propre à l'entreprise (nom de domaine, racines) n'est écrite en dur dans le code : tout passe par la configuration.

## Comportement sur la fiche Document

- `post_item_form` : n'agit que si l'objet est une instance de `Document`. Affiche le champ texte `_officelinks_path` (le préfixe `_` garantit que la valeur n'est jamais écrite dans `glpi_documents`), un aperçu et les boutons. Réutiliser les macros de `components/form/fields_macros.html.twig`.
- `pre_item_add` / `pre_item_update` (Document) : normaliser puis valider. En cas de chemin invalide, `$item->input = false` et message via `Session::addMessageAfterRedirect()`. Une valeur vide supprime le lien.
- `item_add` / `item_update` : upsert. `item_purge` : suppression.
- Droits : la saisie suit le droit de modification du Document ; la page de configuration exige le droit `config` en mise à jour.
- Option de recherche via `plugin_officelinks_getAddSearchOptionsNew()` (identifiant fixe, réservé au plugin, jointure `child`) pour afficher et rechercher le chemin dans Gestion > Documents.
- JavaScript : si le nom du Document est vide, le préremplir avec le nom du fichier (sans extension) au moment de la saisie du chemin.

## Normalisation et validation

Une seule logique, dans une **classe PHP pure sans dépendance à GLPI** (testable seule), qui fait foi. Le JavaScript la reproduit uniquement pour l'aperçu.

1. Supprimer les espaces et guillemets autour du chemin (la commande « Copier en tant que chemin d'accès » de l'Explorateur, Ctrl+Maj+C, ajoute des guillemets).
2. Accepter `\\hôte\...`, `//hôte/...`, `file://hôte/...` (avec décodage des `%XX`), et les lettres de lecteur traduites via `drive_mappings`. `host_aliases` ramène un serveur cible vers l'espace de noms DFS.
3. Uniformiser les séparateurs en `\`, fusionner les doublons, retirer les segments `.`, **rejeter** tout segment `..` (y compris sous forme encodée).
4. La racine doit correspondre à une entrée de `allowed_roots`, sans tenir compte de la casse et **segment par segment** : `\\h\DFS-evil` ne correspond pas à `\\h\DFS`. Réécrire la racine avec la casse de la configuration.
5. Refuser : chemin non UNC, préfixes `\\?\` et `\\.\`, caractères de contrôle, longueur supérieure à 1024, chemin de dossier (v1 = fichiers uniquement).
6. Si `allowed_roots` est vide, **refuser tout enregistrement** (jamais « tout accepter »).

## Génération des liens (à l'affichage)

Format validé en test réel sur le parc (Edge, Microsoft 365) :

```
ms-word:ofe|u|file://internal.example.com/DFS/Informatique/Info/Docs/Linux/Installation%20de%20Unattended-Upgrade.docx
```

- `file://` + hôte + segments encodés **un par un** avec `rawurlencode()` (UTF-8 : espaces, accents, `#`, `%`, `&`).
- Registre extensible des types (une entrée = extensions, libellé, icône Tabler, générateur d'URI) :

| Extensions | Application | URI |
|---|---|---|
| doc, docx, docm, dot, dotx, dotm, rtf | Word | `ms-word:` |
| xls, xlsx, xlsm, xlsb, xltx, xltm, csv | Excel | `ms-excel:` |
| ppt, pptx, pptm, pps, ppsx, potx | PowerPoint | `ms-powerpoint:` |
| vsd, vsdx, vsdm | Visio | `ms-visio:` |
| autres (pdf, html, txt, images…) | — | pas d'URI |

- Boutons :
  - « Ouvrir dans <Application> » (`ofe`) et « Lecture seule » (`ofv`) pour les types Office ;
  - « Copier le chemin » (`navigator.clipboard`, chemin UNC brut) pour tous les types ;
  - si `enable_file_links` est activé : « Afficher dans l'Explorateur » (lien `file://` ; nécessite la stratégie Edge `IntranetFileLinksEnabled` sur les postes).
- Échappement Twig par défaut. Jamais de filtre `|raw` sur une valeur issue de la saisie.

## Sécurité — non négociable

La validation côté serveur est la vraie frontière de sécurité. Un chemin UNC arbitraire placé dans un lien `ms-word:` peut faire fuiter le hash NetNTLM de l'utilisateur vers un hôte tiers. D'où :

- seules les `allowed_roots` sont acceptées ;
- tests PHPUnit couvrant au minimum : `\\attaquant\x\a.docx`, `..` et `%2e%2e`, `\\?\UNC\...`, `\\.\...`, séparateurs mélangés, Unicode, préfixe de racine partiel, lettre de lecteur non mappée, URL http(s), `javascript:`, chaîne vide, longueur excessive.

## Méthode de travail

- Avancer **étape par étape**, une branche ou un commit par étape, sans anticiper l'étape suivante.
- Lancer `php -l` sur chaque fichier PHP et PHPUnit sur la classe de normalisation.
- Les tests fonctionnels sont faits par un humain sur une instance de test GLPI 11 (puis 12) : terminer chaque étape par une **checklist de test manuel**.
- Commentaires et documentation en français. Chaînes d'interface traduisibles avec `__('…', 'officelinks')`, source en anglais.

## Étapes

1. Squelette : `setup.php`, `hook.php` (installation de la table et configuration par défaut vide), `.gitignore`, `composer.json` (PHPUnit en dépendance de développement), README minimal, `docs/compat.md`.
2. Classe de normalisation, de validation et d'encodage, avec ses tests.
3. Page de configuration (racines, correspondances de lecteurs, alias d'hôtes, option `file://`).
4. Hooks de la fiche Document : champ, validation, persistance, purge.
5. Génération des liens, JavaScript (aperçu, copie, préremplissage du nom) et CSS.
6. Option de recherche.
7. Traductions fr_FR et README complet : installation, et déploiement GPO côté postes (zone Intranet local pour le DFS, `AutoLaunchProtocolsFromOrigins` pour l'origine GLPI, `IntranetFileLinksEnabled` en option).
