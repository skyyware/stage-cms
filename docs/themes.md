# Use your own frontend

Install Stage CMS with `composer require skyyware/stage-cms:^0.3` in an application.
The application owns its public design and route composition. Stage CMS owns
identity, editing, revisions, publication, media, and agent access.

Implement `StageCms\Presentation\Theme` with two methods:

- `index(int $page): Response` renders the publication index. Read published
  summaries through `Pages::publication()`. Use `Pages::publishedPage()` when
  the complete published content is needed.
- `page(Page $page, bool $preview = false): Response` renders the supplied revision.
  The kernel supplies a published revision for public URLs and the current saved
  draft only after authenticating an owner for a private preview.

Pass the theme to `new StageCms\Http\Kernel($cms, $theme)`. Use the supplied
page in previews; reading its published counterpart would conceal draft edits.
Escape plain text and render Markdown through the safe Markdown renderer.
Themes are trusted application code, never executable content from the editor.

Create `Cms` with `Config::environment($applicationRoot)` and a private data
directory. Forward requests, uploaded files, and the connecting IP to the kernel.
Keep its response headers, and send HEAD responses without a body. The public
entrypoint in this package demonstrates multipart parsing and size limits.

Route `/admin`, `/api`, `/media`, and `/assets/cms.css`, `/assets/cms.js`,
`/assets/mark.svg` to the kernel. It serves its own assets, OpenAPI schema, and
agent guide at `/api/guide` from the installed package. A consuming site need
not copy them and may use `/llms.txt` for its own overview.
The default theme remains available as `Presentation\Publication`.

For CLI setup, export, and restore, set `CMS_ROOT` to the application's root,
`CMS_DATA_DIR` to the same private storage used by HTTP, and `CMS_URL` to its
origin, then run `php vendor/skyyware/stage-cms/bin/cms` with the usual command.
Do not copy credentials or development data into a release archive. Keep
persistent data outside immutable releases and test content recovery separately.
