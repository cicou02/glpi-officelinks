# OfficeLinks — plugin GLPI

Plugin GLPI qui ajoute à la fiche **Document** un champ « Chemin réseau »
(chemin UNC vers le partage DFS) et génère à l'affichage des liens d'ouverture
native : protocoles Office URI (`ms-word:`, `ms-excel:`…) pour les fichiers
Office, copie du chemin pour les autres types. Aucun fichier n'est stocké dans
GLPI.

> **État : en développement (étape 1, squelette).** Le plugin s'installe et
> s'active, mais n'ajoute encore aucune fonctionnalité visible.

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

## Développement

```bash
composer install
```

```bash
composer test
```

Les sources de GLPI servant de référence de compatibilité sont clonées dans
`.glpi-src/` (non versionné) ; voir [docs/compat.md](docs/compat.md).

## Licence

GPL-3.0-or-later — voir [LICENSE](LICENSE).
