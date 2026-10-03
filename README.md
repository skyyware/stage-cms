# Stage CMS

Write, preview, and publish pages. Give agents only the access they need.

Stage CMS is an MIT-licensed PHP application built on the
[Stage framework](https://github.com/skyyware/stage). People use the workspace.
Agents use the API. Both work with the same content operations, permissions,
and version history.

Version 0.5 is an early, working release for one publication and one owner.
It includes pages, Markdown editing, private previews, publication, revision
restoration, named content fields, page types, languages, theme selection, an
image library, agent connections, and portable export/restore. Pages have stable
identities, linked translations, optional fixed public paths, and publication rules.
The API and database format may change before 1.0.

## Start a publication

Requires PHP 8.4 or 8.5, Composer 2, and the Fileinfo, GD with JPEG/PNG/WebP,
Mbstring, PDO SQLite, and Zip extensions.

Install the tagged application from
[Packagist](https://packagist.org/packages/skyyware/stage-cms).
No GitHub account, SSH identity, or custom Composer repository is required.

```sh
composer create-project skyyware/stage-cms my-site "^0.5.2" --no-plugins
cd my-site
php bin/cms setup --name="Your name" --email="you@example.com"
composer serve
```

Setup asks for a password without displaying it. Open
<http://127.0.0.1:8088/admin>, sign in, and write your first page.
The publication is at <http://127.0.0.1:8088>.

There are no default credentials, public account registration, external fonts,
analytics, mandatory cloud services, or JavaScript build step. Source code,
packages, and private data stay outside the web root. Serve only `public/`.
Read the [deployment guide](docs/operations.md) before using a public server.

## A small, complete workflow

1. Create a page. Choose its type and language. Write in its named fields or Markdown.
2. Save a private draft. Preview opens the latest saved version.
3. Publish when ready. Editing again keeps the previous publication live.
4. Use history to restore an earlier version as a new draft.
5. Unpublish or archive a page. Recover an archived page whenever needed.

Upload images from Media and describe them. In the editor, **Save & choose image**
saves your draft and opens a searchable cover picker. You can also insert an
image address in Markdown. Images remain private until a published page
references them. Deleting an image used by any revision is refused.

Settings contains the publication name, description, installed theme, and a ZIP export of
content, revisions, and images. Exports exclude passwords and agent tokens.
See [recovery](docs/operations.md#recovery).

## An agent is a caller with limited access

Create a named connection in **Agents**. Copy its token once. Give it draft
access first; publication is a separate permission. Connections expire and can
be revoked immediately.

```sh
curl -H "Authorization: Bearer $STAGE_CMS_TOKEN" \
  http://127.0.0.1:8088/api/pages
```

Every update supplies the version it read. A stale update returns `409`;
the caller must compare and reconcile before trying again. Content cannot
grant permissions, change credentials, or override the application's rules.

The [agent guide](docs/agents.txt), [API guide](docs/api.md), and
[OpenAPI schema](docs/openapi.json) describe the full interface. Running
instances serve the guide at `/api/guide` and the schema at `/api/schema`.
Standalone installations also serve the guide at `/llms.txt`. The CMS does not require
a particular model, agent provider, or MCP server.

## One application you can own

Stage handles requests, routes, responses, and caller permissions. This
application owns content, identity, storage, and presentation. SQLite stores
structured state; images live in a private directory. PHP renders the interface.
A little browser JavaScript helps with formatting and unsaved changes.
The core writing workflow also works without JavaScript.

For an existing site, install this package and supply a [publication theme](docs/themes.md).

Read the [architecture](docs/architecture.md) and [design](docs/design.md).
The intent is to make the whole application understandable to one person.

## Develop and contribute

Clone `https://github.com/skyyware/stage-cms.git` and run `composer install`
when working on the application source.

```sh
composer check
php bin/cms help
php bin/benchmark
```

Checks validate package metadata, PHPStan at its strictest level, and behavior
tests. Repository automation is disabled; run these checks locally before delivery.
Browser verification should cover a complete
publishing workflow at desktop and mobile widths.

The benchmark creates isolated fixtures for 100 pages, 50 revisions, and 10,000
image records, then removes them. It reports PHP request handling time, response
size, and memory for the workspace and API. It does not measure image transfer,
network latency, concurrent traffic, or production capacity.

Bug reports, documentation fixes, accessibility improvements, and focused pull
requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and
[AGENTS.md](AGENTS.md). For vulnerabilities, use the private reporting route in
[SECURITY.md](SECURITY.md).
Maintainers follow [RELEASING.md](RELEASING.md).

## Current limits

One owner and one site; no team roles, comments, scheduled publication,
a plugin marketplace, or real-time collaboration. Applications register their
own page types and themes in code. Types provide text fields, not arbitrary
executable templates or a visual layout builder. Pages have one
lowercase content key; applications can bind pages to stable public paths.
Markdown accepts no raw HTML. External images are
blocked by the content security policy; upload them to the library.

Pages, history, and images use 50-item pagination. Lists read summaries; complete
content loads when opened or explicitly requested through the API. Images are limited to
5 MiB and 16 megapixels; bodies to 200 KB and named fields to 200 KB of encoded JSON. Portable exports are limited to
128 MiB uncompressed. Large installations need their own measured capacity and
backup plan. No horizontal write scaling or independent security audit is
claimed for this release.

[MIT](LICENSE) · [Changelog](CHANGELOG.md) ·
[Stage](https://github.com/skyyware/stage)

Bundled D-DIN fonts retain their separate [SIL Open Font License](public/assets/D-DIN-OFL.txt).
