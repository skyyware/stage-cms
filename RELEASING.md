# Release Stage CMS

Follow the [Stage package release workflow](https://github.com/skyyware/stage/blob/main/docs/releases.md).
This repository owns `skyyware/stage-cms`, its API, and storage formats.

Run `composer check`. Check the OpenAPI schema and agent guide against the
implementation. For interface changes, verify a complete draft, preview,
publication, revision, and recovery workflow on desktop and mobile with
keyboard navigation and synthetic content.

Verify both public installation paths without credentials or VCS overrides:

```sh
composer create-project skyyware/stage-cms my-site "0.5.1" --no-dev --no-plugins
composer require skyyware/stage-cms:0.5.1 --no-plugins
```

Use separate disposable directories. In the standalone installation, run
`php bin/cms help`, setup, and a publication check. In the embedded consumer,
use `CMS_ROOT` to run the installed CLI and exercise the documented theme
integration. Keep credentials and databases outside Git and the web root.

The current release is 0.5.1, with schema 3 and export format 3. It is compatible
with 0.5.0. Earlier 0.4 installations need the documented backup and migration.
Update version references and upgrade notes before later releases.
