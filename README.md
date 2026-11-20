# OfficeLinks — GLPI plugin

🇫🇷 [Version française](README.fr.md)

OfficeLinks adds a "Network path" field to GLPI **Document** items. The field
holds the UNC path of a file on the company DFS share. When a document is
displayed, the plugin generates native opening links:

- **Office files** (Word, Excel, PowerPoint, Visio): an "Open in Word" button
  (`ms-word:` protocol and equivalents) that opens the file **for editing,
  directly on the DFS share**;
- **all files**: a "Copy path" button;
- **optional**: a "Show in File Explorer" button.

No file is stored in GLPI: the plugin indexes documents, speeds up searches and
centralises access points.

> **Project status.** OfficeLinks was built for the internal needs of one
> organisation (Windows workstations, Microsoft Edge, Microsoft 365, a DFS
> namespace) and is shared in the hope that it is useful to others. It has
> been tested in that environment only, on GLPI 11.0.8 and 12.0.2. Another
> setup (other browser, Office version, file server, reverse proxy or GLPI
> configuration) may behave differently or reveal bugs. It is provided as is,
> without warranty (see [License](#license)). Bug reports and pull requests
> are welcome in the [issues](https://github.com/cicou02/glpi-officelinks/issues);
> for a security problem, see [SECURITY.md](SECURITY.md).

Contents: [Requirements](#requirements) · [Installation](#installation) ·
[Upgrade](#upgrade) · [Configuration](#configuration) · [Usage](#usage) ·
[Workstation deployment](#workstation-deployment-group-policy) ·
[Known limitations](#known-limitations) · [Troubleshooting](#troubleshooting) ·
[Development](#development)

## Requirements

- GLPI 11.0.x or 12.0.x (same code for both versions)
- PHP 8.2 or later, with the `intl` and `mbstring` extensions (already required by GLPI)
- Workstations: Windows, Microsoft Edge and Microsoft 365 (or Office 2013 and later)

## Installation

1. Put the plugin in the GLPI `plugins/` directory, **in a folder named
   `officelinks`** (not `glpi-officelinks`):

   - **from a release (recommended)**: download `officelinks-X.Y.Z.zip` from
     the [Releases](https://github.com/cicou02/glpi-officelinks/releases) page
     and extract it into `plugins/`; the archive already contains an
     `officelinks/` folder with the required files only;
   - **from Git**:

     ```bash
     git clone https://github.com/cicou02/glpi-officelinks.git /var/www/glpi/plugins/officelinks
     ```

     The `vendor/` folder is not needed in production: Composer is only used
     for the tests.

2. Give the folder to the web server user (for example
   `chown -R www-data: /var/www/glpi/plugins/officelinks`).

3. In GLPI: *Setup > Plugins*, **Install** then **Enable** "OfficeLinks". From
   the command line, in the GLPI root directory:

   ```bash
   php bin/console plugin:install --username=glpi officelinks
   ```

   ```bash
   php bin/console plugin:activate officelinks
   ```

4. Configure at least one allowed root (see [Configuration](#configuration)):
   as long as no root is configured, no path can be saved.

Installation creates the `glpi_plugin_officelinks_documents` table and the
configuration (`plugin:officelinks` context, empty by default). Uninstalling
removes both.

## Upgrade

1. Replace the content of `plugins/officelinks/` with the new version (release
   archive or `git pull`). The configuration and the saved paths are kept:
   they live in the database.
2. *Setup > Plugins*: GLPI detects the new version number, disables the plugin
   and shows an **Upgrade** button. Click it, then **Enable**.
3. Reload the page with Ctrl+F5.

The upgrade clears the GLPI template cache by itself: no `cache:clear` needed.
The plugin configuration page tells you when a newer version is published on
GitHub (checked by the administrator's browser, cached for 12 hours).

**Files changed without a version change** (development, `git pull` of a
branch): GLPI does not offer an upgrade and, in production, does not recompile
templates; clear the cache manually:

```bash
php bin/console cache:clear
```

## Configuration

*Setup > Plugins > OfficeLinks* ("Configure" icon). The page requires the
"Setup" right with update permission. One entry per line:

| Field | Format | Example |
|---|---|---|
| Allowed roots | `\\host\share[\folder]` | `\\internal.example.com\DFS\IT` |
| Drive letter mappings | `X: = \\host\share[\folder]` | `I: = \\internal.example.com\DFS\IT` |
| Host aliases | `\\server\share = \\host\namespace[\folder]` | `\\srv-files01\IT$ = \\internal.example.com\DFS\IT` |
| Show the "Show in File Explorer" button | checkbox | disabled by default |

- **Allowed roots**: only files located under one of these roots can be
  linked. Matching is case-insensitive and done folder by folder
  (`\\host\DFS-test` is not under `\\host\DFS`). The root is rewritten with the
  case used in the configuration.
- **Drive letter mappings**: a path entered as `I:\Info\a.docx` is translated
  into a UNC path.
- **Host aliases**: a path entered with the target server
  (`\\srv-files01\...`) is rewritten to the DFS namespace.
- Drive and alias targets must be under an allowed root. If a line is
  invalid, nothing is saved and errors are listed with their line number.

### Security

The list of allowed roots is the actual protection of the plugin. An `ms-word:`
link to an arbitrary server can make the clicking user's computer send its
NetNTLM hash to that server. GLPI therefore validates every path on save, and
validates the saved path again on every display: if a root is removed from the
configuration, the related documents no longer show opening links (copying
the path remains possible).

## Usage

On a document (*Management > Documents*), the **Network path** field accepts:

- a UNC path: `\\internal.example.com\DFS\IT\Info\Procedure.docx`;
- the output of File Explorer's "Copy as path" (Ctrl+Shift+C), quotes
  included;
- a configured drive letter: `I:\Info\Procedure.docx`;
- a `file://internal.example.com/DFS/...` URI.

While typing, a preview line shows the path that will be saved (or why it
will be rejected). If the document name is empty, it is filled with the file
name without extension. Only the canonical UNC path is saved; clearing the
field removes the link.

The path is also:

- **searchable**: "Network path" column and criterion in
  *Management > Documents*;
- **tracked**: every change adds a line to the document's *Historical* tab.

Editing follows the document update right; a read-only user sees the path and
the buttons.

The interface is available in English and French.

## Workstation deployment (Group Policy)

Three settings, to deploy with Group Policy. The Edge policies require the
Microsoft Edge administrative templates (`MSEdge.admx`). In the examples,
replace `glpi.example.com` and `internal.example.com` with your own names.

### 1. Local intranet zone for the DFS share (recommended)

Puts the DFS share (and GLPI) in the *Local intranet* zone: Office then opens
DFS files without treating them as coming from the Internet, and it is a
prerequisite of the "Show in File Explorer" button.

*Computer Configuration > Policies > Administrative Templates > Windows
Components > Internet Explorer > Internet Control Panel > Security Page*:

- **Site to Zone Assignment List**, zone `1` (Local intranet):

  | Value | Zone |
  |---|---|
  | `file://internal.example.com` | `1` |
  | `https://glpi.example.com` | `1` |

- or, for the share only, **Intranet Sites: Include all network paths
  (UNCs)**: Enabled.

The Edge policies below rely on this same intranet zone definition.

### 2. Open Office without a prompt: `AutoLaunchProtocolsFromOrigins` (recommended)

Without this setting, Edge asks on every click whether to open Word.

*Computer Configuration > Policies > Administrative Templates > Microsoft
Edge*: **Define a list of protocols that can launch an external application
from listed origins without prompting the user**. Edge 85 and later.

Value (JSON, on a single line in the policy):

```json
[
  {"protocol": "ms-word",       "allowed_origins": ["https://glpi.example.com"]},
  {"protocol": "ms-excel",      "allowed_origins": ["https://glpi.example.com"]},
  {"protocol": "ms-powerpoint", "allowed_origins": ["https://glpi.example.com"]},
  {"protocol": "ms-visio",      "allowed_origins": ["https://glpi.example.com"]}
]
```

- Protocol names are lower case, without `:`.
- The origin is the GLPI one (scheme, host name, and port if not standard),
  **without any path**: an origin containing `/glpi` is ignored.
- Registry equivalent: key `HKLM\SOFTWARE\Policies\Microsoft\Edge`, value
  `AutoLaunchProtocolsFromOrigins` (`REG_SZ`) containing the JSON.

### 3. "Show in File Explorer" button: `IntranetFileLinksEnabled` (optional)

Only needed if the option is enabled in the plugin configuration. The button
opens File Explorer on the file's folder, **with the file selected**.

*Computer Configuration > Policies > Administrative Templates > Microsoft Edge
> Content settings*: **Allow intranet zone file URL links from Microsoft Edge
to open in Windows File Explorer**: Enabled. Edge 95 and later.

Conditions set by Edge:

- GLPI must be served over **HTTPS** and be in the Local intranet zone;
- the DFS share must be in the Local intranet zone (setting 1).

Registry equivalent: key `HKLM\SOFTWARE\Policies\Microsoft\Edge`, value
`IntranetFileLinksEnabled` (`REG_DWORD`) set to `1`.

### Checking a workstation

1. `gpupdate /force`, then restart Edge.
2. Open `edge://policy`: `AutoLaunchProtocolsFromOrigins` (and, if used,
   `IntranetFileLinksEnabled`) are listed with status "OK".
3. In GLPI, click "Open in Word" on a document: Word opens without an Edge
   prompt.

## Known limitations

- **No read-only opening**: the Office `ofv` command is only defined for
  documents served over http(s); for a file on the DFS share, Office ignores
  it and opens the file for editing. The button is therefore not offered.
- **Path length**: Office does not open paths longer than 256 characters (216
  for Excel), although the plugin accepts up to 1024.
- **History**: GLPI limits each value to 255 characters; for a longer path,
  the end (folder, file name) is kept, preceded by "…".
- **No existence check**: the GLPI server does not access the DFS share; a
  moved or deleted file is not detected.
- **PDF, images, etc.**: no direct opening from the browser; use "Copy path"
  (or "Show in File Explorer").

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Nothing changed on screen after an update | Unchanged version (no "Upgrade" button): `php bin/console cache:clear`, then Ctrl+F5. |
| Edge asks every time whether to open Word | `AutoLaunchProtocolsFromOrigins` missing, or origin different from the actual GLPI address (scheme, port, path). Check `edge://policy`. |
| "Show in File Explorer" does nothing | `IntranetFileLinksEnabled` missing, GLPI over http, or GLPI/DFS outside the Local intranet zone. |
| "Network path rejected: …" on save | The message gives the reason; usually a path outside the allowed roots or an unconfigured drive letter. |
| Yellow warning "The saved path is no longer allowed" | The path's root was removed from the configuration: opening links are disabled, copying still works. |
| `Duplicate key 21500` in `php-errors.log` | Another plugin uses the same search option ID on Document. |

## Compatibility

Tested on GLPI 11.0.8 and 12.0.2. The same code is checked against the
GLPI 11.0.x and 12.0.x sources (references in [docs/compat.md](docs/compat.md),
in French).

## Development

```bash
composer install
```

```bash
composer test
```

`composer test` runs PHPUnit, then the Node tests (`node --test
tests/js/officelinks.test.js`, Node 18 or later); the latter compare the
JavaScript preview with the results of `PathNormalizer`, which is the
reference implementation.

- **Reference sources**: clone the `11.0.8` and `12.0.1` tags of
  `glpi-project/glpi` into `.glpi-src/` (not versioned) and check any core API
  there before using it; record the result in [docs/compat.md](docs/compat.md).
- **Translations**: strings are written in English with
  `__('…', 'officelinks')`. After adding or changing a string:

  ```bash
  php tools/locales.php
  ```

  The script updates `locales/officelinks.pot`, adds new strings to
  `locales/fr_FR.po` (to be translated) and `locales/en_GB.po`, then compiles
  the `.mo` files (fr_FR, fr_CA, fr_BE, en_GB, en_US). `tests/LocalesTest.php`
  fails if a translation is missing or a `.mo` file is out of date.
- **Continuous integration** (GitHub Actions):
  - `tests.yml`: `php -l`, PHPUnit and JavaScript tests on PHP 8.2, 8.3 and
    8.4, on every pull request and every push to `main`;
  - `release.yml`: builds `officelinks-X.Y.Z.zip` (allow-list of the files
    GLPI needs: no `vendor/`, tests, tools or development files) and attaches
    it to every pull request run. The build job is read-only; a separate
    publish job (tags only) checks the archive's sha256 and creates the
    release;
  - actions are pinned to commit SHAs; Dependabot (`.github/dependabot.yml`)
    proposes updates monthly.
- **Releasing a version**:
  1. bump `PLUGIN_OFFICELINKS_VERSION` in `setup.php` (GLPI then offers
     "Upgrade", and the number busts the browser cache of the JavaScript and
     CSS);
  2. rename the `## [Non publié]` section of `CHANGELOG.md` to
     `## [X.Y.Z] - YYYY-MM-DD`;
  3. merge into `main`, then create and push the tag:

     ```bash
     git tag vX.Y.Z
     ```

     ```bash
     git push origin vX.Y.Z
     ```

     The `release.yml` workflow checks that the tag matches the version, runs
     the tests again and publishes the GitHub release with the archive and
     the CHANGELOG notes.

## License

OfficeLinks is free software, released under the
[GNU General Public License v3.0 or later](LICENSE) (GPL-3.0-or-later), the
licence family of GLPI itself. In short:

- you may use it for any purpose, free of charge, including in a company or
  to provide paid services;
- you may study, modify and redistribute it;
- if you distribute it, modified or not, you must do so under the same
  licence, with its source code; using a modified version internally, without
  distributing it, creates no obligation;
- it comes without any warranty.

This summary is not legal advice: the [LICENSE](LICENSE) file is authoritative.
