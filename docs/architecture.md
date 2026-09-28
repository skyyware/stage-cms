# One publication, shared operations

Stage CMS is one PHP application with SQLite and server-rendered HTML.
The browser and JSON API use the same content operations. Stage supplies HTTP
messages and routing. CommonMark renders Markdown with raw HTML stripped and
unsafe links disabled. There is no mandatory model provider or browser build.

## The editing contract

An owner creates a page, saves a draft, previews it, and publishes it.
Saving a later draft preserves the published version. Publishing replaces
the public version explicitly. Restoring an earlier revision creates a new
draft. Archiving removes a page from public access while preserving its history.

Every update to an existing page takes the expected revision. A stale revision fails
without overwriting the current page. A database transaction owns the check,
the new revision, and the publication pointer together.

## Owners

- Identity owns the local owner, password verification, sessions, and scoped tokens.
- Content owns pages, slugs, revisions, publication, and archive transitions.
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

## Alternatives

A separate JavaScript application and API would duplicate state and deployment
work in this first workflow. A general content-schema platform would add choices
before writing the first page. Files alone would require another locking and
index design for revisions and concurrent human/agent writes. SQLite keeps the
initial deployment and transaction boundary in one place.

## Limits

This first version serves one site and one human owner with scoped agent tokens.
It is not a multi-tenant service, collaborative live editor, or plugin marketplace.
Multiple application servers and high write throughput need a different measured
storage design. Deployment, TLS, off-host backups, and capacity remain host duties.
