# Changelog

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [Non publié]

### Ajouté

- Squelette du plugin : `setup.php`, `hook.php` (table
  `glpi_plugin_officelinks_documents`, configuration `plugin:officelinks`
  vide par défaut), `composer.json`, `docs/compat.md`.
- Classe pure `PathNormalizer` (normalisation, validation et encodage
  `file://` des chemins UNC) et exception `InvalidPathException` à motifs
  stables, avec leurs tests PHPUnit.
- Page de configuration (Configuration > Plugins > OfficeLinks, droit
  « config » en mise à jour) : racines autorisées, lettres de lecteur,
  alias d'hôtes, option des liens `file://`. Rien n'est enregistré tant
  qu'une ligne est invalide.
