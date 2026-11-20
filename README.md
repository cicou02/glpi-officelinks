# OfficeLinks — plugin GLPI

Plugin GLPI qui ajoute à la fiche **Document** un champ « Chemin réseau »
(chemin UNC vers le partage DFS) et génère à l'affichage des liens d'ouverture
native : protocoles Office URI (`ms-word:`, `ms-excel:`…) pour les fichiers
Office, copie du chemin pour les autres types. Aucun fichier n'est stocké dans
GLPI.

> **État : en développement (étape 5).** Configuration, champ « Chemin
> réseau » et boutons d'ouverture disponibles. Restent : option de recherche,
> historique, traductions et documentation de déploiement sur les postes.

## Prérequis

- GLPI 11.0.x ou 12.0.x
- PHP 8.2 ou plus

## Installation

1. Copier le dépôt dans le dossier `plugins/` de GLPI sous le nom
   **`officelinks`** (et non `glpi-officelinks`) :

   ```bash
   git clone https://github.com/cicou02/glpi-officelinks.git /var/www/glpi/plugins/officelinks
   ```

2. Dans GLPI : *Configuration > Plugins*, installer puis activer « OfficeLinks ».

En ligne de commande, depuis la racine de GLPI :

```bash
php bin/console plugin:install --username=glpi officelinks
```

```bash
php bin/console plugin:activate officelinks
```

## Mise à jour du plugin

Après chaque mise à jour des fichiers du plugin (`git pull`, copie d'une
nouvelle version), vider le cache de GLPI, puis recharger la page avec
Ctrl+F5 :

```bash
php bin/console cache:clear
```

En environnement de production (cas de l'image Docker officielle), GLPI ne
recompile pas les templates Twig modifiés : sans cette commande, l'ancienne
version des écrans du plugin reste affichée.

## Configuration

*Configuration > Plugins > OfficeLinks* (icône « Configurer »), avec le droit
« Configuration » en mise à jour. Une entrée par ligne :

| Champ | Format | Exemple |
|---|---|---|
| Racines autorisées | `\\hôte\partage[\dossier]` | `\\internal.example.com\DFS\Informatique` |
| Lettres de lecteur | `X: = \\hôte\partage[\dossier]` | `I: = \\internal.example.com\DFS\Informatique` |
| Alias d'hôtes | `\\serveur\partage = \\hôte\espace-de-noms[\dossier]` | `\\srv-files01\Informatique$ = \\internal.example.com\DFS\Informatique` |

Tant qu'aucune racine n'est configurée, aucun chemin ne peut être enregistré.
Les cibles des lecteurs et des alias doivent se trouver sous une racine
autorisée.

## Développement

```bash
composer install
```

```bash
composer test
```

`composer test` lance PHPUnit puis les tests Node (`node --test
tests/js/officelinks.test.js`, Node 18 ou plus) ; ces derniers comparent
l'aperçu JavaScript aux résultats de `PathNormalizer`.

Les sources de GLPI servant de référence de compatibilité sont clonées dans
`.glpi-src/` (non versionné) ; voir [docs/compat.md](docs/compat.md).

## Licence

GPL-3.0-or-later — voir [LICENSE](LICENSE).
