<?php
declare(strict_types=1);

namespace StageCms\Presentation;

use StageCms\Content\Page;
use StageCms\Content\PageTypes;
use StageCms\Content\PageSummary;
use StageCms\Identity\Session;
use StageCms\Input;
use StageCms\Media\Asset;

final readonly class View
{
    /** @param array<string, string> $locales */
    public function __construct(private string $siteTitle, private Markdown $markdown = new Markdown(), private PageTypes $types = new PageTypes(), private array $locales = []) {}

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function csrf(Session $session): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e($session->csrf) . '">';
    }

    public function document(string $title, string $body, string $class = ''): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . self::e($title) . ' · ' . self::e($this->siteTitle) . '</title>'
            . '<link rel="icon" href="/assets/mark.svg" type="image/svg+xml"><link rel="stylesheet" href="/assets/cms.css">'
            . '<script src="/assets/cms.js" defer></script></head><body class="' . self::e($class) . '">'
            . '<a class="skip" href="#main">Skip to content</a>' . $body . '</body></html>';
    }

    public function shell(string $title, string $section, string $body, Session $session, string $notice = '', string $error = ''): string
    {
        $nav = '';
        foreach (['pages' => 'Pages', 'media' => 'Media', 'agents' => 'Agents'] as $key => $label) {
            $nav .= '<a href="/admin/' . $key . '"' . ($section === $key ? ' aria-current="page"' : '') . '><span class="nav-icon" aria-hidden="true">'
                . match ($key) {'pages' => '▤', 'media' => '◫', 'agents' => '✳'} . '</span>' . $label . '</a>';
        }
        $site = self::e($this->siteTitle);
        $csrf = self::csrf($session);
        $alerts = ($notice !== '' ? '<div class="notice" role="status">' . self::e($notice) . '</div>' : '')
            . ($error !== '' ? '<div class="notice error" role="alert">' . self::e($error) . '</div>' : '');
        return $this->document($title, <<<HTML
            <div class="app">
            <aside class="sidebar"><a class="brand" href="/admin/pages"><img src="/assets/mark.svg" alt="" width="27" height="27"><span>stage<span class="brand-cms">cms</span></span></a>
            <div class="workspace"><span class="workspace-dot"></span><span>{$site}</span></div>
            <nav aria-label="Workspace">{$nav}</nav>
            <div class="sidebar-bottom"><a href="/" target="_blank" rel="noopener">View site <span aria-hidden="true">↗</span></a>
            <a href="/admin/settings">Settings</a><form method="post" action="/admin/logout">{$csrf}<button class="link" type="submit">Sign out</button></form>
            <span class="version">STAGE CMS / 0.5</span></div></aside>
            <main id="main" class="main"><div class="topline"><span>WORKSPACE <span class="slash">/</span> {$site}</span><a href="/admin/help">Help <span aria-hidden="true">↗</span></a></div>
            {$alerts}{$body}<footer class="app-footer"><span>Stage CMS</span><span>by SKYYWARE</span></footer></main></div>
            HTML);
    }

    public function heading(string $eyebrow, string $title, string $description, string $action = ''): string
    {
        return '<header class="heading"><div><p class="eyebrow">' . self::e($eyebrow) . '</p><h1>' . self::e($title)
            . '</h1><p class="intro">' . self::e($description) . '</p></div>' . $action . '</header>';
    }

    /** @param list<PageSummary> $pages */
    public function pages(array $pages, string $filter, string $search): string
    {
        $body = $this->heading('CONTENT', 'Pages', 'Manage drafts and published pages.',
            '<a class="button primary" href="/admin/pages/new"><span aria-hidden="true">＋</span> New page</a>');
        $body .= '<div class="collection-tools"><nav class="tabs" aria-label="Page status">';
        foreach (['all' => 'All pages', 'draft' => 'Drafts', 'published' => 'Published', 'archived' => 'Archive'] as $value => $label) {
            $body .= '<a href="/admin/pages?status=' . $value . '"' . ($filter === $value ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }
        $body .= '</nav><form class="search" action="/admin/pages"><input type="hidden" name="status" value="' . self::e($filter) . '">'
            . '<label class="sr-only" for="search">Search pages</label><input id="search" name="q" value="' . self::e($search) . '" placeholder="Search pages…" maxlength="100">'
            . '<button type="submit" aria-label="Search pages">⌕</button></form></div>';
        if ($pages === []) {
            return $body . '<section class="empty"><span class="empty-mark" aria-hidden="true">▤</span><h2>'
                . ($search !== '' ? 'No pages found.' : 'No pages yet.')
                . '</h2><p>' . ($search !== '' ? 'Try another title or address.' : 'Create a page, save a draft, and preview it before publishing.')
                . '</p><a class="button" href="/admin/pages/new">Write a page <span aria-hidden="true">↗</span></a></section>';
        }
        $body .= '<div class="page-table"><div class="table-labels"><span>PAGE</span><span>STATUS</span><span>LAST EDITED</span><span></span></div>';
        foreach ($pages as $page) {
            $body .= '<a class="page-row" href="/admin/pages/' . $page->id . '"><div class="page-title"><span class="page-symbol" aria-hidden="true">▤</span><span><strong>'
                . self::e($page->title) . '</strong><small>' . self::e(($this->types->all[$page->type]->label ?? $page->type) . ' · ' . strtoupper($page->locale)) . ' · ' . self::e($page->path()) . '</small></span></div>'
                . self::badge($page) . '<time datetime="' . self::e($page->updatedAt) . '">' . self::date($page->updatedAt)
                . '</time><span class="row-arrow" aria-hidden="true">↗</span></a>';
        }
        return $body . '</div><p class="collection-note">' . count($pages) . ' ' . (count($pages) === 1 ? 'page' : 'pages') . ' in this view · Every saved change has a history.</p>';
    }

    public static function badge(PageSummary $page): string
    {
        return '<span class="badge ' . $page->status() . '"><span aria-hidden="true"></span>'
            . match ($page->status()) {'draft' => 'Draft', 'changed' => 'Unpublished edits', 'published' => 'Published', default => 'Archived'} . '</span>';
    }

    /**
     * @param array<string, mixed> $values
     * @param list<Asset> $media
     * @param array<string, string> $errors
     * @param list<PageSummary> $translations
     */
    public function editor(?Page $page, array $values, array $media, Session $session, bool $unsaved = false, array $errors = [], array $translations = []): string
    {
        $id = $page->id ?? 'new';
        $action = '/admin/pages/' . $id;
        $version = Input::text($values, 'expected_version', (string) ($page->version ?? 1));
        $csrf = self::csrf($session);
        $body = '<h1 class="sr-only">Edit page</h1><div class="editor-heading"><a class="back" href="/admin/pages">← All pages</a><div>'
            . ($page !== null ? self::badge($page) . '<span class="revision-label">Revision ' . $page->version . '</span>' : '<span class="badge draft">New draft</span>') . '</div></div>';
        if ($page?->archived) {
            return $body . $this->heading('IN THE ARCHIVE', $page->draft->title, 'Recover this page as a draft whenever you need it.')
                . '<form method="post" action="' . $action . '/recover">' . $csrf . '<input type="hidden" name="expected_version" value="' . $page->version
                . '"><button class="button primary">Recover page</button></form>';
        }
        $title = self::e(Input::text($values, 'title', ''));
        $slug = self::e(Input::text($values, 'slug', ''));
        $excerpt = self::e(Input::text($values, 'excerpt', ''));
        $content = self::e(Input::text($values, 'body', ''));
        $cover = Input::text($values, 'cover', '');
        $options = '<option value="">No cover image</option>';
        foreach ($media as $asset) {
            $options .= '<option value="' . $asset->id . '"' . ($asset->id === $cover ? ' selected' : '') . '>' . self::e($asset->name) . '</option>';
        }
        $coverControl = $media === [] ? '<input type="hidden" name="cover" value="' . self::e($cover) . '"><p class="cover-empty">' . ($cover === '' ? 'No cover selected' : 'Cover retained') . '</p>'
            : '<img class="cover-preview" src="/media/' . $media[0]->id . '" alt="' . self::e($media[0]->alt) . '"><select id="cover" name="cover" aria-label="Cover image">' . $options . '</select>';
        $preview = $page === null ? '<span class="muted">Save to preview</span>' : '<a class="button quiet" href="' . $action . '/preview" target="_blank" rel="noopener">Preview <span aria-hidden="true">↗</span></a>';
        $publish = $page?->publishedVersion !== null ? 'Publish changes' : 'Publish page';
        $pending = $unsaved ? 'true' : 'false';
        $type = Input::text($values, 'type', 'page');
        $definition = $this->types->all[$type] ?? null;
        $typeOptions = '';
        foreach ($this->types->all as $choice) {
            $typeOptions .= '<option value="' . self::e($choice->id) . '"' . ($choice->id === $type ? ' selected' : '') . '>' . self::e($choice->label) . '</option>';
        }
        if ($definition === null) {
            $typeOptions .= '<option value="' . self::e($type) . '" selected>' . self::e($type) . ' (not installed)</option>';
        }
        $locale = self::e(Input::text($values, 'locale', 'en'));
        $languageControl = '<input id="locale" name="locale" value="' . $locale . '" pattern="[a-z]{2,3}(-[A-Z]{2})?" maxlength="6" required>';
        if ($this->locales !== []) {
            $languageControl = '<select id="locale" name="locale">';
            foreach ($this->locales as $code => $name) {
                $languageControl .= '<option value="' . self::e($code) . '"' . ($code === $locale ? ' selected' : '') . '>' . self::e($name) . '</option>';
            }
            $languageControl .= '</select>';
        }
        $typeControl = '<label for="page-type">Page type</label><div class="type-control"><select id="page-type" name="type">' . $typeOptions
            . '</select><button class="button" name="intent" value="type" formnovalidate>Apply type</button></div>'
            . '<p class="field-hint">Choose the fields and layout, then apply.</p>';
        if ($page?->binding !== null) {
            $typeControl = '<span class="field-label">Page type</span><p class="fixed-detail">' . self::e($this->types->all[$page->type]->label ?? $page->type)
                . '</p><input type="hidden" name="type" value="' . self::e($page->type) . '"><p class="field-hint">Assigned by this website.</p>';
        }
        if ($page !== null) {
            $languageControl = '<p class="fixed-detail">' . self::e($this->locales[$page->locale] ?? $page->locale) . '</p><input type="hidden" name="locale" value="' . self::e($page->locale) . '">';
        }
        $typeControl .= ($page === null ? '<label for="locale">Language</label>' : '<span class="field-label">Language</span>') . $languageControl;
        $addressLabel = 'Address';
        $addressHint = 'Lowercase letters, numbers, and hyphens.';
        if ($page?->publicPath !== null) {
            $typeControl .= '<span class="field-label">Public address</span><p class="fixed-detail">' . self::e($page->publicPath) . '</p>';
            $addressLabel = 'Page key';
            $addressHint = 'Used to identify content. The public address stays the same.';
        }
        $namedFields = $this->fields($type, Input::object($values['fields'] ?? []), $errors);
        $markdownVisibility = $definition !== null && !$definition->markdown && $content === '' ? ' hidden' : '';
        $conversion = $definition !== null && !$definition->markdown && $content !== ''
            ? '<p class="notice">This type uses named fields. Move your previous page content into those fields, then clear the Markdown text before saving.</p>' : '';

        $body .= <<<HTML
            <form method="post" action="{$action}" class="editor-form" data-editor data-unsaved="{$pending}">
            {$csrf}<input type="hidden" name="expected_version" value="{$version}">
            <div class="editor-layout"><section class="writing-sheet"><label class="sr-only" for="title">Page title</label>
            <textarea class="title-input" id="title" name="title" rows="2" maxlength="200" placeholder="Page title" required>{$title}</textarea>
            {$namedFields}{$conversion}<div class="markdown-editor"{$markdownVisibility}><div class="format-bar"><span>MARKDOWN</span><div><button type="button" data-format="bold" aria-label="Bold text"><b>B</b></button><button type="button" data-format="italic" aria-label="Italic text"><i>I</i></button><button type="button" data-format="heading" aria-label="Add heading">H₂</button><button type="button" data-format="link" aria-label="Add link">↗</button><button type="button" data-format="list" aria-label="Add list">☷</button></div><a href="/admin/help#writing" target="_blank" rel="noopener" aria-label="Writing guide">?</a></div>
            <label class="sr-only" for="body">Page content</label><textarea class="body-input" id="body" name="body" placeholder="Write your page content." maxlength="200000" data-body>{$content}</textarea></div>
            <div class="writing-bottom"><span data-word-count>Page content</span><span data-save-state>No unsaved changes</span></div></section>
            <aside class="page-details"><h2>Page details</h2>{$typeControl}<label for="slug">{$addressLabel}</label><div class="slug-input"><span>/</span><input id="slug" name="slug" value="{$slug}" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="120" placeholder="your-page" required data-slug></div><p class="field-hint">{$addressHint}</p>
            <label for="excerpt">Summary <span>optional</span></label><textarea id="excerpt" name="excerpt" rows="4" maxlength="500" placeholder="Description for previews and search results.">{$excerpt}</textarea>
            <div class="field-label">Cover image <span>optional</span></div>{$coverControl}<button class="button cover-choose" name="intent" value="cover">Save &amp; choose image</button><p class="field-hint">Your draft is saved before the image library opens.</p>
            <div class="detail-note"><span class="note-dot"></span><p>Save a private draft. Publish to update the live page.</p></div>
            </aside></div><div class="editor-actions"><div>{$preview}</div><div><button class="button" name="intent" value="save" data-save>Save draft</button><button class="button primary" name="intent" value="publish">{$publish} <span aria-hidden="true">↗</span></button></div></div></form>
            HTML;
        if ($page !== null) {
            $body .= '<div class="page-secondary"><a href="' . $action . '/history">Version history <span aria-hidden="true">↗</span></a><div>';
            if ($page->publishedVersion !== null) {
                $body .= '<form method="post" action="' . $action . '/unpublish">' . $csrf . '<input type="hidden" name="expected_version" value="' . $page->version . '"><button class="link">Unpublish</button></form>';
            }
            $body .= '<form method="post" action="' . $action . '/archive">' . $csrf . '<input type="hidden" name="expected_version" value="' . $page->version
                . '"><button class="link">Move to archive</button></form></div></div>';
            $body .= $this->translations($page, $translations, $session);
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function fields(string $type, array $values, array $errors): string
    {
        $groups = [];
        $keys = [];
        $invalidGroups = [];
        foreach ($this->types->all[$type]->fields ?? [] as $field) {
            $keys[$field->key] = true;
            $id = 'field-' . $field->key;
            $value = self::e(Input::text($values, $field->key, ''));
            $attributes = ' id="' . $id . '" name="fields[' . $field->key . ']" maxlength="' . $field->limit . '"';
            $message = '';
            if (isset($errors['fields.' . $field->key])) {
                $attributes .= ' aria-invalid="true" aria-describedby="' . $id . '-error"';
                $message = '<p class="field-error" id="' . $id . '-error">' . self::e($errors['fields.' . $field->key]) . '</p>';
                $invalidGroups[$field->group] = true;
            }
            $groups[$field->group][] = '<div class="content-field"><label for="' . $id . '">' . self::e($field->label)
                . ($field->required ? ' <span class="field-requirement">Required to publish</span>' : '') . '</label>'
                . ($field->multiline ? '<textarea' . $attributes . ' rows="3">' . $value . '</textarea>' : '<input' . $attributes . ' value="' . $value . '">') . $message . '</div>';
        }
        $previous = '';
        foreach ($values as $key => $value) {
            if (!isset($keys[$key]) && is_string($value) && $value !== '') {
                $name = self::e($key);
                $previous .= '<div class="content-field"><label for="previous-' . $name . '">' . $name . '</label><textarea id="previous-' . $name
                    . '" name="fields[' . $name . ']" rows="3">' . self::e($value) . '</textarea></div>';
            }
        }
        $html = '';
        foreach ($groups as $group => $fields) {
            $html .= '<details class="field-group"' . ($html === '' || isset($invalidGroups[$group]) ? ' open' : '') . '><summary>' . self::e($group) . '</summary><div>' . implode('', $fields) . '</div></details>';
        }
        return $html . ($previous === '' ? '' : '<details class="field-group" open><summary>Previous fields</summary><div><p>These fields are not used by this type. Move any text you need into the new fields, then clear them before saving.</p>' . $previous . '</div></details>');
    }

    /** @param list<PageSummary> $translations */
    private function translations(Page $page, array $translations, Session $session): string
    {
        $html = '<section class="translations"><h2>Translations</h2><nav aria-label="Page translations">';
        $existing = [];
        foreach ($translations as $translation) {
            $existing[$translation->locale] = true;
            $html .= '<a href="/admin/pages/' . $translation->id . '"' . ($translation->id === $page->id ? ' aria-current="page"' : '') . '>'
                . self::e($this->locales[$translation->locale] ?? $translation->locale) . '</a>';
        }
        $html .= '</nav>';
        $available = array_diff_key($this->locales, $existing);
        if ($available !== [] || $this->locales === []) {
            $language = '<input id="translation-locale" name="locale" pattern="[a-z]{2,3}(-[A-Z]{2})?" maxlength="6" required>';
            if ($available !== []) {
                $language = '<select id="translation-locale" name="locale">';
                foreach ($available as $locale => $label) {
                    $language .= '<option value="' . self::e($locale) . '">' . self::e($label) . '</option>';
                }
                $language .= '</select>';
            }
            $html .= '<details><summary>New translation</summary><p>Copy the saved revision into a separate draft, then translate its text. Each language has its own history.</p>'
                . '<form method="post" action="/admin/pages/' . $page->id . '/translations">' . self::csrf($session)
                . '<input type="hidden" name="expected_version" value="' . $page->version . '"><div><label for="translation-locale">Language</label>' . $language
                . '</div><div><label for="translation-slug">Address</label><input id="translation-slug" name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="120" required></div><button class="button">Create translation draft</button></form></details>';
        }
        return $html . '</section>';
    }

    /** @param list<PageSummary> $revisions */
    public function history(Page $page, array $revisions, Session $session): string
    {
        $body = '<a class="back" href="/admin/pages/' . $page->id . '">← Back to page</a>'
            . $this->heading('PAGE', 'Version history', $page->draft->title);
        $body .= '<p class="intro">Restore any version as a new draft. Your published page stays as it is.</p><div class="history-list">';
        foreach ($revisions as $revision) {
            $body .= '<article class="history-item"><div><span class="eyebrow">REVISION ' . $revision->version . ' · ' . self::e(strtoupper($revision->action))
                . '</span><h2>' . self::e($revision->title) . '</h2><p>' . self::date($revision->updatedAt) . ' · '
                . (str_starts_with($revision->actor, 'agent:') ? 'Agent' : 'Owner') . '</p><a class="small-link" href="/admin/pages/' . $page->id . '/history/' . $revision->version
                . '">Read revision ' . $revision->version . ' ↗</a></div>';
            if ($revision->version !== $page->version && !$page->archived) {
                $body .= '<form method="post" action="/admin/pages/' . $page->id . '/restore">' . self::csrf($session)
                    . '<input type="hidden" name="expected_version" value="' . $page->version . '"><input type="hidden" name="revision" value="' . $revision->version . '"><button class="button">Restore draft</button></form>';
            } else {
                $body .= '<span class="muted">' . ($revision->version === $page->version ? 'Current version' : 'Archived') . '</span>';
            }
            $body .= '</article>';
        }
        return $body . '</div>';
    }

    public function revision(Page $current, Page $revision, Session $session): string
    {
        $action = $revision->version !== $current->version && !$current->archived
            ? '<form method="post" action="/admin/pages/' . $current->id . '/restore">' . self::csrf($session)
                . '<input type="hidden" name="expected_version" value="' . $current->version . '"><input type="hidden" name="revision" value="' . $revision->version . '"><button class="button">Restore as draft</button></form>'
            : '';
        return '<a class="back" href="/admin/pages/' . $current->id . '/history">← Version history</a>'
            . $this->heading('REVISION ' . $revision->version, $revision->title, 'Saved ' . $revision->updatedAt . '. Restoring keeps the current publication live.', $action)
            . '<p class="intro">' . self::e($revision->type) . ' · ' . self::e($revision->locale) . '</p><article class="revision-reading prose">' . $this->markdown->render($revision->draft->body) . '</article>'
            . '<div class="revision-fields">' . implode('', array_map(fn (string $key, string $value): string => '<section><h2>' . self::e($key) . '</h2><p>' . nl2br(self::e($value)) . '</p></section>', array_keys($revision->draft->fields), array_values($revision->draft->fields))) . '</div>';
    }

    /** @param list<Asset> $assets */
    public function cover(Page $page, array $assets, Session $session, string $search): string
    {
        $path = '/admin/pages/' . $page->id . '/cover';
        $body = '<a class="back" href="/admin/pages/' . $page->id . '">← Back to page</a>'
            . $this->heading('PAGE', 'Choose a cover', 'For ' . $page->title . '. Your saved draft stays private until you publish.')
            . self::mediaSearch($path, $search);
        if ($assets === []) {
            return $body . '<section class="empty"><h2>' . ($search === '' ? 'No images yet.' : 'No matching images.')
                . '</h2><p>' . ($search === '' ? 'Add an image to use it as a cover.' : 'Search by filename or image description.')
                . '</p><a class="button" href="/admin/media" target="_blank" rel="noopener">Open media library ↗</a></section>';
        }
        $body .= '<div class="media-grid cover-grid">';
        foreach ($assets as $asset) {
            $body .= '<form class="media-card cover-card" method="post" action="' . $path . '">' . self::csrf($session)
                . '<input type="hidden" name="expected_version" value="' . $page->version . '"><input type="hidden" name="cover" value="' . $asset->id . '">'
                . '<button class="cover-option" aria-label="Use ' . self::e($asset->name) . ' as cover"><img src="/media/' . $asset->id . '" alt="' . self::e($asset->alt) . '" loading="lazy" width="' . $asset->width . '" height="' . $asset->height
                . '"><span><strong>' . self::e($asset->name) . '</strong><small>' . $asset->width . ' × ' . $asset->height . '</small><span class="cover-action">'
                . ($asset->id === $page->cover ? 'Current cover' : 'Use as cover ↗') . '</span></span></button></form>';
        }
        return $body . '</div>';
    }

    private static function mediaSearch(string $path, string $search): string
    {
        return '<form class="search media-search" action="' . self::e($path) . '"><label class="sr-only" for="media-search">Search images</label>'
            . '<input id="media-search" name="q" value="' . self::e($search) . '" placeholder="Find an image by name or description…" maxlength="100">'
            . '<button type="submit" aria-label="Search images">⌕</button></form>';
    }

    /** @param list<Asset> $assets */
    public function media(array $assets, Session $session, string $search = ''): string
    {
        $csrf = self::csrf($session);
        $body = $this->heading('CONTENT', 'Media', 'Upload and describe images for your pages.');
        $body .= <<<HTML
            <form class="upload-form panel" method="post" action="/admin/media" enctype="multipart/form-data">{$csrf}
            <div><label for="image">Add an image</label><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" required><p class="field-hint">JPEG, PNG, or WebP · Up to 5 MB and 16 megapixels</p></div>
            <div><label for="upload-alt">Describe it <span>for readers using assistive technology</span></label><input id="upload-alt" name="alt" maxlength="300" placeholder="What does the image show?"></div><button class="button primary">Upload image</button></form>
            HTML;
        $body .= self::mediaSearch('/admin/media', $search);
        if ($assets === []) {
            return $body . '<section class="empty"><span class="empty-mark" aria-hidden="true">◫</span><h2>' . ($search === '' ? 'No images yet.' : 'No matching images.')
                . '</h2><p>' . ($search === '' ? 'Images stay private until a published page uses them.' : 'Try another filename or description.') . '</p></section>';
        }
        $body .= '<div class="media-grid">';
        foreach ($assets as $asset) {
            $body .= '<article class="media-card"><a href="/media/' . $asset->id . '" target="_blank" rel="noopener"><img src="/media/' . $asset->id . '" alt="' . self::e($asset->alt)
                . '" loading="lazy"></a><div class="media-info"><h2>' . self::e($asset->name) . '</h2><p>' . $asset->width . ' × ' . $asset->height . ' · '
                . number_format($asset->bytes / 1024) . ' KB</p><form method="post" action="/admin/media/' . $asset->id . '">' . $csrf
                . '<label for="alt-' . $asset->id . '">Image description</label><input id="alt-' . $asset->id . '" name="alt" value="' . self::e($asset->alt) . '" maxlength="300"><button class="small-link">Save description</button></form>'
                . '<div class="media-tools"><button class="link" type="button" data-copy="/media/' . $asset->id . '">Copy address</button><form method="post" action="/admin/media/' . $asset->id . '/delete">' . $csrf
                . '<button class="link">Delete unused</button></form></div><code class="media-address">/media/' . $asset->id . '</code></div></article>';
        }
        return $body . '</div>';
    }

    /** @param list<array<string, mixed>> $tokens */
    public function agents(array $tokens, Session $session, ?string $secret = null): string
    {
        $csrf = self::csrf($session);
        $body = $this->heading('ACCESS', 'Agents', 'Create scoped connections to the content API.');
        if ($secret !== null) {
            $body .= '<div class="token-reveal" role="status"><h2>Your connection is ready.</h2><p>Copy this token now. It is shown only once. Keep it in your agent’s secret storage.</p><code>'
                . self::e($secret) . '</code><button class="button" type="button" data-copy="' . self::e($secret) . '">Copy token</button></div>';
        }
        $body .= '<div class="agent-layout"><section class="panel"><h2>New connection</h2><form method="post" action="/admin/agents">' . $csrf
            . '<label for="agent-name">Name</label><input id="agent-name" name="name" maxlength="80" placeholder="Editorial assistant" required><fieldset><legend>Permissions</legend>'
            . '<label class="check"><input type="checkbox" checked disabled>Read pages and images <span>Included</span></label>';
        foreach (['content:write' => 'Write drafts and restore revisions', 'content:publish' => 'Publish and unpublish pages', 'media:write' => 'Upload and manage images'] as $scope => $label) {
            $body .= '<label class="check"><input type="checkbox" name="scopes[]" value="' . $scope . '"' . ($scope === 'content:write' ? ' checked' : '') . '>' . $label . '</label>';
        }
        $body .= '</fieldset><label for="days">Expires in</label><select id="days" name="days"><option value="30">30 days</option><option value="90" selected>90 days</option><option value="365">1 year</option></select>'
            . '<button class="button primary">Create connection</button></form></section><aside class="agent-note"><span class="large-symbol" aria-hidden="true">✳</span><h2>Set each connection’s permissions.</h2><p>People and agents work on the same pages. Every content change is recorded. Publishing is a separate permission.</p>'
            . '<p>Agents cannot change your settings, password, or other connections.</p><a href="/api/schema" target="_blank" rel="noopener">Read the API schema ↗</a><a href="/api/guide" target="_blank" rel="noopener">Agent quick start ↗</a></aside></div>'
            . '<h2 class="section-title">Connections</h2><div class="connections">';
        if ($tokens === []) {
            $body .= '<p class="muted">No agent connections.</p>';
        }
        foreach ($tokens as $token) {
            $revoked = $token['revoked_at'] !== null;
            $expires = Input::integer($token['expires']);
            $state = $revoked ? 'Revoked' : ($expires < time() ? 'Expired' : 'Active');
            $id = Input::text($token, 'id');
            $scopes = self::e(str_replace(['[', ']', '"'], '', Input::text($token, 'scopes')));
            $body .= '<article class="connection"><div><h3>' . self::e(Input::text($token, 'name')) . ' <span class="connection-state">' . $state . '</span></h3><p>' . $scopes
                . '</p><small>Expires ' . gmdate('j M Y', $expires) . ' · Last used ' . ($token['last_used'] === null ? 'never' : self::date(Input::text($token, 'last_used'))) . '</small></div>';
            if (!$revoked) {
                $body .= '<form method="post" action="/admin/agents/' . $id . '/revoke">' . $csrf . '<button class="button">Revoke access</button></form>';
            }
            $body .= '</article>';
        }
        return $body . '</div>';
    }

    /** @param array{title:string, description:string, theme:string} $settings */
    public function settings(array $settings, Session $session, ?Themes $themes = null): string
    {
        $themeControl = '';
        if ($themes !== null) {
            $themeControl = '<label for="theme">Theme</label><select id="theme" name="theme">';
            foreach ($themes->all as $option) {
                $themeControl .= '<option value="' . self::e($option->id) . '"' . ($themes->selected() === $option->id ? ' selected' : '') . '>' . self::e($option->label) . '</option>';
            }
            $themeControl .= '</select><p class="field-hint">Applies immediately to the public site and previews.</p>';
        }
        return $this->heading('SITE', 'Settings', 'Name, theme, and content export.')
            . '<div class="settings-layout"><form class="panel" method="post" action="/admin/settings">' . self::csrf($session)
            . '<label for="site-title">Site name</label><input id="site-title" name="title" value="' . self::e($settings['title']) . '" maxlength="100" required>'
            . '<label for="description">Description</label><textarea id="description" name="description" rows="4" maxlength="300">' . self::e($settings['description'])
            . '</textarea>' . $themeControl . '<button class="button primary">Save settings</button></form><aside class="panel export-panel"><h2>Export content</h2><p>Download every page, revision, and image in a portable archive. Passwords, sessions, and agent tokens are excluded.</p><form method="post" action="/admin/export">'
            . self::csrf($session) . '<button class="button">Export content ↓</button></form><p class="field-hint">ZIP archive · JSON content + original stored images</p></aside></div>';
    }

    public function login(Session $session, string $error = ''): string
    {
        return $this->document('Sign in', '<main id="main" class="login"><a class="brand" href="/"><img src="/assets/mark.svg" alt="" width="32" height="32">stage<span class="brand-cms">cms</span></a>'
            . '<section><p class="eyebrow">STAGE CMS</p><h1>Sign in</h1><p class="intro">Manage your website.</p>'
            . ($error !== '' ? '<div class="notice error" role="alert">' . self::e($error) . '</div>' : '')
            . '<form method="post" action="/admin/login">' . self::csrf($session) . '<label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required>'
            . '<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>'
            . '<button class="button primary">Sign in <span aria-hidden="true">↗</span></button></form></section><footer>Stage CMS by SKYYWARE</footer></main>', 'login-page');
    }

    public function help(): string
    {
        return $this->heading('DOCUMENTATION', 'Using Stage CMS', 'Editing, publishing, and working with agents.')
            . '<div class="guide prose"><h2>From draft to publication</h2><p>Create a page, choose an address, and save. Drafts are visible only in your workspace. Preview shows the latest saved draft. Publish makes it available to everyone.</p>'
            . '<p>Editing a published page keeps its previous version live until you publish again. Unpublish removes the public page. Archive puts it aside; recovery brings it back as a draft.</p>'
            . '<h2>Page type, language, and theme</h2><p>In Page details, choose a type and select Apply type to load its fields. Move or clear incompatible content before saving. Choose the page’s language, then save a draft. Settings lets you choose from installed themes; this changes the public site immediately. The application defines which types, languages, themes, and public routes are available.</p>'
            . '<h2 id="writing">Writing with Markdown</h2><p>Use plain text with a few simple marks. The toolbar can insert them for you.</p>'
            . '<pre><code>## A section heading' . "\n\n" . '**Bold words** and *a little emphasis*' . "\n\n" . '[A link](https://example.com)' . "\n\n" . '- One idea' . "\n" . '- Another idea' . "\n\n" . '![Image description](/media/your-image-id)</code></pre>'
            . '<p>Raw HTML is removed. Images from your library are private until a published page refers to their address or uses them as a cover. A publication cannot undo downloads already made by readers.</p>'
            . '<h2>Work without overwriting each other</h2><p>Each saved change has a revision number. If a person or agent saves first, your next save stops and keeps your submitted text on screen. Open the latest version in another tab, compare, then transfer your changes.</p>'
            . '<h2>Invite an agent</h2><p>Create a connection, choose permissions, and copy its token once. Start with draft access. Add publication access only when the agent should publish. Revoke access at any time.</p>'
            . '<h2>Back up your content</h2><p>Export from Settings to download your content and images. Revision history is not a backup. Keep regular copies outside this server. The command-line guide explains restoring an export and resetting your password.</p>'
            . '<p><a href="https://github.com/skyyware/stage-cms">Documentation and source ↗</a></p></div>';
    }

    /** @param list<PageSummary> $pages */
    public function publication(array $pages, string $description, int $number = 1, ?bool $hasMore = null): string
    {
        $body = '<header class="publication-header"><a href="/">' . self::e($this->siteTitle) . '</a><span>A PUBLICATION</span></header><main id="main" class="publication-home">'
            . '<p class="eyebrow">PAGES</p><h1>' . self::e($this->siteTitle) . '</h1><p class="publication-intro">' . self::e($description) . '</p><div class="publication-grid">';
        if ($pages === []) {
            $body .= '<p class="muted">No published pages yet.</p>';
        }
        foreach ($pages as $page) {
            $body .= '<article><a href="' . self::e($page->path()) . '">'
                . ($page->cover !== null ? '<img class="story-cover" src="/media/' . $page->cover . '" alt="" loading="lazy">' : '<div class="story-type"><span>PAGE</span><span>↗</span></div>')
                . '<time datetime="' . self::e($page->updatedAt) . '">' . self::date($page->updatedAt) . '</time><h2>' . self::e($page->title)
                . '</h2><p>' . self::e($page->excerpt) . '</p><span class="read-story">Read page ↗</span></a></article>';
        }
        return $this->document($this->siteTitle, $body . '</div>' . self::pagination('/', $number, count($pages), hasMore: $hasMore) . '</main>' . $this->publicFooter(), 'publication');
    }

    public function story(Page $page, bool $preview = false, string $coverAlt = ''): string
    {
        $body = ($preview ? '<div class="preview-bar">PRIVATE PREVIEW · Saved revision ' . $page->version . '<a href="/admin/pages/' . $page->id . '">Back to editing ↗</a></div>' : '')
            . '<header class="publication-header"><a href="/">' . self::e($this->siteTitle) . '</a><span>A PUBLICATION</span></header><main id="main" class="story"><article>'
            . '<header class="story-heading"><p class="eyebrow">' . self::date($page->updatedAt) . '</p><h1>' . self::e($page->draft->title) . '</h1>'
            . ($page->draft->excerpt !== '' ? '<p class="publication-intro">' . self::e($page->draft->excerpt) . '</p>' : '') . '</header>'
            . ($page->draft->cover !== null ? '<img class="story-hero" src="/media/' . $page->draft->cover . '" alt="' . self::e($coverAlt) . '">' : '')
            . '<div class="prose">' . $this->markdown->render($page->draft->body) . '</div></article><a class="back" href="/">← All stories</a></main>';
        return $this->document($page->draft->title, $body . $this->publicFooter(), 'publication');
    }

    public function problem(string $message, int $status): string
    {
        return $this->document('Something needs attention', '<main id="main" class="problem"><a class="brand" href="/">stage<span class="brand-cms">cms</span></a><p class="eyebrow">'
            . $status . '</p><h1>' . self::e($message) . '</h1><a class="button" href="/admin/pages">Back to workspace</a><a class="small-link" href="/">Go to the publication ↗</a></main>');
    }

    private function publicFooter(): string
    {
        return '<footer class="publication-footer"><span>' . self::e($this->siteTitle) . '</span><a href="https://github.com/skyyware/stage-cms">Made with Stage CMS ↗</a></footer>';
    }

    private static function date(string $date): string
    {
        $time = strtotime($date);
        return $time === false ? self::e($date) : gmdate('j M Y', $time);
    }

    /** @param array<string, string> $query */
    public static function pagination(string $path, int $page, int $count, array $query = [], ?bool $hasMore = null): string
    {
        $body = '';
        if ($page > 1) {
            $body .= '<a href="' . self::e($path . '?' . http_build_query(array_merge($query, ['page' => $page - 1]))) . '">← Previous</a>';
        }
        if ($hasMore ?? $count === 50) {
            $body .= '<a href="' . self::e($path . '?' . http_build_query(array_merge($query, ['page' => $page + 1]))) . '">Next →</a>';
        }
        return $body === '' ? '' : '<nav class="pagination" aria-label="Pagination">' . $body . '</nav>';
    }
}
