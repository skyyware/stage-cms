# Changelog

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
