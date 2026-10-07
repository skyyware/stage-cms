# How Stage CMS stores and publishes content

Stage CMS is one PHP application with SQLite and server-rendered HTML.
The browser and JSON API use the same content operations. Stage supplies HTTP
messages and routing. CommonMark renders Markdown with raw HTML stripped and
unsafe links disabled. The application needs no model provider or JavaScript build step.

## The editing contract

An owner creates a page, saves a draft, previews it, and publishes it.
Saving a later draft preserves the published version. Publishing replaces
the public version explicitly. Restoring an earlier revision creates a new
draft. Archiving removes a page from public access while preserving its history.

Every update to an existing page takes the expected revision. A stale revision fails
without overwriting the current page. One database transaction checks the
version, records the revision, and updates which revision is public.

## Code responsibilities

- Identity owns the local owner, password verification, sessions, and scoped tokens.
- Content owns pages, slugs, page type validation, languages, named fields,
  revisions, publication, and archive transitions.
- Media owns validated image bytes and their metadata.
- Infrastructure owns database setup and portable exports.
- HTTP parses external values, authenticates callers, checks browser CSRF, and translates errors.
- Presentation escapes HTML and renders the editor, library, settings, and public pages.

An authenticated identity produces a Stage Caller. Operations enforce its
permissions before protected work. Agent tokens can read, write drafts, upload
media, or publish according to their explicit scopes. Tokens never acquire
publication rights from request data.

## Storage

Runtime is outside public/ and Git. SQLite uses foreign keys, WAL, a busy timeout,
and immediate write transactions. Images use generated names and validated
content types. The public site reads published revisions only.

Workspace and API lists read metadata without Markdown bodies or named fields.
Each query reads one extra row to determine whether a next page exists. Individual
reads load complete content. The PHP operations `Pages::browse()`,
`Pages::revisions()`, `Pages::publication()`, and `Library::browse()` return a
`Listing` with `items`, `number`, and `nextPage`. Page items are `PageSummary`
objects; requesting `includeBody: true` from browse or revisions yields `Page`
objects with their draft. The existing list, history, and published methods
keep their full-content behavior for PHP callers.

Agent authentication reads current credentials on every request. It writes the
last-use timestamp at most once per minute per token, so repeated reads do not
continually compete with content writes for SQLite's single writer.

## Content and presentation

Schema 3 retains schema 2 content: it stores `type`, `locale`, and JSON `fields` with every revision, and a
selected theme ID in settings. Existing schema 1 revisions migrate to the
Markdown `page` type, language `en`, and empty fields. Migration rechecks the
version under the write lock. Already-current reads need no migration lock.

Schema 3 adds one translation group and identity language per page, unique
within a group. Existing pages start in their own groups. Applications can bind
a name and language to one page ID, type, and public path. A separate alias table
reserves previous public slugs. These identity records are current metadata,
not historical revision snapshots.

Publication checks required fields and the optional application rule after
permission and revision checks, before recording the exact candidate and moving
the publication pointer. All checks share one write transaction. Rules must use
bounded local evidence; network calls would hold SQLite's writer lock. Published
reads never run the rule again. Time-sensitive applications must also filter
expired published evidence when serving it.

Page type definitions validate writes in the content operation, so browser,
API, and PHP callers use the same rules. Installed theme definitions belong to
the consuming application. Theme selection changes presentation, not content.
Publication makes image references in named fields public on the same terms as
Markdown references. History retains those references for safe restoration.

## Alternatives

A separate JavaScript frontend would need its own state handling and build.
PHP renders the editor directly. Applications define text fields for structured
pages, so content never needs an executable template language. Storing revisions
in plain files would require separate locking and indexing. SQLite provides
both and keeps each write in one transaction.

## Limits

This first version serves one site and one human owner with scoped agent tokens.
It is not a multi-tenant service, collaborative live editor, or plugin marketplace.
Multiple application servers and high write throughput need a different measured
storage design. Deployment, TLS, off-host backups, and capacity remain host duties.
