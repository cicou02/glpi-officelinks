# Changelog

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [Non publié]

## [1.0.0] - 2026-11-20

Première version publiée.

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
  Visio » (`ofe`), « Copier le chemin » et, si
  l'option est activée, « Afficher dans l'Explorateur » (dossier parent en
  `file://`). Le chemin enregistré est revalidé à l'affichage : hors racine,
  aucun lien d'ouverture n'est généré.
- JavaScript : aperçu du chemin normalisé pendant la saisie, préremplissage
  du nom du document, copie avec repli pour GLPI servi en http. Tests Node
  comparant l'aperçu aux résultats de PathNormalizer.
- Option de recherche « Chemin réseau » (identifiant 21500) dans
  Gestion > Documents : colonne affichable et critère de recherche.
- Historique du document : une ligne « Chemin réseau : ancien → nouveau » à
  chaque modification ou suppression du chemin. Au-delà de 255 caractères
  (limite de `glpi_logs`), la fin du chemin est conservée, précédée de « … ».
- Suppression du bouton « Lecture seule » (`ofv` ignoré par Office pour les
  chemins `file://`).
- Traduction française (fr_FR, fr_CA, fr_BE) ; fichiers anglais (en_GB,
  en_US) pour qu'un utilisateur anglophone ne reçoive pas la langue par
  défaut de l'instance. Outil `tools/locales.php` (extraction, mise à jour
  des .po, compilation des .mo) et test de cohérence des traductions.
- « Afficher dans l'Explorateur » pointe vers le fichier : avec
  `IntranetFileLinksEnabled`, l'Explorateur s'ouvre sur le dossier, fichier
  sélectionné.
- README complet : installation, mise à jour, configuration, utilisation,
  déploiement GPO (zone Intranet local, `AutoLaunchProtocolsFromOrigins`,
  `IntranetFileLinksEnabled`), limites connues, dépannage.
- README en anglais (`README.md`) et en français (`README.fr.md`).
- Mise à jour : le bouton « Mettre à jour » de GLPI vide lui-même le cache
  des templates (plus besoin de `cache:clear` quand la version change). La
  page de configuration affiche la version installée et signale une version
  plus récente publiée sur GitHub.
- Actions GitHub : tests (PHP 8.2 à 8.4, Node) et construction de l'archive
  de release `officelinks-X.Y.Z.zip` (fichiers du plugin uniquement).

### Sécurité (audit avant v1.0.0)

Audit avec la méthode Cloudflare *security-audit-skill* et zizmor : aucune
vulnérabilité confirmée. Renforcements appliqués :

- Chemins : refus des caractères invisibles de mise en forme (catégorie
  Unicode Cf, dont U+200B, U+FEFF, U+061C, U+00AD) et des séparateurs de
  ligne/paragraphe, ainsi que de `¥ ₩ ∕ ∖` (convertis en séparateurs par
  certaines pages de code Windows). Même contrôle dans l'aperçu JavaScript.
- Le chemin n'est enregistré qu'une fois la mise à jour du Document acceptée
  par GLPI (y compris pour deux enregistrements dans la même seconde).
- Nouveau document : le champ et la configuration utilisée par l'aperçu ne
  sont proposés qu'avec le droit « Documents » en création (plus avec le
  seul droit d'ajout de suivi).
- Installation et mise à jour : suppression des chemins dont le document
  n'existe plus.
- Configuration : un alias dont la source contient « = » est relu
  correctement ; la page signale les entrées enregistrées invalides
  (ignorées, supprimées au prochain enregistrement).
- JavaScript : écouteurs limités au bloc du plugin ; message de mise à jour
  insensible aux motifs `$&` d'un tag GitHub.
- Release : construction et tests sans droit d'écriture, publication dans un
  job séparé (tag uniquement) qui vérifie le sha256 de l'archive ; version
  validée et passée par variable d'environnement ; actions épinglées par SHA
  avec Dependabot ; `persist-credentials: false` ; exécutions concurrentes
  sérialisées.
