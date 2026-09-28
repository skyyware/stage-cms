<?php
declare(strict_types=1);

namespace StageCms\Presentation;

use StageCms\Content\Page;
use StageCms\Identity\Session;
use StageCms\Input;
use StageCms\Media\Asset;

final readonly class View
{
    public function __construct(private string $siteTitle, private Markdown $markdown = new Markdown()) {}

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
            <div class="sidebar-bottom"><a href="/" target="_blank" rel="noopener">View publication <span aria-hidden="true">↗</span></a>
            <a href="/admin/settings">Settings</a><form method="post" action="/admin/logout">{$csrf}<button class="link" type="submit">Sign out</button></form>
            <span class="version">STAGE CMS / 0.2</span></div></aside>
            <main id="main" class="main"><div class="topline"><span>WORKSPACE <span class="slash">/</span> {$site}</span><a href="/admin/help">A little guidance <span aria-hidden="true">↗</span></a></div>
            {$alerts}{$body}<footer class="app-footer"><span>A place for your next idea.</span><span>Made with Stage</span></footer></main></div>
            HTML);
    }

    public function heading(string $eyebrow, string $title, string $description, string $action = ''): string
    {
        return '<header class="heading"><div><p class="eyebrow">' . self::e($eyebrow) . '</p><h1>' . self::e($title)
            . '</h1><p class="intro">' . self::e($description) . '</p></div>' . $action . '</header>';
    }

    /** @param list<Page> $pages */
    public function pages(array $pages, string $filter, string $search): string
    {
        $body = $this->heading('YOUR PUBLICATION', 'Room for good ideas.', 'Write, refine, and publish. One page at a time.',
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
                . ($search !== '' ? 'No pages found.' : 'A little space to begin.')
                . '</h2><p>' . ($search !== '' ? 'Try another title or address.' : 'Your words have a home here. Start with something worth sharing.')
                . '</p><a class="button" href="/admin/pages/new">Write a page <span aria-hidden="true">↗</span></a></section>';
        }
        $body .= '<div class="page-table"><div class="table-labels"><span>PAGE</span><span>STATUS</span><span>LAST EDITED</span><span></span></div>';
        foreach ($pages as $page) {
            $body .= '<a class="page-row" href="/admin/pages/' . $page->id . '"><div class="page-title"><span class="page-symbol" aria-hidden="true">▤</span><span><strong>'
                . self::e($page->draft->title) . '</strong><small>/' . self::e($page->draft->slug) . '</small></span></div>'
                . self::badge($page) . '<time datetime="' . self::e($page->updatedAt) . '">' . self::date($page->updatedAt)
                . '</time><span class="row-arrow" aria-hidden="true">↗</span></a>';
        }
        return $body . '</div><p class="collection-note">' . count($pages) . ' ' . (count($pages) === 1 ? 'page' : 'pages') . ' in this view · Every saved change has a history.</p>';
    }

    public static function badge(Page $page): string
    {
        return '<span class="badge ' . $page->status() . '"><span aria-hidden="true"></span>'
            . match ($page->status()) {'draft' => 'Draft', 'changed' => 'Unpublished edits', 'published' => 'Published', default => 'Archived'} . '</span>';
    }

    /**
     * @param array<string, mixed> $values
     * @param list<Asset> $media
     */
    public function editor(?Page $page, array $values, array $media, Session $session, bool $unsaved = false): string
    {
        $id = $page->id ?? 'new';
        $action = '/admin/pages/' . $id;
        $version = Input::text($values, 'expected_version', (string) ($page->version ?? 1));
        $csrf = self::csrf($session);
        $body = '<div class="editor-heading"><a class="back" href="/admin/pages">← All pages</a><div>'
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
        $preview = $page === null ? '<span class="muted">Save to preview</span>' : '<a class="button quiet" href="' . $action . '/preview" target="_blank" rel="noopener">Preview <span aria-hidden="true">↗</span></a>';
        $publish = $page?->publishedVersion !== null ? 'Publish changes' : 'Publish page';
        $pending = $unsaved ? 'true' : 'false';
        $body .= <<<HTML
            <form method="post" action="{$action}" class="editor-form" data-editor data-unsaved="{$pending}">
            {$csrf}<input type="hidden" name="expected_version" value="{$version}">
            <div class="editor-layout"><section class="writing-sheet"><label class="sr-only" for="title">Page title</label>
            <textarea class="title-input" id="title" name="title" rows="2" maxlength="200" placeholder="A title worth opening" required>{$title}</textarea>
            <div class="format-bar"><span>MARKDOWN</span><div><button type="button" data-format="bold" aria-label="Bold text"><b>B</b></button><button type="button" data-format="italic" aria-label="Italic text"><i>I</i></button><button type="button" data-format="heading" aria-label="Add heading">H₂</button><button type="button" data-format="link" aria-label="Add link">↗</button><button type="button" data-format="list" aria-label="Add list">☷</button></div><a href="/admin/help#writing" target="_blank" rel="noopener" aria-label="Writing guide">?</a></div>
            <label class="sr-only" for="body">Page content</label><textarea class="body-input" id="body" name="body" placeholder="Start with a thought. Give it room to grow." maxlength="200000" data-body>{$content}</textarea>
            <div class="writing-bottom"><span data-word-count>Write at your own pace.</span><span data-save-state>No unsaved changes</span></div></section>
            <aside class="page-details"><h2>Page details</h2><label for="slug">Address</label><div class="slug-input"><span>/</span><input id="slug" name="slug" value="{$slug}" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="120" placeholder="your-page" required data-slug></div><p class="field-hint">Lowercase letters, numbers, and hyphens.</p>
            <label for="excerpt">Summary <span>optional</span></label><textarea id="excerpt" name="excerpt" rows="4" maxlength="500" placeholder="A few words to invite the reader in.">{$excerpt}</textarea>
            <label for="cover">Cover image <span>optional</span></label><select id="cover" name="cover">{$options}</select><a class="small-link" href="/admin/media" target="_blank" rel="noopener">Open media library ↗</a>
            <div class="detail-note"><span class="note-dot"></span><p>Saving keeps your work private. Publish when it feels ready.</p></div>
            </aside></div><div class="editor-actions"><div>{$preview}</div><div><button class="button" name="intent" value="save" data-save>Save draft</button><button class="button primary" name="intent" value="publish">{$publish} <span aria-hidden="true">↗</span></button></div></div></form>
            HTML;
        if ($page !== null) {
            $body .= '<div class="page-secondary"><a href="' . $action . '/history">Version history <span aria-hidden="true">↗</span></a><div>';
            if ($page->publishedVersion !== null) {
                $body .= '<form method="post" action="' . $action . '/unpublish">' . $csrf . '<input type="hidden" name="expected_version" value="' . $page->version . '"><button class="link">Unpublish</button></form>';
            }
            $body .= '<form method="post" action="' . $action . '/archive">' . $csrf . '<input type="hidden" name="expected_version" value="' . $page->version
                . '"><button class="link">Move to archive</button></form></div></div>';
        }
        return $body;
    }

    /** @param list<Page> $revisions */
    public function history(Page $page, array $revisions, Session $session): string
    {
        $body = '<a class="back" href="/admin/pages/' . $page->id . '">← Back to page</a>'
            . $this->heading('NOTHING LOST', 'A history of the work.', $page->draft->title);
        $body .= '<p class="intro">Restore any version as a new draft. Your published page stays as it is.</p><div class="history-list">';
        foreach ($revisions as $revision) {
            $body .= '<article class="history-item"><div><span class="eyebrow">REVISION ' . $revision->version . ' · ' . self::e(strtoupper($revision->action))
                . '</span><h2>' . self::e($revision->draft->title) . '</h2><p>' . self::date($revision->updatedAt) . ' · '
                . (str_starts_with($revision->actor, 'agent:') ? 'Agent' : 'Owner') . '</p><details><summary>Read this version</summary><div class="prose">'
                . $this->markdown->render($revision->draft->body) . '</div></details></div>';
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

    /** @param list<Asset> $assets */
    public function media(array $assets, Session $session): string
    {
        $csrf = self::csrf($session);
        $body = $this->heading('THE VISUAL SIDE', 'Worth a thousand words.', 'A home for the images that make your pages yours.');
        $body .= <<<HTML
            <form class="upload-form panel" method="post" action="/admin/media" enctype="multipart/form-data">{$csrf}
            <div><label for="image">Add an image</label><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" required><p class="field-hint">JPEG, PNG, or WebP · Up to 5 MB and 16 megapixels</p></div>
            <div><label for="upload-alt">Describe it <span>for readers using assistive technology</span></label><input id="upload-alt" name="alt" maxlength="300" placeholder="What does the image show?"></div><button class="button primary">Upload image</button></form>
            HTML;
        if ($assets === []) {
            return $body . '<section class="empty"><span class="empty-mark" aria-hidden="true">◫</span><h2>A fresh canvas.</h2><p>Images stay private until a published page uses them.</p></section>';
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
        $body = $this->heading('GOOD COMPANY', 'Your tools, invited in.', 'Give an agent a clear task and just the access it needs.');
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
            . '<button class="button primary">Create connection</button></form></section><aside class="agent-note"><span class="large-symbol" aria-hidden="true">✳</span><h2>A shared workspace.<br>A clear boundary.</h2><p>People and agents work on the same pages. Every content change is recorded. Publishing is a separate permission.</p>'
            . '<p>Agents cannot change your settings, password, or other connections.</p><a href="/api/schema" target="_blank" rel="noopener">Read the API schema ↗</a><a href="/llms.txt" target="_blank" rel="noopener">Agent quick start ↗</a></aside></div>'
            . '<h2 class="section-title">Connections</h2><div class="connections">';
        if ($tokens === []) {
            $body .= '<p class="muted">No connections yet. Your workspace is yours.</p>';
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

    /** @param array{title:string, description:string} $settings */
    public function settings(array $settings, Session $session): string
    {
        return $this->heading('MAKE IT YOURS', 'The essentials.', 'A name, a purpose, and content you can take with you.')
            . '<div class="settings-layout"><form class="panel" method="post" action="/admin/settings">' . self::csrf($session)
            . '<label for="site-title">Publication name</label><input id="site-title" name="title" value="' . self::e($settings['title']) . '" maxlength="100" required>'
            . '<label for="description">Description</label><textarea id="description" name="description" rows="4" maxlength="300">' . self::e($settings['description'])
            . '</textarea><button class="button primary">Save settings</button></form><aside class="panel export-panel"><h2>Your content is yours.</h2><p>Download every page, revision, and image in a portable archive. Passwords, sessions, and agent tokens are excluded.</p><form method="post" action="/admin/export">'
            . self::csrf($session) . '<button class="button">Export publication ↓</button></form><p class="field-hint">ZIP archive · JSON content + original stored images</p></aside></div>';
    }

    public function login(Session $session, string $error = ''): string
    {
        return $this->document('Welcome back', '<main id="main" class="login"><a class="brand" href="/"><img src="/assets/mark.svg" alt="" width="32" height="32">stage<span class="brand-cms">cms</span></a>'
            . '<section><p class="eyebrow">A SPACE FOR YOUR WORK</p><h1>Welcome back.</h1><p class="intro">Good things start with a little focus.</p>'
            . ($error !== '' ? '<div class="notice error" role="alert">' . self::e($error) . '</div>' : '')
            . '<form method="post" action="/admin/login">' . self::csrf($session) . '<label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required>'
            . '<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>'
            . '<button class="button primary">Enter your workspace <span aria-hidden="true">↗</span></button></form></section><footer>Words, images, and a little possibility.</footer></main>', 'login-page');
    }

    public function help(): string
    {
        return $this->heading('A LITTLE GUIDANCE', 'Keep it simple.', 'Everything you need for a considered publication.')
            . '<div class="guide prose"><h2>From draft to publication</h2><p>Create a page, choose an address, and save. Drafts are visible only in your workspace. Preview shows the latest saved draft. Publish makes it available to everyone.</p>'
            . '<p>Editing a published page keeps its previous version live until you publish again. Unpublish removes the public page. Archive puts it aside; recovery brings it back as a draft.</p>'
            . '<h2 id="writing">Writing with Markdown</h2><p>Use plain text with a few simple marks. The toolbar can insert them for you.</p>'
            . '<pre><code>## A section heading' . "\n\n" . '**Bold words** and *a little emphasis*' . "\n\n" . '[A link](https://example.com)' . "\n\n" . '- One idea' . "\n" . '- Another idea' . "\n\n" . '![Image description](/media/your-image-id)</code></pre>'
            . '<p>Raw HTML is removed. Images from your library are private until a published page refers to their address or uses them as a cover. A publication cannot undo downloads already made by readers.</p>'
            . '<h2>Work without overwriting each other</h2><p>Each saved change has a revision number. If a person or agent saves first, your next save stops and keeps your submitted text on screen. Open the latest version in another tab, compare, then transfer your changes.</p>'
            . '<h2>Invite an agent</h2><p>Create a connection, choose permissions, and copy its token once. Start with draft access. Add publication access only when the agent should publish. Revoke access at any time.</p>'
            . '<h2>Take care of the work</h2><p>Export from Settings to download your content and images. Revision history is not a backup. Keep regular copies outside this server. The command-line guide explains restoring an export and resetting your password.</p>'
            . '<p><a href="https://github.com/skyyware/stage-cms">Documentation and source ↗</a></p></div>';
    }

    /** @param list<Page> $pages */
    public function publication(array $pages, string $description, int $number = 1): string
    {
        $body = '<header class="publication-header"><a href="/">' . self::e($this->siteTitle) . '</a><span>A PUBLICATION</span></header><main id="main" class="publication-home">'
            . '<p class="eyebrow">NOTES, IDEAS & EVERYTHING BETWEEN</p><h1>' . self::e($this->siteTitle) . '</h1><p class="publication-intro">' . self::e($description) . '</p><div class="publication-grid">';
        if ($pages === []) {
            $body .= '<p class="muted">Something worth reading is on its way.</p>';
        }
        foreach ($pages as $page) {
            $body .= '<article><a href="/' . self::e($page->draft->slug) . '">'
                . ($page->draft->cover !== null ? '<img class="story-cover" src="/media/' . $page->draft->cover . '" alt="" loading="lazy">' : '<div class="story-type"><span>FIELD NOTES</span><span>↗</span></div>')
                . '<time datetime="' . self::e($page->updatedAt) . '">' . self::date($page->updatedAt) . '</time><h2>' . self::e($page->draft->title)
                . '</h2><p>' . self::e($page->draft->excerpt) . '</p><span class="read-story">Read the story ↗</span></a></article>';
        }
        return $this->document($this->siteTitle, $body . '</div>' . self::pagination('/', $number, count($pages)) . '</main>' . $this->publicFooter(), 'publication');
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
    public static function pagination(string $path, int $page, int $count, array $query = []): string
    {
        $body = '';
        if ($page > 1) {
            $body .= '<a href="' . self::e($path . '?' . http_build_query(array_merge($query, ['page' => $page - 1]))) . '">← Previous</a>';
        }
        if ($count === 50) {
            $body .= '<a href="' . self::e($path . '?' . http_build_query(array_merge($query, ['page' => $page + 1]))) . '">Next →</a>';
        }
        return $body === '' ? '' : '<nav class="pagination" aria-label="Pagination">' . $body . '</nav>';
    }
}
