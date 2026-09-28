# Work through the API

The running application serves [openapi.json](openapi.json) at `/api/schema`
and the [agent guide](agents.txt) at `/llms.txt`. Both are public documentation.
All content API endpoints require a bearer token; a browser cookie does not
authenticate the API.

Create a connection in the owner interface or use the trusted server CLI:

```sh
php bin/cms token --name="Editorial assistant" --scopes=content:read,content:write --days=90 --json
```

The token appears once. Only its hash is stored. Read access is always included.
Publication requires both `content:write` and `content:publish`. Tokens cannot
manage credentials, site settings, exports, or other tokens.

## Create, edit, publish

```sh
curl -X POST http://127.0.0.1:8088/api/pages \
  -H "Authorization: Bearer $STAGE_CMS_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"title":"Hello, world","slug":"hello-world","body":"A considered beginning."}'
```

The response contains the page ID, `version: 1`, and `status: "draft"`.
Creation cannot publish. Read or list the page, then replace its draft:

```sh
curl -X PUT "http://127.0.0.1:8088/api/pages/$PAGE_ID" \
  -H "Authorization: Bearer $STAGE_CMS_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"title":"Hello, world","slug":"hello-world","body":"A second thought.","expected_version":1}'
```

`PUT` replaces the draft fields. Omitted `excerpt`, `body`, and `cover` become
empty, empty, and null. The published version stays unchanged. After an
authorized review, a token with publication scope can publish the saved draft:

```sh
curl -X POST "http://127.0.0.1:8088/api/pages/$PAGE_ID/publish" \
  -H "Authorization: Bearer $STAGE_CMS_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"expected_version":2}'
```

Publication creates another revision. Always use the version returned by the
last read/write, not a hard-coded increment.

## Conflicts and retries

A write using an old version returns `409 stale_revision`. Keep the proposed
change, read the latest revision, and compare. Never blindly retry with a
new version number.

Writes have no idempotency-key mechanism. After a lost response, read
the page and its history before retrying. A unique slug prevents duplicate
creation at the same address. `GET /api/pages?q=hello-world` can locate it.

## Reading and recovery

`GET /api/pages` accepts `status=all|draft|published|archived`, `q`, and `page`.
`GET /api/pages/{id}/history` accepts `page`. Both return 50 items at most plus
`next_page`. A full final page can point to an empty next page. Lists may move
while writers work; they are not a transaction-wide snapshot.

`POST /api/pages/{id}/restore` takes `revision` and `expected_version`.
It copies that content into a new draft. `unpublish`, `archive`, and `recover`
take `expected_version`. Archive removes the public page; recovery is private.
The original history remains available.

## Media

Upload JSON containing `name`, base64-encoded image bytes under `base64`, and
optional `alt` to `POST /api/media`. Accepted types are JPEG, PNG, and WebP.
Use the returned ID as a cover or its URL in Markdown.

Images are private until referenced by a published page. A reference is a
cover ID or `/media/{id}` appearing in the published Markdown. Public copies
already downloaded cannot be recalled by unpublishing. Fetch private bytes
with a bearer token at `/media/{id}`.

`PATCH /api/media/{id}` changes `alt`. This metadata change applies to any
published cover using the image. `DELETE` refuses any image referenced in
current content or history.
