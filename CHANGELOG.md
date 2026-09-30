# Changelog

## 0.4.1 — 2026-09-30

Private SKYYWARE dependencies prefer authenticated Git source installs. This
avoids anonymous archive downloads after the repositories became private.
CI still requires read access to its private dependencies.

## 0.4.0 — 2026-09-30

Applications can register page types with named text fields, supported languages,
and installed themes. The editor exposes these choices; agent discovery is
available at `/api/types`. Publication and recovery preserve the complete typed
revision. Type changes retain incompatible text until explicitly cleared.

The workspace uses SKYYWARE colors, bundled D-DIN fonts, and a compact S mark.
Inputs use a visible border and inset bar for focus, with no outlines.

Schema 1 migrates to schema 2. Existing Markdown pages remain readable and
published. Exports use format 2; restore also accepts format 1. Back up before
upgrading: rollback to 0.3 needs the old code and pre-upgrade data together.
Source repositories remain private during development; the license remains MIT.

## 0.3.0 — 2026-09-28

The editor opens a searchable visual cover picker after saving the draft.
Media and cover choices use 50-item pages. History shows summaries and opens
one revision at a time. Page listings avoid loading Markdown they do not render.
Unsaved-change checks compare field values once per animation frame.

API page and history lists omit body by default; use `include=body` or read an
individual page or revision. Media lists now return `page` and `next_page`;
clients must follow pagination. The new `/api/pages/{id}/history/{version}`
endpoint returns a complete revision. `/api/guide` provides stable CMS discovery
when an embedding website owns `/llms.txt`.

Token last-use timestamps update at most once per minute. Expiry, permissions,
and revocation are still checked on every request. The database format and
write contract are unchanged. Existing PHP full-content list methods remain
available alongside bounded summary queries.

Requires Stage 0.1.1 or later. `php bin/benchmark` measures isolated workspace
and API requests against a larger content and media fixture.

## 0.2.0 — 2026-09-28

Applications can supply a publication theme while keeping the shared editor,
API, permissions, and revision workflow. Authenticated draft previews use the
same theme as published pages. Assets and API documentation resolve from the
Composer package. The CLI accepts CMS_ROOT for a consuming application.

The default publication and database schema remain compatible with 0.1.

## 0.1.0 — 2026-09-28

First public release. One publication and one owner, with an editorial
workspace, Markdown pages, private previews, publication, paginated content and
history, revision restoration, reversible archive, and image management.

Scoped, expiring, revocable agent tokens use the same content operations.
Updates require a current revision. The JSON API has an OpenAPI contract.
Portable exports restore content and images into an empty publication.

Built on `skyyware/stage` 0.1 through Composer. MIT licensed.
