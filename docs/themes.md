# Page types, languages, and themes

The application owns public routes and design. Stage CMS owns editing,
identity, revisions, publication, media, and agent access. A page type names
its content fields; a theme renders that content.

## Install in an application

While the repositories are private, add both VCS repositories to the consuming
application's `composer.json`. Composer does not inherit dependency repositories.
Use an account with access; keep credentials outside the repository.

```json
{
  "repositories": [
    {"type": "vcs", "url": "git@github.com:skyyware/stage-cms.git"},
    {"type": "vcs", "url": "git@github.com:skyyware/stage.git"}
  ],
  "require": {"skyyware/stage-cms": "^0.4.0"}
}
```

## Define content

```php
use StageCms\Cms;
use StageCms\Content\Field;
use StageCms\Content\PageType;
use StageCms\Content\PageTypes;
use StageCms\Infrastructure\Config;

$types = new PageTypes(new PageType('homepage', 'Homepage', [
    new Field('hero.title', 'Headline', 'Hero', limit: 200),
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
oversized values fail before writing. Empty fields are allowed. Field values
are plain text; the theme escapes them in their output context.

Leave the locale map empty to accept any valid language code. When a map is
provided, both browser and API writes enforce its choices. Language is revision
metadata; the application owns translation relationships and URL routing.

In the editor choose **Page type**, then **Apply type**. This changes the form
without saving. Incompatible text remains visible so it can be moved or cleared.
Select **Language**, complete the fields, and save a draft. Type, locale, fields,
Markdown, and cover participate in the same publication and history workflow.

Types are trusted application definitions, never code supplied by content.
Removing a type does not erase its stored pages, but further edits require an
installed definition. Keep definitions compatible with revisions you may restore.

## Render the public site

Implement `StageCms\Presentation\Theme`:

- `index(int $page): Response` reads published content for a listing or homepage.
- `page(Page $page, bool $preview = false): Response` renders the supplied revision.

Read `$page->type`, `$page->locale`, and `$page->draft->fields`. Read published
summaries with `Pages::publication()` and full pages with `publishedPage()`.
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

The default publication maps `slug` to `/{slug}`. An application can map fixed
routes such as `/de` to a published content key such as `de-home`. Document
those mappings for editors and agents. Moving a key does not create redirects.

Use a private persistent data directory outside public/ and release folders.
Set `CMS_ROOT`, `CMS_DATA_DIR`, and `CMS_URL` consistently for HTTP and the CLI.
Run the installed `vendor/skyyware/stage-cms/bin/cms` for setup, export, restore,
and password reset. Never ship credentials or development data in a release.
