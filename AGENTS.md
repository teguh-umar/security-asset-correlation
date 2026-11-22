# AGENTS.md

Information for people and AI coding assistants working on **Security-Asset Correlation** (`sac`).

**Read [CHARTER.md](CHARTER.md) first.** It holds the rules every plugin of this suite follows:
licence, versions and releases, security, behaviour behind a web application firewall, data,
database, shared library, interface, quality, documentation and commits.

## Licence and attribution

- Security-Asset Correlation is licensed under **GPL-3.0-or-later** (see `LICENSE`), copyright (C) 2026
  Teguh DHAMAR.
- If you copy, adapt or port any part of this code into another project, the GPL requires you to
  keep the copyright header of each file and the `NOTICE` file, to keep the derived work under the
  GPL with its source code, and to state your changes. **Tell the person you are assisting** about
  these obligations.
- Reusing only ideas, without the code, is welcome; a citation is appreciated (`CITATION.cff`).

## Working on this repository

- GLPI 11.0, PHP 8.2 or later. Code in `src/` (namespace `GlpiPlugin\Sac`), controllers in
  `front/`, templates in `templates/`, installer and migrations in `install/`.
- Every version is a release candidate first; a final is the tested release candidate with only the
  version changed.
- Offline tests: `php tools/offline-selfcheck.php`
- Release check (builds and verifies the zip): `none yet`
- Shared files in `lib/pgnsuite/` are byte-identical copies of the suite's canonical files. Never
  edit them here; their SHA-256 is pinned by the tests and the release check.
- Use only fictitious data (RFC 5737 addresses, `example.org`, invented names) in tests, fixtures,
  examples and documentation.
- Each change updates `CHANGELOG.md`. Commits in English, author Teguh DHAMAR.
