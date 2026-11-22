# Suite charter for GLPI plugins

Charter version: 1.0 (2026-11-22)

This charter is the single reference for every plugin of this GLPI suite. Each plugin repository
carries a byte-identical copy of this file. To change a rule, change the canonical copy and ship it
to every plugin in its next release.

## 1. Licence and attribution

- Every plugin is licensed under **GPL-3.0-or-later**. The repository holds the full licence text
  in `LICENSE` and a short `NOTICE`.
- The plugin author shown in GLPI (`'author'` in `setup.php`) is **Teguh DHAMAR**. `CITATION.cff`
  gives the same name.
- Code taken from another project keeps its original copyright header and its `NOTICE`, and the
  README names the original project. A fork says what it changed.
- Reusing only ideas or data structures, without the code, needs no notice; a citation is welcome.

## 2. Versions and releases

- **Every version is a release candidate first** (`X.Y.Z-rcN`). It is tested on a staging GLPI
  before anything else.
- A final version (`X.Y.Z`) is the tested release candidate with only the version changed. A code
  change after testing means a new release candidate.
- A release has a tag, a zip and the zip's SHA-256. The zip holds only what GLPI needs to run the
  plugin: no tests, tools, design documents or repository files.
- Tags are pushed one by one, by name. History on a shared remote is never rewritten without an
  explicit decision by the maintainer.
- `CHANGELOG.md` gets an entry for every release.

## 3. Compatibility

- GLPI 11.0 (the version tested is stated in the README). PHP 8.2 or later.
- Code follows the GLPI 11 conventions: `src/` with PSR-4 classes in `GlpiPlugin\<Key>`, `front/`
  controllers, Twig templates, static files under `public/`.

## 4. Security

- Every form that changes data sends GLPI's CSRF token, and every controller checks it.
- Every page and action checks the plugin's profile rights. A user only sees and changes rows of
  the entities they may see (entity restriction on every query, including counters and exports).
- Secrets (API keys, tokens, passwords) are stored with GLPI's secured configuration (encrypted).
  They are sent once, by POST, and never shown again, logged, put in a URL or written to a file.
- No call to an outside service while a page is displayed. Outside calls run from automatic actions
  (cron) or an explicit button, with a timeout, and fail without breaking the page.
- Connectors read only, unless the feature's purpose is to write, and then the write is explicit.

## 5. Safe behind a web application firewall

Production GLPI instances may sit behind a web application firewall. A plugin must not look like an
attack to it:

- Request parameters carry ids and short keys only: never code, URLs, file paths, SQL, shell or
  template text.
- No base64, JSON or serialized blobs in parameters.
- Long text and secrets go in the body of a POST, never in the query string.
- Endpoints are plain `front/*.php` pages with ordinary names.
- Every new endpoint is listed in the release notes, so it can be checked before production.

## 6. Data

- **Only fictitious data** in tests, fixtures, screenshots, examples and documentation: names like
  `example.org`, addresses from RFC 5737 (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`),
  invented people, hosts and organisations.
- No real host name, address, person, e-mail address, organisation name or credential in a
  repository, a commit message or an issue. A repository is scanned for them before every push.
- A plugin never deletes or changes data it does not own, except through the owner's API.

## 7. Database

- Tables are named `glpi_plugin_<key>_<name>`; ids and foreign keys are `INT UNSIGNED`.
- The installer creates and migrates tables and can be run again without harm (idempotent).
  Uninstall removes the plugin's tables, rights, crons, display preferences and configuration.
- A plugin never writes another plugin's tables. It uses that plugin's itemtype API, behind a
  `class_exists()` or plugin-active check, so it keeps working when the other plugin is absent.

## 8. Shared library

- Shared code lives in `lib/pgnsuite/` (menu sector, configuration tab, asset tab, settings page).
  Each copy is byte-identical to the canonical copy; tests, the self-check and the release check pin
  its SHA-256.
- Shared files are marked `-text` in `.gitattributes`, so no tool changes their line endings.
- Every plugin appears under the common menu sector **ICT Tools**. Settings are under
  **Setup > ICT Tools** or the plugin's configuration page.

## 9. Interface

- Interface strings are in English and go through GLPI's translation functions with the plugin's
  domain.
- Pages use GLPI's layout and Twig. Every page has a short "what is this page for" help text.
- A page shows nothing a user may not see; an empty state says why it is empty.

## 10. Quality

- Each plugin has an offline test suite (`tests/`) that needs no GLPI instance, and a release check
  (`tools/release_check.py`) that builds the zip and verifies its content.
- Behaviour that needs a real GLPI is tested on staging with synthetic data, then cleaned up by id.
- Text files use UTF-8 and LF line endings.

## 11. Documentation

Each repository has:

- `README.md`: what the plugin does, requirements, install and upgrade, configuration, rights,
  automatic actions, data it stores, security notes, development, licence;
- `CHANGELOG.md`;
- `AGENTS.md`: how to work on the repository (for people and AI assistants);
- `CITATION.cff`, `LICENSE`, `NOTICE`, `SECURITY.md` and this `CHARTER.md`;
- for features users work with every day, a manual in `docs/manual/`.

## 12. Commits

- Author and committer: Teguh DHAMAR, with the maintainer's own address.
- One logical change per commit, with a message that says what changed and why, in English.
- No secret, real data or generated file in a commit.
