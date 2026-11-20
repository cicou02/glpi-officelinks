# Compatibilité GLPI 11.0.x / 12.0.x

Références vérifiées dans les sources clonées (lecture seule, non versionnées) :

- `.glpi-src/11.0.8` — tag `11.0.8` (production)
- `.glpi-src/12.0.1` — tag `12.0.1`

Les chemins sont relatifs à la racine de chaque arborescence. « = » signifie que
le code concerné est identique dans les deux versions (vérifié par `diff`).

Dernière vérification : 2026-11-05.

## Différences relevées

| # | Sujet | GLPI 11.0.8 | GLPI 12.0.1 | Conséquence pour le plugin |
|---|---|---|---|---|
| D1 | Hook `csrf_compliant` | `Hooks::CSRF_COMPLIANT` existe mais `@deprecated 11.0.0` (`src/Glpi/Plugin/Hooks.php:44-47`), plus lu nulle part sauf `HookManager::enableCSRF()` déprécié (`src/Glpi/Plugin/HookManager.php:61`) | constante et méthode **supprimées** | Ne jamais déclarer `csrf_compliant` ni appeler `enableCSRF()` (fatal en 12). |
| D2 | `Html::closeForm()` | ajoute `_glpi_csrf_token` (`src/Html.php:4403-4413`) | ne ferme que `</form>` (`src/Html.php:3987-3995`) | Conforme à CLAUDE.md : fermer les formulaires du plugin par `Html::closeForm()`, n'écrire aucun jeton. |
| D3 | Formulaire natif d'un item | `buttons.html.twig:196` ajoute `<input name="_glpi_csrf_token">` | ligne supprimée | Le champ ajouté via `post_item_form` est posté par le formulaire natif : rien à faire. |
| D4 | Espace de noms Twig `@officelinks` | toujours enregistré, `src/Glpi/Application/View/TemplateRenderer.php:82` (`addPath(Plugin::getPhpDir($key.'/templates'), $key)`) | enregistré **seulement si** `templates/` existe, `src/Glpi/Kernel/Listener/PostBootListener/InitializeTwigEnvironment.php:126-131` | Le dossier `templates/` devra exister dès qu'un template est utilisé (étape 3/4). |
| D5 | `DBmysql::doQuery()` | `doQuery($query)` (`src/DBmysql.php:400`) | `doQuery(BasePart\|string $query): true\|mysqli_result` (`src/DBmysql.php:354`) + refus de tout DDL dans une transaction (`checkForDDLInsideTransaction`, `src/DBmysql.php:2316`) | Appeler avec une chaîne, hors transaction. `Plugin::install()` n'ouvre pas de transaction (11 : `src/Plugin.php:1197-1250`, 12 : `src/Plugin.php:1196-1249`). |
| D6 | `Session::haveRight()` | `haveRight($module, $right)` (`src/Session.php:1475`) | `haveRight(string $module, int $right): bool` (`src/Session.php:1522`) | Toujours passer une chaîne et un entier (`UPDATE`, etc.). |
| D7 | `CommonDBTM::can()` | `can($ID, int $right, ?array &$input = null): bool` (`src/CommonDBTM.php:2954`) | `can($ID, int $right, ?array &$input = null, null &$reauth_needed = null): bool` (`src/CommonDBTM.php:3013`) | Appel compatible ; ne jamais surcharger (règle « pas de sous-classe »). |
| D8 | Langues | `$CFG_GLPI['languages'][$lang][1]` (`src/Plugin.php:553`, `fr_FR` → `fr_FR.mo`, `src/autoload/CFG_GLPI.php:75`) | objets `Language->mo_file` (`src/Plugin.php:551`, défaut `{code}.mo`, `src/Glpi/Locale/Language.php:69-78`) | Même nom de fichier : `locales/fr_FR.mo`. |
| D9 | Hook `show_in_timeline` | déprécié (`Hooks.php:731`) | supprimé | Non utilisé. |
| D10 | `inc/includes.php` | sans effet (contrôles de variables globales obsolètes uniquement) | `Toolbox::deprecated('The inc/includes.php file is obsolete.')` | Ne pas l'inclure dans `front/` : l'amorçage est assuré par le routeur. |
| D11 | `Plugin::getWebDir()` | `@deprecated 11.0` (`src/Plugin.php:3131`) | **supprimée** | Construire les URL avec `$CFG_GLPI['root_doc'] . '/plugins/officelinks/...'`. |
| D12 | Contrôle CSRF des POST (`CheckCsrfListener`) | jeton `_glpi_csrf_token` exigé dans le corps (`Session::checkCSRF()`, `src/Glpi/Kernel/Listener/ControllerListener/CheckCsrfListener.php`) | en-têtes `Sec-Fetch-Site` / `Origin` | Formulaire du plugin fermé par `Html::closeForm()` : jeton en 11, rien en 12. |

Aucune différence ne remet en cause une décision de CLAUDE.md.

## Cycle de vie du plugin

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `plugin_version_<key>()` | `src/Plugin.php:1942` | `src/Plugin.php:1938` | = |
| `requirements.glpi.min/max` | `src/Plugin.php:2349-2361` | `src/Plugin.php:2345-2357` | `min` : `>=` ; `max` : **`<` strict** sur la version normalisée sans suffixe (`VersionParser::getNormalizedVersion(GLPI_VERSION, false)`, 12 : `src/Plugin.php:2583-2586`). `max = 12.0.99` accepte 12.0.0 → 12.0.98. |
| `requirements.php.min` | `src/Plugin.php:2397-2406` | `src/Plugin.php:2393-2402` | = |
| `plugin_init_<key>()` | `src/Plugin.php:472` | `src/Plugin.php:470` | = |
| `setup.php` inclus par `include_once` | `src/Plugin.php:2063-2071` | `src/Plugin.php:2059-2067` | = |
| `hook.php` inclus par `include_once` (`Plugin::includeHook()`) | `src/Plugin.php` (`include_once($path)`) | idem | = |
| `plugin_<key>_check_prerequisites()` (facultatif) | `src/Plugin.php:1223`, `1328` | `src/Plugin.php:1222`, `1327` | = |
| `plugin_<key>_check_config()` (facultatif, appelée sans argument) | `src/Plugin.php:1251`, `1350` | `src/Plugin.php:1250`, `1349` | = |
| `plugin_<key>_install($params)` | `src/Plugin.php:1246-1249` | `src/Plugin.php:1245-1248` | = (reçoit un tableau) |
| `plugin_<key>_uninstall()` | `src/Plugin.php:1142` | `src/Plugin.php:1141` | = |

## Autoload PSR-4 et ressources web

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `NS_PLUG = 'GlpiPlugin\\'` | `src/autoload/constants.php:63` | `src/autoload/constants.php:64` | = |
| Autoload `src/` | `src/Plugin.php:406` | `src/Plugin.php:404` | `addPsr4(NS_PLUG . ucfirst($plugin_key) . '\\', $dir . '/src/')` → **`GlpiPlugin\Officelinks\`** sur `src/` (confirmé). |
| Routage `/plugins/<key>/…` → `public/` | `src/Glpi/Http/RequestRouterTrait.php:85-96` | idem | = (fichier identique). `front/`, `ajax/`, `report/` restent servis depuis la racine du plugin ; tout le reste depuis `public/`. |
| URL avec `/public` dépréciée | `src/Glpi/Http/RequestRouterTrait.php:153-161` | idem | = : appeler `/plugins/officelinks/js/...`. |
| `Hooks::ADD_JAVASCRIPT` → `Html::script("/plugins/{$plugin}/{$file}")` | `src/Html.php:6147-6160` | `src/Html.php:5670-5683` | = (bloc identique). |
| `Hooks::ADD_CSS` → `"/plugins/{$plugin}/{$file}"` | `src/Glpi/Application/View/Extension/PluginExtension.php:141-170` | `…/PluginExtension.php:119-148` | = |
| `Hooks::CONFIG_PAGE` → `/plugins/<key>/<valeur>` | `src/Plugin.php:2847-2850` | `src/Plugin.php:2843-2846` | = |

## Hooks d'item

| Hook | Constante 11.0.8 | Constante 12.0.1 |
|---|---|---|
| `post_item_form` | `src/Glpi/Plugin/Hooks.php:655` | `src/Glpi/Plugin/Hooks.php:674` |
| `pre_item_add` | `Hooks.php:457` | `Hooks.php:452` |
| `item_add` | `Hooks.php:475` | `Hooks.php:470` |
| `pre_item_update` | `Hooks.php:485` | `Hooks.php:480` |
| `item_update` | `Hooks.php:495` | `Hooks.php:500` |
| `item_purge` | `Hooks.php:529` | `Hooks.php:534` |
| `add_javascript` / `add_css` | `Hooks.php:60` / `54` | `Hooks.php:55` / `49` |
| `config_page` | `Hooks.php:148` | `Hooks.php:143` |

Appels :

- `Plugin::doHook($name, $param = null)` : `src/Plugin.php:1779` (11 et 12, même logique hors D9). Si `$param` est un objet, le callback est cherché dans `$PLUGIN_HOOKS[$name][$key][get_class($param)]` et reçoit l'objet → déclarer `['Document' => callable]`.
- `pre_item_add` / `item_add` / `pre_item_update` / `item_update` / `item_purge` : `src/CommonDBTM.php:1338 / 1434 / 1678 / 1773 / 2189` (11) ; `1348 / 1444 / 1688 / 1792 / 2207` (12) — `Plugin::doHook(Hooks::…, $this)`.
- `post_item_form` : `templates/components/form/buttons.html.twig:41` (11 et 12) — `call_plugin_hook(POST_ITEM_FORM, {'item': item, 'options': params})`, donc **appel standard** (pas indexé par type) : le callback reçoit un tableau `['item' => …, 'options' => …]` et doit tester `instanceof Document` lui-même. `callPluginHook()` : `PluginExtension.php:72` (11), `:70` (12).
- Le hook est émis **à l'intérieur** du `<form name="asset_form">` : ouverture `templates/components/form/header.html.twig:61`, fermeture `buttons.html.twig:198` (11) / `:197` (12).
- La fiche Document utilise ce gabarit : `Document::showForm()` (11 : `src/Document.php:366`, 12 : `:386`) → `pages/management/document.html.twig` → `generic_show_form.html.twig` → `buttons.html.twig` (11 : `generic_show_form.html.twig:565`, 12 : `:568`).

## Champ natif `link` (rappel, hors périmètre)

`Document::prepareInputForAdd()` / `prepareInputForUpdate()` (11 : `src/Document.php:223`, `:335` ; 12 : `:239`, `:355`) refusent `link` si `!Toolbox::isValidWebUrl()` (11 : `src/Document.php:292`, `:354` ; 12 : `:308`, `:374`). `Toolbox::isValidWebUrl($url): bool` : 11 `src/Toolbox.php:3294`, 12 `src/Toolbox.php:2981`.

## Configuration (`Config`)

| API | 11.0.8 `src/Config.php` | 12.0.1 `src/Config.php` | Remarque |
|---|---|---|---|
| `getConfigurationValues($context, array $names = [])` | 1344 | 1326 | = ; renvoie `name => value` (chaînes). |
| `getConfigurationValue(string $context, string $name)` | 1378 | 1360 | = |
| `setConfigurationValues($context, array $values = [])` | 1504 | 1490 | = (upsert clé par clé ; `new self` → `new static`, sans effet). Valeurs stockées en texte → listes encodées en JSON par le plugin. |
| `deleteConfigurationValues($context, array $values = [])` | 1560 | 1546 | = ; supprime **uniquement** les noms passés (tableau vide = rien). |

## Base de données

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `DBConnection::getDefaultCharset(): string` | `src/DBConnection.php:891` | `src/DBConnection.php:927` | = |
| `DBConnection::getDefaultCollation(): string` | `:909` | `:945` | = |
| `DBConnection::getDefaultPrimaryKeySignOption(): string` | `:927` | `:963` | = |
| `$DB->request($criteria)` | `src/DBmysql.php:1085` | `src/DBmysql.php:1063` | = |
| `$DB->tableExists($tablename, $usecache = true)` | `:1180` | `:1158` | = |
| `$DB->insert($table, $params)` | `:1375` | `:1358` | = |
| `$DB->updateOrInsert($table, $params, $where, $onlyone = true)` | `:1535` | `:1501` | = |
| `$DB->delete($table, $where, array $joins = [])` | `:1603` | `:1573` | = |
| `$DB->dropTable(string $name, bool $exists = false)` | `:1679` | `:1590` | = ; `$exists = true` → `DROP TABLE IF EXISTS` (`buildDrop`, 11 `:1720`, 12 `:1631`). |
| `$DB->doQuery()` | `:400` | `:354` | voir D5. |
| Contrôles DDL (`checkForDeprecatedTableOptions`) | `:2196` | `:2243` | = : avertit si `utf8` au lieu de `utf8mb4`, `MyISAM`, `DATETIME`, clés `id`/`*_id` signées → utiliser les helpers `DBConnection`, `InnoDB`, `TIMESTAMP`. |

## Interface, droits, traductions

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `Html::closeForm($display = true)` | `src/Html.php:4403` | `src/Html.php:3987` | voir D2. |
| `Session::addMessageAfterRedirect($msg, $check_once = false, $message_type = INFO, $reset = false)` | `src/Session.php:1589` | `src/Session.php:1636` | = |
| `Session::checkRight($module, $right)` | `src/Session.php:1293` | `src/Session.php:1340` | = |
| `Session::haveRight()` | `src/Session.php:1475` | `src/Session.php:1522` | voir D6. |
| `TemplateRenderer::getInstance()` / `render()` / `display()` | `src/Glpi/Application/View/TemplateRenderer.php:137 / 166 / 184` | `…/TemplateRenderer.php:59 / 91 / 109` | = (signatures) |
| Espace de noms Twig `@<key>` | voir D4 | voir D4 | |
| Macros `components/form/fields_macros.html.twig` | `textField` 96, `checkboxField` 136, `textareaField` 220, `hiddenField` 442, `htmlField` 788, `field` 806, `nullField` 863, `smallTitle(label, icon, helper, id)` 57 | `textField` 100, `checkboxField` 140, `textareaField` 224, `hiddenField` 446, `htmlField` 792, `field` 810, `nullField` 867, `smallTitle(…, level = 4)` 58 | Mêmes signatures (12 ajoute seulement `level` optionnel à `smallTitle`). |
| `__($str, $domain = 'glpi')`, `_n()`, `_x()` | `src/autoload/i18n.php:57 / 127 / 174` | idem | = |
| `Plugin::loadLang()` → `locales/<lang>.mo`, repli `en_GB.mo` | `src/Plugin.php:553` | `src/Plugin.php:551` | voir D8. |

## Recherche

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `plugin_<key>_getAddSearchOptionsNew($itemtype)` | `src/Plugin.php:2175-2185` | `src/Plugin.php:2171-2181` | = (bloc identique) ; chaque option doit avoir un `id`, doublon → `E_USER_WARNING`. |
| `joinparams.jointype = 'child'` | `src/Glpi/Search/Provider/SQLProvider.php:2361` | `…/SQLProvider.php:2448` | Existe dans les deux ; détail à revérifier à l'étape 6. |

## Extensions PHP utilisées par `src/PathNormalizer.php`

| Extension | 11.0.8 | 12.0.1 | Usage |
|---|---|---|---|
| `ext-intl` (`Normalizer`) | exigée, `composer.json:24` | exigée, `composer.json:24` | Forme NFKC pour détecter `..` et séparateurs déguisés (pleine chasse). |
| `ext-mbstring` | exigée, `composer.json:27` | exigée, `composer.json:27` | Contrôle UTF-8, longueur en caractères, casse (`MB_CASE_UPPER_SIMPLE`). |

La classe n'utilise aucune API du cœur de GLPI.

## Page de configuration (étape 3)

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| Script `front/` d'un plugin | `src/Glpi/Http/RequestRouterTrait.php:87-92` | idem | = ; servi depuis la racine du plugin (et non `public/`). |
| Plugin non chargé → 404 | `src/Glpi/Kernel/Listener/RequestListener/LegacyRouterListener.php:96-103` | idem | = ; « chargé » n'implique pas « activé » → la page teste `Plugin::isPluginActive()` (11 `src/Plugin.php:2714`, 12 `:2710`). |
| `Glpi\Exception\Http\NotFoundHttpException` | `src/Glpi/Exception/Http/NotFoundHttpException.php` | idem | = |
| `Session::checkRight($module, $right)` → `AccessDeniedHttpException` | `src/Session.php:1293` | `src/Session.php:1340` | = |
| Constantes `UPDATE` / `INFO` | `src/autoload/constants.php:67` | `src/autoload/constants.php:68` | = |
| `Html::header($title, $url = '', $sector = 'none', $item = 'none', $option = '', bool $add_id = true)` | `src/Html.php:1615` | `src/Html.php:1201` | = ; appelé comme `front/plugin.php:48` (`'config', 'plugin'`). |
| `Html::footer()` | `src/Html.php:1685` | `src/Html.php:1271` | = |
| `Html::redirect($dest, $http_response_code = 302): never` | `src/Html.php:457` | `src/Html.php:378` | = |
| `$CFG_GLPI['root_doc']` | `src/Config.php:1400` | `src/Config.php:1383` | = |
| `htmlescape(mixed $str): string` | `src/autoload/misc-functions.php:100` | `src/autoload/misc-functions.php:103` | = |
| `__s($str, $domain = 'glpi')` (traduit et échappe) | `src/autoload/i18n.php:89` | idem | = |
| Messages après redirection affichés **sans échappement** | `templates/components/messages_after_redirect_toasts.html.twig:58` (`message\|raw`) | idem | = : toujours échapper (`__s()`, `htmlescape()`) le texte passé à `addMessageAfterRedirect()`. |
| `$_POST` brut (plus d'échappement automatique) | `Sanitizer` limité au décodage des anciennes données | idem | Valider le type (`is_string`) des valeurs postées. |
| `Config::prepareInputForUpdate()` : entrée avec `context` renvoyée telle quelle | `src/Config.php:173-183` | `src/Config.php:170-180` | = |
| Autoloader actif pendant `plugin_<key>_install()` | `Plugin::install()` → `self::load($dir, true)` → `registerPluginAutoloader()` | idem | = : `hook.php` peut utiliser les classes de `src/`. |
| Twig `__()`, `_x()` | `src/Glpi/Application/View/Extension/I18nExtension.php:55-57` | `…/I18nExtension.php:56-58` | = |
| Macro `textareaField` (et `textarea` de `basic_inputs_macros`) | `fields_macros.html.twig:220` | `:224` | Identiques (`diff`) ; valeur échappée (`{{ value }}` sans éditeur riche). |
| Macro `checkboxField` (champ caché `0` + case `1`) | `fields_macros.html.twig:136`, `basic_inputs_macros.html.twig:513` | idem | Identiques (`diff`). |
| Option `add_field_html` (affichée avec `\|raw`) | macro `field` / `horizontalField` | idem | = : construite dans le template par `{% set %}…{% endset %}`, donc échappée. |
| Pas d'option `helper` sur les champs | — | — | Aide affichée via `add_field_html`. |

## Fiche Document (étape 4)

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `CommonDBTM::add(array $input, $options = [], $history = true)` | `src/CommonDBTM.php:1288` | `src/CommonDBTM.php:1298` | Identique (`diff`, hormis `isSlave` → `isReplica`). `pre_item_add` est appelé **avant** `prepareInputForAdd()` ; `$this->input = false` arrête l'ajout ; `item_add` est toujours appelé après `addToDB()`. Les clés commençant par `_` ne sont jamais écrites en base. |
| `CommonDBTM::update(array $input, $history = true, $options = [])` | `src/CommonDBTM.php:1642` | `src/CommonDBTM.php:1652` | `pre_item_update` avant `prepareInputForUpdate()` ; **`item_update` n'est appelé que si une colonne change** (`if (count($this->updates))`). 12 ajoute `post_prepareupdate`. |
| `CommonDBTM::delete()` → `item_purge` | `src/CommonDBTM.php:2118` (hook `:2189`) | `src/CommonDBTM.php:2136` (hook `:2207`) | = |
| `CommonDBTM::$history_blacklist` | `public $history_blacklist = []` (`:112`) | `public array $history_blacklist = []` (`:116`) | Propriété publique, vide pour Document : un `date_mod` passé dans `input` serait historisé, d'où son ajout temporaire à la liste. |
| `Log::constructHistory()` | ignore `getNonLoggedFields()` (vide par défaut) | idem | = |
| `Document::prepareInputForAdd()` / `prepareInputForUpdate()` → `filterFields()` | `src/Document.php:223`, `:335`, `:1818` | `:239`, `:355`, `:1915` | = ; ne retire que `filename` (si présent dans `$_REQUEST`), `filepath`, `sha1sum` : `_officelinks_path` est conservé. Un document peut être créé sans fichier. |
| `Document::showForm()` → `params.canedit = $this->canUpdateItem()` | `src/Document.php:366-378` | `src/Document.php:386-398` | = |
| `post_item_form` dans la timeline des tickets | objet `Document_Item` (`src/CommonITILObject.php:7492`) | idem (`:7453`) | `Document_Item` n'hérite pas de `Document` : le test `instanceof Document` limite le champ à la fiche Document. |
| `CommonDBTM::canUpdateItem(): bool` / `CommonGLPI::isNewItem()` | `src/CommonDBTM.php:2487` / `src/CommonGLPI.php:841` | `:2510` / `:909` | = ; droits de classe via `Document::canUpdate()` / `canCreate()`. |
| Macro `textField` / `input` (`readonly`, `maxlength`, `additional_attributes`) | `fields_macros.html.twig:96` / `basic_inputs_macros.html.twig` | `:100` / idem | `textField` identique ; `input` ne diffère que par les boutons des mots de passe (non utilisés). |
| `$DB->insert()`, `updateOrInsert()`, `delete()`, `request()` | voir « Base de données » | idem | = |
| `Plugin::uninstall()` charge le plugin (`self::load($dir, true)`) | `src/Plugin.php:1142` | `src/Plugin.php:1141` | = : `hook.php` peut utiliser `DocumentPath::TABLE` à la désinstallation. |

## Liens et ressources statiques (étape 5)

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `Hooks::ADD_JAVASCRIPT` → `Html::script("/plugins/officelinks/js/officelinks.js")` | `src/Html.php:6147-6160` | `src/Html.php:5670-5683` | = ; servi depuis `public/js/`. Chargé sur toutes les pages : le script n'agit que sur les éléments `data-officelinks-*`. |
| `Hooks::ADD_CSS` → `/plugins/officelinks/css/officelinks.css` | `PluginExtension.php:141-170` | `PluginExtension.php:119-148` | = ; servi depuis `public/css/`. |
| Onglets de la fiche chargés en AJAX | — | — | Le JavaScript délègue ses écouteurs à `document` (pas d'initialisation au chargement). |
| En-tête `Content-Security-Policy` | absent de `src/` | absent de `src/` | Pas de contrainte sur les scripts ; le plugin n'utilise de toute façon aucun script en ligne. |
| Icônes Tabler (`@tabler/icons-webfont`) | `^3.34.1` (`package.json:25`) | `^3.35.0` (`package.json:27`) | `ti-file-type-doc`, `ti-file-type-xls`, `ti-file-type-ppt`, `ti-sitemap`, `ti-eye`, `ti-copy`, `ti-folder-open` présentes dans les deux. |
| Filtres Twig `json_encode`, `format` | Twig `^3.27` (`composer.json:106`) | Twig `^3.27` (`composer.json:111`) | Filtres natifs de Twig. |

## Cache des templates (constaté au test de l'étape 5)

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `Environment::shouldExpectResourcesToChange()` | `src/Glpi/Application/Environment.php:207` | `src/Glpi/Application/Environment.php:197` | `false` en production/staging si GLPI n'est pas une copie git : Twig `auto_reload` désactivé (11 `src/Glpi/Application/View/TemplateRenderer.php:88`, 12 `src/Glpi/Kernel/Listener/PostBootListener/InitializeTwigEnvironment.php:84`). Un template de plugin modifié n'est pas recompilé → `php bin/console cache:clear` après chaque mise à jour. |
| `Plugin::getPluginFilesVersion()` (paramètre `?v=` des JS/CSS) | `src/Plugin.php:1965` | `src/Plugin.php:1961` | = ; renvoie la version du plugin : l'augmenter à chaque publication pour invalider le cache navigateur. |

## Office URI Schemes (référence Microsoft)

Source : <https://learn.microsoft.com/en-us/office/client-developer/office-uri-schemes>.

- Section 1.3 : « Currently only URIs based on the http or https scheme are supported. » Avec `file://`, `ofe` fonctionne sur le parc mais **`ofv` (lecture seule) est ignoré** : bouton « Lecture seule » retiré (décision du 2026-11-20).
- Section 1.7 : « the maximum path length is 256 characters for all apps except Excel, where the limit is 216. » Un chemin plus long est accepté par le plugin (limite 1024) mais peut ne pas s'ouvrir dans Office.

## Recherche et historique (étape 6)

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| Jointure `child` (`joinparams.jointype`) | `src/Glpi/Search/Provider/SQLProvider.php:3020-3036` | `…/SQLProvider.php:3122-3160` | `LEFT JOIN … ON glpi_documents.id = <table>.documents_id` (`getForeignKeyFieldForTable()`). La variante en sous-requête `COUNT(*)` de 12 n'est utilisée que si l'option déclare `use_join_subquery` (non utilisé). |
| Identifiants d'options du cœur | littéraux ≤ 4000 ; `State` : 4000 + n (`src/State.php:899`) ; champs personnalisés : 45000 + n (`src/Glpi/Asset/CustomFieldDefinition.php:339`) | idem (`:898`, `:341`) | Aucun registre officiel pour les plugins (documentation développeur : « we must avoid conflict »). Plugin : **21500**, constante `DocumentPath::SEARCH_OPTION_ID`, à ne jamais changer. |
| `Log::history($items_id, $itemtype, $changes, $itemtype_link = '', $linked_action = 0)` | `src/Log.php:231` | `src/Log.php:232` | = (hormis `affectedRows()` → `getAffectedRows()`) ; `$changes = [id_search_option, ancien, nouveau]` ; valeurs tronquées aux 255 **premiers** caractères (`mb_substr($v, 0, 255)`). Le plugin envoie déjà « … » + les 254 derniers (`DocumentPath::forHistory()`), pour garder dossier et nom du fichier. |
| Affichage de l'historique (`Log::getHistoryData()`, `linked_action = 0`) | `src/Log.php:400` | `src/Log.php:401` | = : libellé = `name` de l'option de recherche, texte « Changer <ancien> en <nouveau> » échappé. |
| `CommonDBTM::$dohistory` | `public $dohistory` (`:104`), `true` pour Document (`src/Document.php:73`) | `public bool $dohistory` (`:108`), `true` (`src/Document.php:88`) | Historisation seulement si `dohistory` et sans `_no_history`, comme `CommonDBTM::update()`. |

### Correctif : clé `itemtype` de l'option de recherche (constaté au test de l'étape 6)

Sans `'itemtype'`, l'affichage de la colonne lève `TypeError: DbUtils::getClassForItemtype(): Argument #1 ($itemtype) must be of type string, null given`.

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `SQLProvider::giveItem()` : `$so['itemtype'] ?? getItemTypeForTable($table)` puis `getItemForItemtype()` | `src/Glpi/Search/Provider/SQLProvider.php:6933-6934` | `…/SQLProvider.php:8088-8089` | `getItemTypeForTable('glpi_plugin_officelinks_documents')` cherche `GlpiPlugin\Officelinks\Document` → `null`. D'où `'itemtype' => Document::class` dans l'option. |
| Autres lectures de `$so['itemtype']` | `SQLProvider.php:212` (`Session::haveTranslations()`), `:5585`, `:6601` ; `SearchOption.php:622` (`CommonTreeDropdown` ?) | `SQLProvider.php:233`, `:6640`, `:7781` ; `SearchOption.php:667` | Sans effet pour un champ texte de Document (cas propres à d'autres tables ou types). |
| `Document::getSpecificValueToDisplay()` | hérité de `CommonDBTM` (`src/CommonDBTM.php:4722`) : `''` | `src/Document.php:1086` (seul `filesize`) puis `CommonDBTM` : `''` | Valeur brute affichée, échappée par `giveItem()` (`htmlescape`). |

## Traductions (étape 7)

| API | 11.0.8 | 12.0.1 | Remarque |
|---|---|---|---|
| `Plugin::loadLang()` : ordre de recherche du `.mo` | `src/Plugin.php:553` (`$CFG_GLPI['languages'][$lang][1]`) | `src/Plugin.php:551` (`LanguageRegistry::tryGet()->mo_file`) | Même ordre : langue de l'utilisateur, puis **langue par défaut de l'instance**, puis `en_GB.mo`. D'où des `.mo` anglais sans traduction (`en_GB`, `en_US`) : sinon un anglophone recevrait la langue par défaut (ex. français). |
| Noms des fichiers | `fr_FR.mo`, `fr_CA.mo`, `fr_BE.mo`, `en_GB.mo`, `en_US.mo` (`src/autoload/CFG_GLPI.php:65-77`) | idem (`src/Glpi/Locale/LanguageRegistry.php:243-255`, défaut `{code}.mo`) | = |
| Chargeur gettext | `laminas/laminas-i18n` `^2.30` (`composer.json:45`) | `^2.33` (`composer.json:44`) | `.mo` produits par `tools/locales.php` relus avec ce chargeur (vérification manuelle) : français pour fr_*, texte source pour en_*. |
| `__()`, `_x()` (contexte = `ctx . "\004" . msgid`) | `src/autoload/i18n.php:57`, `:174` | idem | = |

## Stratégies Edge (documentation Microsoft, consultée le 2026-11-20)

- `AutoLaunchProtocolsFromOrigins` (Edge ≥ 85) : `HKLM\SOFTWARE\Policies\Microsoft\Edge`, `REG_SZ`, JSON `[{"protocol": "...", "allowed_origins": [...]}]` ; protocole en minuscules sans séparateur ; origines sans chemin ni requête. <https://learn.microsoft.com/en-us/deployedge/microsoft-edge-browser-policies/autolaunchprotocolsfromorigins>
- `IntranetFileLinksEnabled` (Edge ≥ 95) : `REG_DWORD` ; liens `file://` vers des fichiers de la zone Intranet, depuis des pages **HTTPS** de la zone Intranet ; lien vers un fichier → Explorateur sur le dossier parent, fichier sélectionné. <https://learn.microsoft.com/en-us/deployedge/microsoft-edge-browser-policies/intranetfilelinksenabled>
