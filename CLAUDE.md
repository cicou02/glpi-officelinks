# Plugin GLPI « dfslink » — Instructions projet

## Contexte

Plugin GLPI permettant de référencer, dans une fiche **Document** (Gestion > Documents),
un fichier hébergé sur un partage réseau **DFS Windows**, sans stocker le binaire dans GLPI.
Objectif métier : indexer les documents, accélérer les recherches et centraliser les points
d'accès dans GLPI. L'ouverture se fait nativement sur le poste client (Word, Excel…),
directement à la source sur le DFS, en édition.

Mécanisme client **validé en conditions réelles** (Edge + Microsoft 365 Apps) :

```
ms-word:ofe|u|file://<hôte-dfs>/<chemin>/<fichier>.docx
```

(segments encodés en %XX, ex. espace = %20). Une invite de sécurité Office s'affiche : c'est attendu.

## Périmètre

### Inclus (V1)
- Champ « Chemin DFS » ajouté dans le formulaire principal du Document.
- Normalisation et validation stricte du chemin saisi (voir « Règles de chemin »).
- Stockage du **chemin UNC canonique uniquement**.
- Génération à l'affichage du lien d'ouverture (protocole Office selon l'extension).
- Bouton « Copier le chemin » pour tous les fichiers.
- Colonne recherchable « Chemin DFS » dans la liste des Documents.
- Pré-remplissage du nom et du type MIME du Document à partir du fichier.
- Page de configuration du plugin (racines autorisées, correspondances lecteurs/serveurs).
- Fonctionnement identique via l'API GLPI (clé d'entrée `_dfslink_path`) — à vérifier.

### Exclu (ne pas proposer, ne pas implémenter)
- Tout montage du DFS sur le serveur GLPI, tout accès serveur aux fichiers (V2 abandonnée).
- Toute modification du cœur de GLPI ou du champ natif `link` des Documents.
- Tout protocole maison (`dfs://` ou autre) et tout exécutable côté poste client.
- Toute extension de navigateur.

## Compatibilité cible : GLPI 11.0.x ET 12.0.x avec le même code

- `plugin_version_dfslink()` : `requirements.glpi.min = 11.0.0`, `max = 12.0.99`.
- **Syntaxe PHP 8.2 maximum** (GLPI 11 exige PHP ≥ 8.2, GLPI 12 ≥ 8.3) :
  pas de constantes de classe typées, pas d'attribut `#[\Override]`, pas de `json_validate()`,
  ni aucune autre fonctionnalité propre à PHP 8.3+.
- Hooks utilisés (présents et identiques dans 11.0.8 et 12.0.1) : `post_item_form`,
  `pre_item_add`, `pre_item_update`, `item_add`, `item_update`, `item_purge`,
  `add_javascript`, `config_page`, et la fonction `plugin_dfslink_getAddSearchOptionsNew()`.
  Utiliser les constantes `Glpi\Plugin\Hooks::*`.
- **Interdit** (supprimé ou déprécié en GLPI 12) : hook/constante `csrf_compliant`,
  macro Twig `fields.csrfField()`, fonction Twig `csrf_token()`, `Plugin::getWebDir()`,
  `DBmysql::query()`, `queryOrDie()`, `doQueryOrDie()`, `insertOrDie()`, `updateOrDie()`,
  `deleteOrDie()`, `Migration::display*()`, `Glpi\Toolbox\Sanitizer`, `Toolbox::isUrlSafe()`,
  `Toolbox::addslashes_deep()`, Guzzle, `league/csv`.
- **CSRF** : ne jamais écrire le jeton à la main. Fermer les formulaires propres au plugin
  avec `Html::closeForm()` (ajoute le jeton en 11, plus rien en 12). Le champ ajouté dans
  le formulaire Document n'a pas besoin de traitement : il part avec le formulaire natif.
- Avant d'utiliser une classe, méthode, macro ou hook GLPI : **vérifier sa signature dans
  les deux sources de référence** (`.reference/glpi-11` et `.reference/glpi-12`).
  Ne jamais se fier à la mémoire pour les API internes de GLPI.

## Architecture

- Racine du dépôt = racine du plugin. Clé du plugin : `dfslink` (le dossier d'installation
  doit s'appeler `plugins/dfslink`).
- Arborescence :
  - `setup.php` : version, prérequis, enregistrement des hooks.
  - `hook.php` : install/uninstall, options de recherche.
  - `src/` : classes PSR-4, namespace `GlpiPlugin\Dfslink\`.
  - `templates/` : Twig, accessible via `@dfslink/...`.
  - `public/` : ressources web (JS/CSS), servies **sans** le préfixe `/public` dans l'URL.
  - `locales/` : `fr_FR` et `en_GB`.
  - `tests/` : tests unitaires.
- `src/PathNormalizer.php` : **classe pure, sans aucune dépendance à GLPI**, contenant
  toute la logique de normalisation, de validation et de génération d'URI. C'est la partie
  sensible du projet : elle doit être couverte par des tests unitaires exhaustifs.
- Flux de données :
  1. `post_item_form` : si l'item est un `Document`, afficher le champ `_dfslink_path`
     (macros natives `fields_macros.html.twig`), l'aperçu et les boutons.
  2. `pre_item_add` / `pre_item_update` : normaliser et valider `$item->input['_dfslink_path']`.
     En cas de refus : message via `Session::addMessageAfterRedirect()` et `$item->input = false`.
     Pré-remplir `name` (si vide) et `mime`.
  3. `item_add` / `item_update` : upsert dans la table du plugin.
  4. `item_purge` : suppression de la ligne liée.
- Le préfixe `_` garantit que la valeur n'est jamais écrite dans `glpi_documents`.

## Modèle de données

Table `glpi_plugin_dfslink_dfslinks` :
`id` (PK), `documents_id` (FK, **unique**), `path` (VARCHAR(1024), chemin UNC canonique),
`date_creation`, `date_mod`.
Créer avec `DBConnection::getDefaultCharset()`, `getDefaultCollation()`,
`getDefaultPrimaryKeySignOption()`. Accès BDD uniquement via `$DB->request()`,
`$DB->insert()`, `$DB->update()`, `$DB->delete()` (et `doQuery()` pour le DDL d'installation).

Configuration du plugin stockée via `Config::setConfigurationValues('plugin:dfslink', ...)` :
- `allowed_roots` : liste de préfixes UNC autorisés.
- `drive_mappings` : lettre de lecteur → préfixe UNC.
- `server_aliases` : préfixe UNC d'un serveur cible → préfixe UNC de l'espace de noms DFS.
- `enable_file_links` : booléen, affiche un lien `file://` (stratégie Edge `IntranetFileLinksEnabled`).

Page de configuration réservée aux utilisateurs ayant le droit de modifier la configuration.

**Aucune valeur réelle de l'entreprise (nom de domaine, serveur, chemin) ne doit apparaître
dans le code, les tests, la documentation ou les commits.** Utiliser `example.com` /
`\\dfs.example.com\DFS\...` dans les exemples et tests.

## Règles de chemin (PathNormalizer)

Entrées acceptées puis normalisées vers `\\hôte\segment\...\fichier.ext` :
- Guillemets et espaces en bord supprimés (format de « Copier en tant que chemin d'accès »).
- `\\hôte\...`, `//hôte/...`, `file://hôte/...`, `file://///hôte/...` (décoder les `%XX`).
- `X:\...` : traduit via `drive_mappings` ; refusé si la lettre n'est pas mappée.
- Préfixes listés dans `server_aliases` : remplacés par le préfixe DFS correspondant.
- Séparateurs unifiés, doublons de séparateurs supprimés.

Refus systématique :
- Segment `..` ou `.`, caractère de contrôle, chemin vide, absence de nom de fichier.
- Chemin ne commençant pas par une racine de `allowed_roots` (comparaison insensible à la
  casse, sur des frontières de segment : `\\h\DFS\Info` n'autorise pas `\\h\DFS\Informatique`).
- Toute forme non UNC après normalisation.

La validation serveur fait autorité. Le JS côté client ne sert qu'à l'aperçu.

## Génération des liens (à l'affichage uniquement)

| Extensions | Lien |
|---|---|
| doc, docx, docm, dot, dotx, dotm, rtf | `ms-word:ofe|u|file://...` |
| xls, xlsx, xlsm, xlsb, xltx, csv | `ms-excel:ofe|u|file://...` |
| ppt, pptx, pptm, pps, ppsx, potx | `ms-powerpoint:ofe|u|file://...` |
| vsd, vsdx | `ms-visio:ofe|u|file://...` |
| autres | pas de lien Office ; bouton « Copier le chemin » (+ lien `file://` si activé) |

- Encodage : `rawurlencode()` sur **chaque segment** (UTF-8), séparateurs `/` non encodés.
  Les caractères `#`, `%`, `&`, espaces et accents doivent être couverts par les tests.
- Échappement Twig par défaut conservé sur les attributs `href` (jamais de `|raw` sur
  une donnée issue de la saisie).
- Bouton « Copier le chemin » : `navigator.clipboard.writeText()`.

## Environnement de test

- Sources de référence (non versionnées, dans `.gitignore`) : extraire `/var/www/glpi` des
  images Docker officielles `glpi/glpi:11.0.8` et `glpi/glpi:12.0.1` vers
  `.reference/glpi-11` et `.reference/glpi-12`.
- Instances de test : `docker compose` (dossier `dev/`) avec MariaDB + `glpi/glpi:11.0.8`
  et `glpi/glpi:12.0.1`, plugin monté en volume dans `plugins/dfslink`.
  Installation et activation via `bin/console plugin:install` / `plugin:activate`.
- Vérifications obligatoires avant chaque commit :
  `php -l` sur tous les fichiers, tests unitaires, analyse statique (PHPStan configuré
  avec `phpVersion: 80200` et les sources de référence en `scanDirectories`).
- Le test réel d'ouverture Office se fait sur un poste Windows par le mainteneur :
  ne pas prétendre l'avoir validé depuis l'environnement de développement.

## Méthode de travail

- Avancer **une étape à la fois**, sur une branche dédiée, avec une PR par étape.
- À la fin de chaque étape : résumer ce qui a été fait, ce qui a été vérifié
  (et sur quelle version de GLPI), et ce qui reste à tester manuellement.
- En cas de doute sur un choix d'architecture ou de dépassement du périmètre : **demander**,
  ne pas improviser.
- Messages de commit et commentaires de code en français ; noms de code en anglais.

## Étapes

0. Environnement : sources de référence, `docker compose` de test, PHPStan, PHPUnit.
1. Squelette : `setup.php`, `hook.php`, install/uninstall (table + config), activation OK en 11 et 12.
2. `PathNormalizer` + tests unitaires exhaustifs.
3. Champ dans le formulaire Document + hooks de validation, sauvegarde, purge.
4. Affichage : boutons d'ouverture, copie du chemin, aperçu JS.
5. Option de recherche + pré-remplissage nom/MIME + vérification via l'API.
6. Page de configuration.
7. Traductions, README, archive de release.
