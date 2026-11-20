# OfficeLinks — plugin GLPI

🇬🇧 [English version](README.md)

OfficeLinks ajoute à la fiche **Document** de GLPI un champ « Chemin réseau »
qui contient le chemin UNC d'un fichier sur le partage DFS de l'entreprise.
À l'affichage, le plugin génère des liens d'ouverture native :

- **fichiers Office** (Word, Excel, PowerPoint, Visio) : bouton « Ouvrir dans
  Word » (protocole `ms-word:` et équivalents), qui ouvre le fichier **en
  édition, directement sur le DFS** ;
- **tous les fichiers** : bouton « Copier le chemin » ;
- **en option** : bouton « Afficher dans l'Explorateur ».

Aucun fichier n'est stocké dans GLPI : le plugin indexe les documents,
accélère les recherches et centralise les points d'accès.

Sommaire : [Prérequis](#prérequis) · [Installation](#installation) ·
[Mise à jour](#mise-à-jour) · [Configuration](#configuration) ·
[Utilisation](#utilisation) · [Déploiement sur les postes](#déploiement-sur-les-postes-gpo) ·
[Limites connues](#limites-connues) · [Dépannage](#dépannage) ·
[Développement](#développement)

## Prérequis

- GLPI 11.0.x ou 12.0.x (même code pour les deux versions)
- PHP 8.2 ou plus, avec les extensions `intl` et `mbstring` (déjà exigées par GLPI)
- Postes : Windows, Microsoft Edge et Microsoft 365 (ou Office 2013 et plus)

## Installation

1. Placer le plugin dans le dossier `plugins/` de GLPI, **sous le nom
   `officelinks`** (et non `glpi-officelinks`) :

   - **depuis une release (recommandé)** : télécharger
     `officelinks-X.Y.Z.zip` sur la page
     [Releases](https://github.com/cicou02/glpi-officelinks/releases) et
     l'extraire dans `plugins/` ; l'archive contient déjà un dossier
     `officelinks/` avec uniquement les fichiers nécessaires ;
   - **depuis Git** :

     ```bash
     git clone https://github.com/cicou02/glpi-officelinks.git /var/www/glpi/plugins/officelinks
     ```

     Le dossier `vendor/` n'est pas nécessaire en production : Composer ne
     sert qu'aux tests.

2. Donner le dossier à l'utilisateur du serveur web (par exemple
   `chown -R www-data: /var/www/glpi/plugins/officelinks`).

3. Dans GLPI : *Configuration > Plugins*, **Installer** puis **Activer**
   « OfficeLinks ». En ligne de commande, depuis la racine de GLPI :

   ```bash
   php bin/console plugin:install --username=glpi officelinks
   ```

   ```bash
   php bin/console plugin:activate officelinks
   ```

4. Configurer au moins une racine autorisée (voir [Configuration](#configuration)) :
   tant qu'aucune racine n'est configurée, aucun chemin ne peut être enregistré.

L'installation crée la table `glpi_plugin_officelinks_documents` et la
configuration (contexte `plugin:officelinks`, vide par défaut). La
désinstallation supprime les deux.

## Mise à jour

1. Remplacer le contenu du dossier `plugins/officelinks/` par la nouvelle
   version (archive de release ou `git pull`). La configuration et les
   chemins enregistrés sont conservés : ils sont en base.
2. *Configuration > Plugins* : GLPI détecte le nouveau numéro de version,
   désactive le plugin et affiche le bouton **Mettre à jour**. Cliquer dessus,
   puis sur **Activer**.
3. Recharger la page avec Ctrl+F5.

La mise à jour vide elle-même le cache des templates de GLPI : pas besoin de
`cache:clear`. La page de configuration du plugin signale quand une version
plus récente est publiée sur GitHub (vérification faite par le navigateur de
l'administrateur, mise en cache 12 heures).

**Fichiers modifiés sans changement de version** (développement, `git pull`
d'une branche) : GLPI ne propose pas de mise à jour et ne recompile pas les
templates en production ; vider le cache à la main :

```bash
php bin/console cache:clear
```

## Configuration

*Configuration > Plugins > OfficeLinks* (icône « Configurer »). La page exige
le droit « Configuration » en modification. Une entrée par ligne :

| Champ | Format | Exemple |
|---|---|---|
| Racines autorisées | `\\hôte\partage[\dossier]` | `\\internal.example.com\DFS\Informatique` |
| Lettres de lecteur | `X: = \\hôte\partage[\dossier]` | `I: = \\internal.example.com\DFS\Informatique` |
| Alias d'hôtes | `\\serveur\partage = \\hôte\espace-de-noms[\dossier]` | `\\srv-files01\Informatique$ = \\internal.example.com\DFS\Informatique` |
| Afficher le bouton « Afficher dans l'Explorateur » | case à cocher | désactivé par défaut |

- **Racines autorisées** : seuls les fichiers situés sous l'une de ces racines
  peuvent être liés. La comparaison ignore la casse et se fait dossier par
  dossier (`\\hôte\DFS-test` n'est pas sous `\\hôte\DFS`). La racine est
  réécrite avec la casse de la configuration.
- **Lettres de lecteur** : un chemin saisi comme `I:\Info\a.docx` est traduit
  en chemin UNC.
- **Alias d'hôtes** : un chemin saisi avec le serveur cible
  (`\\srv-files01\...`) est réécrit vers l'espace de noms DFS.
- Les cibles des lecteurs et des alias doivent se trouver sous une racine
  autorisée. Si une ligne est invalide, rien n'est enregistré et les erreurs
  sont listées avec leur numéro de ligne.

### Sécurité

La liste des racines autorisées est la vraie protection du plugin. Un lien
`ms-word:` vers un serveur arbitraire peut faire envoyer à ce serveur le hash
NetNTLM de l'utilisateur qui clique. Le serveur GLPI valide donc chaque
chemin, et revalide le chemin enregistré à chaque affichage : si une racine est
retirée de la configuration, les documents concernés n'affichent plus de lien
d'ouverture (la copie du chemin reste possible).

## Utilisation

Sur la fiche d'un document (*Gestion > Documents*), le champ **Chemin réseau**
accepte :

- un chemin UNC : `\\internal.example.com\DFS\Informatique\Info\Procédure.docx` ;
- le résultat de « Copier en tant que chemin d'accès » de l'Explorateur
  (Ctrl+Maj+C), guillemets compris ;
- une lettre de lecteur configurée : `I:\Info\Procédure.docx` ;
- une URI `file://internal.example.com/DFS/...`.

Pendant la saisie, une ligne d'aperçu indique le chemin qui sera enregistré
(ou la raison d'un refus). Si le nom du document est vide, il est rempli avec
le nom du fichier sans extension. Seul le chemin UNC canonique est enregistré ;
un champ vidé supprime le lien.

Le chemin est aussi :

- **recherchable** : colonne et critère « Chemin réseau » dans
  *Gestion > Documents* ;
- **historisé** : chaque changement ajoute une ligne dans l'onglet
  *Historique* du document.

La saisie suit le droit de modification du document ; un utilisateur en
lecture seule voit le chemin et les boutons.

L'interface est disponible en français et en anglais.

## Déploiement sur les postes (GPO)

Trois réglages, à déployer par stratégie de groupe. Les stratégies Edge
nécessitent les modèles d'administration de Microsoft Edge (`MSEdge.admx`).
Dans les exemples, remplacer `glpi.example.com` et `internal.example.com` par
vos noms.

### 1. Zone Intranet local pour le DFS (recommandé)

Classe le partage DFS (et GLPI) en zone *Intranet local* : Office ouvre alors
les fichiers du DFS sans les traiter comme provenant d'Internet, et c'est une
condition du bouton « Afficher dans l'Explorateur ».

*Configuration ordinateur > Stratégies > Modèles d'administration >
Composants Windows > Internet Explorer > Panneau de configuration Internet >
Page Sécurité* :

- **Liste des attributions de sites aux zones** (*Site to Zone Assignment
  List*), zone `1` (Intranet local) :

  | Valeur | Zone |
  |---|---|
  | `file://internal.example.com` | `1` |
  | `https://glpi.example.com` | `1` |

- ou, pour le partage seulement, **Sites intranet : inclure tous les chemins
  réseau (UNC)** (*Intranet Sites: Include all network paths (UNCs)*) :
  Activé.

Les stratégies Edge suivantes utilisent cette même définition de la zone
Intranet.

### 2. Ouvrir Office sans confirmation : `AutoLaunchProtocolsFromOrigins` (recommandé)

Sans ce réglage, Edge demande à chaque clic s'il doit ouvrir Word.

*Configuration ordinateur > Stratégies > Modèles d'administration > Microsoft
Edge* : **Définir une liste de protocoles pouvant lancer une application
externe à partir des origines listées sans demander l'autorisation de
l'utilisateur** (*Define a list of protocols that can launch an external
application from listed origins without prompting the user*). Edge 85 et plus.

Valeur (JSON, sur une ligne dans la GPO) :

```json
[
  {"protocol": "ms-word",       "allowed_origins": ["https://glpi.example.com"]},
  {"protocol": "ms-excel",      "allowed_origins": ["https://glpi.example.com"]},
  {"protocol": "ms-powerpoint", "allowed_origins": ["https://glpi.example.com"]},
  {"protocol": "ms-visio",      "allowed_origins": ["https://glpi.example.com"]}
]
```

- Le nom du protocole s'écrit en minuscules, sans `:`.
- L'origine est celle de GLPI (schéma, nom d'hôte et port s'il n'est pas
  standard), **sans chemin** : une origine contenant `/glpi` est ignorée.
- Registre équivalent : clé `HKLM\SOFTWARE\Policies\Microsoft\Edge`, valeur
  `AutoLaunchProtocolsFromOrigins` (`REG_SZ`) contenant le JSON.

### 3. Bouton « Afficher dans l'Explorateur » : `IntranetFileLinksEnabled` (optionnel)

À n'utiliser que si l'option est cochée dans la configuration du plugin. Le
bouton ouvre l'Explorateur sur le dossier du fichier, **fichier sélectionné**.

*Configuration ordinateur > Stratégies > Modèles d'administration > Microsoft
Edge > Paramètres de contenu* : **Autoriser les liens d'URL de fichiers de la
zone intranet de Microsoft Edge à s'ouvrir dans l'Explorateur Windows**
(*Allow intranet zone file URL links from Microsoft Edge to open in Windows
File Explorer*) : Activé. Edge 95 et plus.

Conditions imposées par Edge :

- GLPI doit être servi en **HTTPS** et classé en zone Intranet local ;
- le partage DFS doit être en zone Intranet local (réglage 1).

Registre équivalent : clé `HKLM\SOFTWARE\Policies\Microsoft\Edge`, valeur
`IntranetFileLinksEnabled` (`REG_DWORD`) à `1`.

### Vérification sur un poste

1. `gpupdate /force`, puis redémarrer Edge.
2. Ouvrir `edge://policy` : `AutoLaunchProtocolsFromOrigins` (et, le cas
   échéant, `IntranetFileLinksEnabled`) apparaissent avec l'état « OK ».
3. Dans GLPI, « Ouvrir dans Word » sur un document : Word s'ouvre sans
   demande de confirmation d'Edge.

## Limites connues

- **Pas d'ouverture en lecture seule** : la commande Office `ofv` n'est prévue
  que pour des documents servis en http(s) ; pour un fichier du DFS, Office
  l'ignore et ouvre en édition. Le bouton n'est donc pas proposé.
- **Longueur des chemins** : Office n'ouvre pas les chemins de plus de 256
  caractères (216 pour Excel), même si le plugin en accepte jusqu'à 1024.
- **Historique** : GLPI limite chaque valeur à 255 caractères ; pour un chemin
  plus long, la fin (dossier, nom du fichier) est conservée, précédée de « … ».
- **Pas de vérification d'existence** : le serveur GLPI n'accède pas au DFS ;
  un fichier déplacé ou supprimé n'est pas détecté.
- **PDF, images, etc.** : pas d'ouverture directe depuis le navigateur ;
  utiliser « Copier le chemin » (ou « Afficher dans l'Explorateur »).

## Dépannage

| Symptôme | Cause probable |
|---|---|
| Après une mise à jour, rien n'a changé à l'écran | Version inchangée (pas de bouton « Mettre à jour ») : `php bin/console cache:clear`, puis Ctrl+F5. |
| Edge demande à chaque fois s'il faut ouvrir Word | `AutoLaunchProtocolsFromOrigins` absente, ou origine différente de l'adresse réelle de GLPI (schéma, port, chemin). Vérifier `edge://policy`. |
| « Afficher dans l'Explorateur » ne fait rien | `IntranetFileLinksEnabled` absente, GLPI en http, ou GLPI/DFS hors zone Intranet local. |
| « Chemin réseau refusé : … » à l'enregistrement | Le message donne la raison ; le plus souvent, chemin hors des racines autorisées ou lettre de lecteur non configurée. |
| Avertissement jaune « Le chemin enregistré n'est plus autorisé » | La racine du chemin a été retirée de la configuration : liens d'ouverture désactivés, la copie reste possible. |
| `Duplicate key 21500` dans `php-errors.log` | Un autre plugin utilise le même identifiant d'option de recherche sur Document. |

## Compatibilité

Testé sur GLPI 11.0.8. Le code est écrit et vérifié pour GLPI 11.0.x et
12.0.x (références dans [docs/compat.md](docs/compat.md)) ; la recette
fonctionnelle sur GLPI 12 reste à faire.

## Développement

```bash
composer install
```

```bash
composer test
```

`composer test` lance PHPUnit puis les tests Node (`node --test
tests/js/officelinks.test.js`, Node 18 ou plus) ; ces derniers comparent
l'aperçu JavaScript aux résultats de `PathNormalizer`, qui fait foi.

- **Sources de référence** : cloner les tags `11.0.8` et `12.0.1` de
  `glpi-project/glpi` dans `.glpi-src/` (non versionné) et y vérifier toute API
  du cœur avant de l'utiliser ; consigner le résultat dans
  [docs/compat.md](docs/compat.md).
- **Traductions** : chaînes en anglais avec `__('…', 'officelinks')`. Après
  tout ajout ou modification de chaîne :

  ```bash
  php tools/locales.php
  ```

  Le script met à jour `locales/officelinks.pot`, ajoute les nouvelles chaînes
  à `locales/fr_FR.po` (à traduire) et à `locales/en_GB.po`, puis compile les
  `.mo` (fr_FR, fr_CA, fr_BE, en_GB, en_US). `tests/LocalesTest.php` échoue
  si une traduction manque ou si un `.mo` n'est pas à jour.
- **Intégration continue** (GitHub Actions) :
  - `tests.yml` : `php -l`, PHPUnit et tests JavaScript sur PHP 8.2, 8.3 et
    8.4, à chaque pull request et à chaque push sur `main` ;
  - `release.yml` : construit `officelinks-X.Y.Z.zip` (liste blanche des
    fichiers utiles à GLPI : ni `vendor/`, ni tests, ni outils, ni fichiers de
    développement) et le joint au run de chaque pull request.
- **Publication d'une version** :
  1. augmenter `PLUGIN_OFFICELINKS_VERSION` dans `setup.php` (GLPI propose
     alors « Mettre à jour », et le numéro invalide le cache navigateur du
     JavaScript et du CSS) ;
  2. renommer la section `## [Non publié]` de `CHANGELOG.md` en
     `## [X.Y.Z] - AAAA-MM-JJ` ;
  3. fusionner sur `main`, puis créer et pousser le tag :

     ```bash
     git tag vX.Y.Z
     ```

     ```bash
     git push origin vX.Y.Z
     ```

     L'action `release.yml` vérifie que le tag correspond à la version,
     relance les tests et publie la release GitHub avec l'archive et les
     notes du CHANGELOG.

## Licence

GPL-3.0-or-later — voir [LICENSE](LICENSE).
