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
- Champ « Chemin réseau » sur la fiche Document (création et modification) :
  normalisation et validation côté serveur, enregistrement du chemin UNC
  canonique, suppression si le champ est vidé ou si le document est purgé.
- Boutons d'ouverture sous le champ : « Ouvrir dans Word/Excel/PowerPoint/
  Visio » (`ofe`), « Lecture seule » (`ofv`), « Copier le chemin » et, si
  l'option est activée, « Afficher dans l'Explorateur » (dossier parent en
  `file://`). Le chemin enregistré est revalidé à l'affichage : hors racine,
  aucun lien d'ouverture n'est généré.
- JavaScript : aperçu du chemin normalisé pendant la saisie, préremplissage
  du nom du document, copie avec repli pour GLPI servi en http. Tests Node
  comparant l'aperçu aux résultats de PathNormalizer.
