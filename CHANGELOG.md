# Changelog

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
