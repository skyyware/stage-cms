<?php
declare(strict_types=1);

namespace StageCms\Content;

use Stage\Security\Caller;
use StageCms\Failure;
use StageCms\Infrastructure\Database;
use StageCms\Input;
use StageCms\Listing;

final readonly class Pages
{
    /** @param array<string, string> $locales */
    public function __construct(private Database $db, private PageTypes $types = new PageTypes(), private array $locales = [], private ?PublicationRule $publicationRule = null) {}

    /** @return list<Page> */
    public function list(Caller $caller, string $status = 'all', string $search = '', int $page = 1): array
    {
        $caller->require('content:read');
        $rows = $this->db->all(self::select(includeBody: true) . ' WHERE ' . self::filter($status) . ' AND (r.title LIKE :q OR r.slug LIKE :q) ORDER BY r.created_at DESC, p.id LIMIT 50 OFFSET :offset',
            ['q' => '%' . mb_substr($search, 0, 100) . '%', 'offset' => Listing::offset($page)]);
        return array_map(Page::fromRow(...), $rows);
    }

    private static function select(bool $published = false, bool $includeBody = false): string
    {
        $version = $published ? 'p.published_version' : 'p.version';
        return 'SELECT p.id, ' . $version . ' AS version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.cover, r.type, r.locale, r.actor, r.action, r.created_at'
            . ($includeBody ? ', r.body, r.fields' : '') . ', p.translation_group, b.name AS binding, b.path AS public_path FROM pages p JOIN revisions r ON r.page_id = p.id AND r.version = ' . $version . ' LEFT JOIN page_bindings b ON b.page_id = p.id';
    }

    private static function filter(string $status): string
    {
        return match ($status) {
            'archived' => 'p.archived = 1',
            'draft' => 'p.archived = 0 AND (p.published_version IS NULL OR p.version != p.published_version)',
            'published' => 'p.archived = 0 AND p.published_version IS NOT NULL',
            'all' => 'p.archived = 0',
            default => throw new Failure(422, 'invalid_status', 'Choose all, draft, published, or archived.'),
        };
    }

    /** @return Listing<PageSummary> */
    public function browse(Caller $caller, string $status = 'all', string $search = '', int $page = 1, bool $includeBody = false): Listing
    {
        $caller->require('content:read');
        $select = self::select(includeBody: $includeBody);
        $rows = $this->db->all($select . ' WHERE ' . self::filter($status) . ' AND (r.title LIKE :q OR r.slug LIKE :q) ORDER BY r.created_at DESC, p.id LIMIT 51 OFFSET :offset',
            ['q' => '%' . mb_substr($search, 0, 100) . '%', 'offset' => Listing::offset($page)]);
        return new Listing(array_map($includeBody ? Page::fromRow(...) : PageSummary::fromRow(...), $rows), $page);
    }

    /** @return Listing<PageSummary> */
    public function revisions(Caller $caller, string $id, int $page = 1, bool $includeBody = false): Listing
    {
        $caller->require('content:read');
        $this->assertExists($id);
        $rows = $this->db->all('SELECT p.id, r.version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.cover, r.type, r.locale, r.actor, r.action, r.created_at'
            . ($includeBody ? ', r.body, r.fields' : '') . ', p.translation_group, b.name AS binding, b.path AS public_path FROM revisions r JOIN pages p ON p.id = r.page_id LEFT JOIN page_bindings b ON b.page_id = p.id WHERE p.id = :id ORDER BY r.version DESC LIMIT 51 OFFSET :offset',
            ['id' => $id, 'offset' => Listing::offset($page)]);
        return new Listing(array_map($includeBody ? Page::fromRow(...) : PageSummary::fromRow(...), $rows), $page);
    }

    public function revision(Caller $caller, string $id, int $version): Page
    {
        $caller->require('content:read');
        $row = $this->db->one('SELECT p.id, r.version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.body, r.fields, r.cover, r.type, r.locale, r.actor, r.action, r.created_at, p.translation_group, b.name AS binding, b.path AS public_path FROM revisions r JOIN pages p ON p.id = r.page_id LEFT JOIN page_bindings b ON b.page_id = p.id WHERE p.id = :id AND r.version = :version',
            ['id' => $id, 'version' => $version]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'That revision does not exist.');
        }
        return Page::fromRow($row);
    }

    /** @return Listing<PageSummary> */
    public function publication(int $page = 1): Listing
    {
        $rows = $this->db->all(self::select(published: true) . ' WHERE p.archived = 0 ORDER BY r.created_at DESC, p.id LIMIT 51 OFFSET :offset', ['offset' => Listing::offset($page)]);
        return new Listing(array_map(PageSummary::fromRow(...), $rows), $page);
    }

    public function get(Caller $caller, string $id): Page
    {
        $caller->require('content:read');
        return $this->load($id);
    }

    /** @return list<Page> */
    public function history(Caller $caller, string $id, int $page = 1): array
    {
        $caller->require('content:read');
        $this->load($id);
        return array_map(Page::fromRow(...), $this->db->all(
            'SELECT p.id, r.version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.body, r.fields, r.cover, r.type, r.locale, r.actor, r.action, r.created_at, p.translation_group, b.name AS binding, b.path AS public_path FROM revisions r JOIN pages p ON p.id = r.page_id LEFT JOIN page_bindings b ON b.page_id = p.id WHERE p.id = :id ORDER BY r.version DESC LIMIT 50 OFFSET :offset',
            ['id' => $id, 'offset' => Listing::offset($page)]));
    }

    public function create(Caller $caller, Draft $draft, bool $publish = false): Page
    {
        $caller->require('content:write');
        if ($publish) {
            $caller->require('content:publish');
        }
        return $this->db->transaction(function () use ($caller, $draft, $publish): Page {
            return $this->insert($caller, $draft, $publish);
        });
    }

    private function insert(Caller $caller, Draft $draft, bool $publish = false, ?string $group = null): Page
    {
        $this->assertAvailable($draft, '');
        $id = bin2hex(random_bytes(16));
        if ($publish) {
            $this->validatePublication($id, 1, $draft);
        }
        $this->db->execute('INSERT INTO pages (id, slug, version, created_at, translation_group, locale) VALUES (:id, :slug, 1, :created, :group, :locale)', [
            'id' => $id, 'slug' => $draft->slug, 'created' => gmdate('c'), 'group' => $group ?? $id, 'locale' => $draft->locale,
        ]);
        $this->record($id, 1, $draft, $caller, $publish ? 'published' : 'created');
        if ($publish) {
            $this->db->execute('UPDATE pages SET published_version = 1, published_slug = :slug WHERE id = :id', ['id' => $id, 'slug' => $draft->slug]);
        }
        return $this->load($id);
    }

    /** @return list<PageSummary> */
    public function translations(Caller $caller, string $id): array
    {
        $caller->require('content:read');
        $page = $this->load($id);
        return array_map(PageSummary::fromRow(...), $this->db->all(self::select() . ' WHERE p.translation_group = :group ORDER BY p.locale', ['group' => $page->translationGroup]));
    }

    public function translate(Caller $caller, string $id, string $locale, string $slug, int $expectedVersion): Page
    {
        $caller->require('content:read');
        $caller->require('content:write');
        return $this->db->transaction(function () use ($caller, $id, $locale, $slug, $expectedVersion): Page {
            $page = $this->load($id);
            if ($page->version !== $expectedVersion) {
                throw new Failure(409, 'stale_revision', 'The source page changed. Open its current version before creating a translation.');
            }
            if ($page->archived) {
                throw new Failure(409, 'archived', 'Recover the source page before creating a translation.');
            }
            if ($this->db->one('SELECT id FROM pages WHERE translation_group = :group AND locale = :locale', ['group' => $page->translationGroup, 'locale' => $locale]) !== null) {
                throw new Failure(409, 'translation_exists', 'This page already has a version in that language, including its archive.');
            }
            $draft = new Draft($page->title, $slug, $page->excerpt, $page->draft->body, $page->cover, $page->type, $locale, $page->draft->fields);
            return $this->insert($caller, $draft, group: $page->translationGroup);
        });
    }

    public function bind(Caller $caller, string $name, string $id, string $path): Page
    {
        $caller->require('admin');
        return $this->db->transaction(function () use ($name, $id, $path): Page {
            $page = $this->load($id);
            $binding = new PageBinding($name, $page->locale, $id, $path, $page->type);
            if ($page->publishedVersion !== null) {
                $published = $this->publishedById($id);
                if ($published->type !== $page->type || $published->locale !== $page->locale) {
                    throw new Failure(409, 'binding_mismatch', 'Publish the intended type and language before assigning this public route.');
                }
            }
            $existing = $this->db->one('SELECT * FROM page_bindings WHERE (name = :name AND locale = :locale) OR page_id = :id OR path = :path', [
                'name' => $name, 'locale' => $binding->locale, 'id' => $id, 'path' => $path,
            ]);
            if ($existing !== null) {
                if ($existing['name'] === $name && $existing['locale'] === $binding->locale && $existing['page_id'] === $id && $existing['path'] === $path && $existing['type'] === $binding->type) {
                    return $page;
                }
                throw new Failure(409, 'binding_taken', 'This name, language, page, or public path is already assigned.');
            }
            $slug = substr($path, 1);
            if ($this->db->one('SELECT id FROM pages WHERE id != :id AND (slug = :slug OR published_slug = :slug)', ['id' => $id, 'slug' => $slug]) !== null
                || $this->db->one('SELECT page_id FROM page_redirects WHERE slug = :slug AND page_id != :id', ['id' => $id, 'slug' => $slug]) !== null) {
                throw new Failure(409, 'binding_taken', 'Another page already uses this public path.');
            }
            $target = $this->db->one('SELECT p.translation_group, b.type FROM page_bindings b JOIN pages p ON p.id = b.page_id WHERE b.name = :name LIMIT 1', ['name' => $name]);
            if ($this->db->one('SELECT b.name FROM page_bindings b JOIN pages p ON p.id = b.page_id WHERE p.translation_group = :source AND b.name != :name LIMIT 1', ['source' => $page->translationGroup, 'name' => $name]) !== null) {
                throw new Failure(409, 'binding_taken', 'These translations already belong to another named page.');
            }
            if ($target !== null) {
                $group = Input::text($target, 'translation_group');
                if ($target['type'] !== $page->type) {
                    throw new Failure(422, 'binding_type', 'Translations of this named page must use the same page type.');
                }
                if ($group !== $page->translationGroup) {
                    if ($this->db->one('SELECT a.id FROM pages a JOIN pages b ON a.locale = b.locale WHERE a.translation_group = :source AND b.translation_group = :target LIMIT 1', ['source' => $page->translationGroup, 'target' => $group]) !== null) {
                        throw new Failure(409, 'translation_exists', 'These page groups have conflicting languages or named pages.');
                    }
                    $this->db->execute('UPDATE pages SET translation_group = :target WHERE translation_group = :source', ['source' => $page->translationGroup, 'target' => $group]);
                }
            }
            $this->db->execute('INSERT INTO page_bindings (name, locale, page_id, path, type) VALUES (:name, :locale, :id, :path, :type)', [
                'name' => $name, 'locale' => $binding->locale, 'id' => $id, 'path' => $path, 'type' => $binding->type,
            ]);
            return $this->load($id);
        });
    }

    public function bound(Caller $caller, string $name, string $locale): ?Page
    {
        $caller->require('content:read');
        $row = $this->db->one(self::select(includeBody: true) . ' WHERE b.name = :name AND b.locale = :locale', ['name' => $name, 'locale' => $locale]);
        return $row === null ? null : Page::fromRow($row);
    }

    public function publishedBinding(string $name, string $locale): Page
    {
        $row = $this->db->one(self::select(published: true, includeBody: true) . ' WHERE p.archived = 0 AND b.name = :name AND b.locale = :locale', ['name' => $name, 'locale' => $locale]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'This page is not published.');
        }
        return Page::fromRow($row);
    }

    public function resolvePath(string $path): Page
    {
        $row = $this->db->one(self::select(published: true, includeBody: true) . ' WHERE p.archived = 0 AND b.path = :path', ['path' => $path]);
        if ($row !== null) {
            return Page::fromRow($row);
        }
        if (!preg_match('~^/[a-z0-9]+(?:-[a-z0-9]+)*$~D', $path)) {
            throw new Failure(404, 'not_found', 'This page is not published.');
        }
        $slug = substr($path, 1);
        $row = $this->db->one(self::select(published: true, includeBody: true) . ' WHERE p.archived = 0 AND (p.published_slug = :slug OR p.id IN (SELECT page_id FROM page_redirects WHERE slug = :slug))', ['slug' => $slug]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'This page is not published.');
        }
        return Page::fromRow($row);
    }

    public function save(Caller $caller, string $id, Draft $draft, int $expectedVersion, bool $publish = false): Page
    {
        return $this->change($caller, $id, $expectedVersion, $publish ? 'published' : 'saved', $draft);
    }

    public function publish(Caller $caller, string $id, int $expectedVersion): Page
    {
        return $this->change($caller, $id, $expectedVersion, 'published');
    }

    public function unpublish(Caller $caller, string $id, int $expectedVersion): Page
    {
        return $this->change($caller, $id, $expectedVersion, 'unpublished');
    }

    public function archive(Caller $caller, string $id, int $expectedVersion): Page
    {
        return $this->change($caller, $id, $expectedVersion, 'archived');
    }

    public function recover(Caller $caller, string $id, int $expectedVersion): Page
    {
        return $this->change($caller, $id, $expectedVersion, 'recovered');
    }

    public function restore(Caller $caller, string $id, int $version, int $expectedVersion): Page
    {
        $caller->require('content:read');
        $row = $this->db->one('SELECT title, slug, excerpt, body, cover, type, locale, fields FROM revisions WHERE page_id = :id AND version = :version', ['id' => $id, 'version' => $version]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'That revision does not exist.');
        }
        return $this->change($caller, $id, $expectedVersion, 'restored', Draft::fromRow($row));
    }

    /** @return list<Page> */
    public function published(int $page = 1): array
    {
        return array_map(Page::fromRow(...), $this->db->all(self::select(published: true, includeBody: true) . ' WHERE p.archived = 0 ORDER BY r.created_at DESC, p.id LIMIT 50 OFFSET :offset', ['offset' => Listing::offset($page)]));
    }

    public function publishedPage(string $slug): Page
    {
        $row = $this->db->one(self::select(published: true, includeBody: true) . ' WHERE p.archived = 0 AND p.published_slug = :slug', ['slug' => $slug]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'This page is not published.');
        }
        return Page::fromRow($row);
    }

    public function publishedById(string $id): Page
    {
        $row = $this->db->one(self::select(published: true, includeBody: true) . ' WHERE p.archived = 0 AND p.id = :id', ['id' => $id]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'This page is not published.');
        }
        return Page::fromRow($row);
    }

    private function change(Caller $caller, string $id, int $expectedVersion, string $action, ?Draft $draft = null): Page
    {
        $caller->require('content:write');
        return $this->db->transaction(function () use ($caller, $id, $expectedVersion, $action, $draft): Page {
            $page = $this->load($id);
            if ($page->version !== $expectedVersion) {
                throw new Failure(409, 'stale_revision', 'This page changed since you opened it. Your text is preserved here. Open the latest revision before saving again.');
            }
            if ($page->archived && $action !== 'recovered') {
                throw new Failure(409, 'archived', 'Restore this page from the archive before changing it.');
            }
            if ($action === 'recovered' && !$page->archived) {
                throw new Failure(409, 'not_archived', 'This page is not archived.');
            }
            if (in_array($action, ['published', 'unpublished'], true) || ($action === 'archived' && $page->publishedVersion !== null)) {
                $caller->require('content:publish');
            }
            $draft ??= $page->draft;
            if ($draft->locale !== $page->locale) {
                throw new Failure(422, 'fixed_language', 'Open or create a translation to edit another language.', ['locale' => 'A page keeps its language.']);
            }
            $binding = $this->db->one('SELECT type FROM page_bindings WHERE page_id = :id', ['id' => $id]);
            if ($binding !== null && $binding['type'] !== $draft->type) {
                throw new Failure(422, 'fixed_page_type', 'This named page keeps the type required by its public route.', ['type' => 'The application assigns this page type.']);
            }
            $this->assertAvailable($draft, $id);
            $version = $page->version + 1;
            if ($action === 'published') {
                $this->validatePublication($id, $version, $draft);
            }
            $this->record($id, $version, $draft, $caller, $action);
            $this->db->execute('UPDATE pages SET slug = :slug, version = :version, archived = :archived WHERE id = :id', [
                'id' => $id, 'slug' => $draft->slug, 'version' => $version, 'archived' => $action === 'archived' ? 1 : 0,
            ]);
            if ($action === 'published') {
                $previous = $this->db->one('SELECT published_slug FROM pages WHERE id = :id', ['id' => $id]);
                $oldSlug = $previous['published_slug'] ?? null;
                $this->db->execute('DELETE FROM page_redirects WHERE slug = :slug AND page_id = :id', ['slug' => $draft->slug, 'id' => $id]);
                if (is_string($oldSlug) && $oldSlug !== $draft->slug) {
                    $this->db->execute('INSERT INTO page_redirects (slug, page_id) VALUES (:slug, :id) ON CONFLICT(slug) DO UPDATE SET page_id = excluded.page_id', ['slug' => $oldSlug, 'id' => $id]);
                }
                $this->db->execute('UPDATE pages SET published_version = :version, published_slug = :slug WHERE id = :id',
                    ['id' => $id, 'version' => $version, 'slug' => $draft->slug]);
            } elseif (in_array($action, ['unpublished', 'archived', 'recovered'], true)) {
                $this->db->execute('INSERT INTO page_redirects (slug, page_id) SELECT published_slug, id FROM pages WHERE id = :id AND published_slug IS NOT NULL ON CONFLICT(slug) DO UPDATE SET page_id = excluded.page_id', ['id' => $id]);
                $this->db->execute('UPDATE pages SET published_version = NULL, published_slug = NULL WHERE id = :id', ['id' => $id]);
            }
            return $this->load($id);
        });
    }

    private function load(string $id): Page
    {
        $row = $this->db->one(self::select(includeBody: true) . ' WHERE p.id = :id', ['id' => $id]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'That page does not exist.');
        }
        return Page::fromRow($row);
    }

    private function validatePublication(string $id, int $version, Draft $draft): void
    {
        $this->types->get($draft->type)->validatePublication($draft);
        $this->publicationRule?->validate(new PublicationCandidate($id, $version, $draft));
    }

    private function assertExists(string $id): void
    {
        if ($this->db->one('SELECT id FROM pages WHERE id = :id', ['id' => $id]) === null) {
            throw new Failure(404, 'not_found', 'That page does not exist.');
        }
    }

    private function assertAvailable(Draft $draft, string $id): void
    {
        $this->types->validate($draft);
        if ($this->locales !== [] && !isset($this->locales[$draft->locale])) {
            throw new Failure(422, 'unsupported_language', 'Choose a language supported by this site.');
        }
        if ($this->db->one('SELECT id FROM pages WHERE id != :id AND (slug = :slug OR published_slug = :slug)', ['id' => $id, 'slug' => $draft->slug]) !== null) {
            throw new Failure(409, 'slug_taken', 'Another page already uses this address, including pages in the archive.');
        }
        if ($this->db->one('SELECT page_id FROM page_redirects WHERE slug = :slug AND page_id != :id', ['slug' => $draft->slug, 'id' => $id]) !== null
            || $this->db->one('SELECT page_id FROM page_bindings WHERE path = :path AND page_id != :id', ['path' => '/' . $draft->slug, 'id' => $id]) !== null) {
            throw new Failure(409, 'slug_taken', 'A published route or redirect already uses this address.');
        }
        foreach ($this->mediaIds($draft) as $mediaId) {
            if ($this->db->one('SELECT id FROM media WHERE id = :id', ['id' => $mediaId]) === null) {
                throw new Failure(422, 'missing_media', 'An image in this page is not in the media library.');
            }
        }
    }

    private function record(string $id, int $version, Draft $draft, Caller $caller, string $action): void
    {
        $this->db->execute('INSERT INTO revisions (page_id, version, title, slug, excerpt, body, cover, type, locale, fields, action, actor, created_at) VALUES (:id, :version, :title, :slug, :excerpt, :body, :cover, :type, :locale, :fields, :action, :actor, :created)',
            array_merge($draft->data(), ['fields' => json_encode($draft->fields, JSON_THROW_ON_ERROR), 'id' => $id, 'version' => $version, 'action' => $action, 'actor' => $caller->id ?? 'unknown', 'created' => gmdate('c')]));
        foreach ($this->mediaIds($draft) as $mediaId) {
            $this->db->execute('INSERT INTO revision_media VALUES (:id, :version, :media)', ['id' => $id, 'version' => $version, 'media' => $mediaId]);
        }
    }

    /** @return list<string> */
    private function mediaIds(Draft $draft): array
    {
        preg_match_all('~/media/([a-f0-9]{32})(?![a-f0-9])~', $draft->body . json_encode($draft->fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $matches);
        $ids = $matches[1];
        if ($draft->cover !== null) {
            $ids[] = $draft->cover;
        }
        return array_values(array_unique($ids));
    }
}
