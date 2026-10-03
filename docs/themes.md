# Page types, languages, and themes

The application owns public routes and design. Stage CMS owns editing,
identity, revisions, publication, media, and agent access. A page type names
its content fields; a theme renders that content.

## Install in an application

Install the public package in your existing Composer project:

```sh
composer require skyyware/stage-cms:^0.5.2
```

No VCS override or private repository access is required. Commit your
application's lockfile. When upgrading from 0.4, follow the
[schema migration and recovery guide](operations.md#updates).

## Define content

```php
use StageCms\Cms;
use StageCms\Content\Field;
use StageCms\Content\PageType;
use StageCms\Content\PageTypes;
use StageCms\Infrastructure\Config;

$types = new PageTypes(new PageType('homepage', 'Homepage', [
    new Field('hero.title', 'Headline', 'Hero', limit: 200, required: true),
    new Field('hero.copy', 'Introduction', 'Hero', multiline: true),
], markdown: false));
$cms = new Cms(Config::environment($applicationRoot), $types, [
    'en' => 'English',
    'de' => 'Deutsch',
]);
```

The default `page` type accepts Markdown. Additional types may use Markdown,
named fields, or both. A field has a stable key, label, group, multiline flag,
and character limit. Unknown types, unsupported locales, unknown fields, and
oversized values fail before writing. Empty fields are allowed in drafts. A field
with `required: true` must contain nonblank text before publication. Field values
are plain text; the theme escapes them in their output context.

Leave the locale map empty to accept any valid language code. When a map is
provided, both browser and API writes enforce its choices. Choose a language
when creating a page. Existing pages keep that language; use a linked translation
to write another version. Each translation has a separate publication and history.

In the editor choose **Page type**, then **Apply type**. This changes the form
without saving. Incompatible text remains visible so it can be moved or cleared.
For a new page, select **Language**, complete the fields, and save a draft. Type, locale, fields,
Markdown, and cover participate in the same publication and history workflow.

Types are trusted application definitions, never code supplied by content.
Removing a type does not erase its stored pages, but further edits require an
installed definition. Keep definitions compatible with revisions you may restore.

## Render the public site

Implement `StageCms\Presentation\Theme`:

- `index(int $page): Response` reads published content for a listing or homepage.
- `page(Page $page, bool $preview = false): Response` renders the supplied revision.

Read `$page->type`, `$page->locale`, and `$page->draft->fields`. Read published
summaries with `Pages::publication()` and full pages with `publishedById()`,
`publishedBinding()`, or `publishedPage()`. `browse(status: 'published')` is an
editorial list: it returns the latest draft of pages with a live revision.
For a preview, render the supplied draft instead of re-reading its publication.
Escape plain text; render Markdown through `Presentation\Markdown`.

Register installed choices with stable IDs:

```php
use StageCms\Http\Kernel;
use StageCms\Presentation\ThemeOption;
use StageCms\Presentation\Themes;

$themes = new Themes($cms, new ThemeOption('website', 'Website', $websiteTheme));
$kernel = new Kernel($cms, $themes);
```

**Settings → Theme** selects one of these installed options. The selection
applies immediately to the public site and previews; it is not a draft page
change. Register more options only when their rendering code exists. An unknown
stored theme fails explicitly; reinstall it or choose an available option.

Passing a single Theme directly to Kernel remains supported, under the ID
`custom`. Omitting it uses the built-in `publication` theme. Keep a stable ID
when adding a registry to an existing installation.

## Compose routes and storage

Forward `/admin`, `/api`, `/media`, `/health`, and the package's `/assets/`
resources to the kernel, including the bundled fonts. The kernel serves its
agent guide at `/api/guide` and schema at `/api/schema`; the site can keep its
own `/llms.txt`. Preserve response headers and omit bodies on HEAD responses.

The default publication maps `slug` to `/{slug}`. For a fixed route, bind a page
ID once from trusted application setup:

```php
$cms->pages->bind($owner, 'homepage', $englishPage->id, '/');
$cms->pages->bind($owner, 'homepage', $germanPage->id, '/de');
$page = $cms->pages->publishedBinding('homepage', 'de');
```

Binding requires `admin`. The same binding is safe to repeat; conflicting names,
languages, paths, or page IDs fail. Translations with the same binding name join
one group, with at most one page per language. A binding fixes the page type and
language, and survives slug edits, unpublishing, archive, and recovery. It changes
routing immediately; install bindings during application setup or migration.
`bound($owner, $name, $locale)` also finds drafts and archived pages so an importer
can preserve them. The content API cannot change bindings.

`Page::path()` returns the bound path or `/{slug}`. Paths are `/` or lowercase
segments separated by slashes, with digits and hyphens allowed. CMS endpoint
prefixes are reserved. The default kernel resolves bound paths and redirects old
slugs with HTTP 301. An application with its own routes can use `resolvePath()`
and redirect when the returned page's `path()` differs from the requested path.
Old public slugs stay reserved to their page; they resolve only while that page
is published. `publishedPage($slug)` remains an exact-slug read.

See [publication rules](publication.md) for application validation.

Use a private persistent data directory outside public/ and release folders.
Set `CMS_ROOT`, `CMS_DATA_DIR`, and `CMS_URL` consistently for HTTP and the CLI.
Run the installed `vendor/skyyware/stage-cms/bin/cms` for setup, export, restore,
and password reset. Never ship credentials or development data in a release.
