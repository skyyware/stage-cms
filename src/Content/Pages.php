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
    public function __construct(private Database $db) {}

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
        return 'SELECT p.id, ' . $version . ' AS version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.cover, r.actor, r.action, r.created_at'
            . ($includeBody ? ', r.body' : '') . ' FROM pages p JOIN revisions r ON r.page_id = p.id AND r.version = ' . $version;
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
        $rows = $this->db->all('SELECT p.id, r.version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.cover, r.actor, r.action, r.created_at'
            . ($includeBody ? ', r.body' : '') . ' FROM revisions r JOIN pages p ON p.id = r.page_id WHERE p.id = :id ORDER BY r.version DESC LIMIT 51 OFFSET :offset',
            ['id' => $id, 'offset' => Listing::offset($page)]);
        return new Listing(array_map($includeBody ? Page::fromRow(...) : PageSummary::fromRow(...), $rows), $page);
    }

    public function revision(Caller $caller, string $id, int $version): Page
    {
        $caller->require('content:read');
        $row = $this->db->one('SELECT p.id, r.version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.body, r.cover, r.actor, r.action, r.created_at FROM revisions r JOIN pages p ON p.id = r.page_id WHERE p.id = :id AND r.version = :version',
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
            'SELECT p.id, r.version, p.published_version, p.archived, r.title, r.slug, r.excerpt, r.body, r.cover, r.actor, r.action, r.created_at FROM revisions r JOIN pages p ON p.id = r.page_id WHERE p.id = :id ORDER BY r.version DESC LIMIT 50 OFFSET :offset',
            ['id' => $id, 'offset' => Listing::offset($page)]));
    }

    public function create(Caller $caller, Draft $draft, bool $publish = false): Page
    {
        $caller->require('content:write');
        if ($publish) {
            $caller->require('content:publish');
        }
        return $this->db->transaction(function () use ($caller, $draft, $publish): Page {
            $this->assertAvailable($draft, '');
            $id = bin2hex(random_bytes(16));
            $this->db->execute('INSERT INTO pages (id, slug, version, created_at) VALUES (:id, :slug, 1, :created)', [
                'id' => $id, 'slug' => $draft->slug, 'created' => gmdate('c'),
            ]);
            $this->record($id, 1, $draft, $caller, $publish ? 'published' : 'created');
            if ($publish) {
                $this->db->execute('UPDATE pages SET published_version = 1, published_slug = :slug WHERE id = :id', ['id' => $id, 'slug' => $draft->slug]);
            }
            return $this->load($id);
        });
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
        $row = $this->db->one('SELECT title, slug, excerpt, body, cover FROM revisions WHERE page_id = :id AND version = :version', ['id' => $id, 'version' => $version]);
        if ($row === null) {
            throw new Failure(404, 'not_found', 'That revision does not exist.');
        }
        return $this->change($caller, $id, $expectedVersion, 'restored', Draft::fromInput($row));
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
            $this->assertAvailable($draft, $id);
            $version = $page->version + 1;
            $this->record($id, $version, $draft, $caller, $action);
            $this->db->execute('UPDATE pages SET slug = :slug, version = :version, archived = :archived WHERE id = :id', [
                'id' => $id, 'slug' => $draft->slug, 'version' => $version, 'archived' => $action === 'archived' ? 1 : 0,
            ]);
            if ($action === 'published') {
                $this->db->execute('UPDATE pages SET published_version = :version, published_slug = :slug WHERE id = :id',
                    ['id' => $id, 'version' => $version, 'slug' => $draft->slug]);
            } elseif (in_array($action, ['unpublished', 'archived', 'recovered'], true)) {
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

    private function assertExists(string $id): void
    {
        if ($this->db->one('SELECT id FROM pages WHERE id = :id', ['id' => $id]) === null) {
            throw new Failure(404, 'not_found', 'That page does not exist.');
        }
    }

    private function assertAvailable(Draft $draft, string $id): void
    {
        if ($this->db->one('SELECT id FROM pages WHERE id != :id AND (slug = :slug OR published_slug = :slug)', ['id' => $id, 'slug' => $draft->slug]) !== null) {
            throw new Failure(409, 'slug_taken', 'Another page already uses this address, including pages in the archive.');
        }
        foreach ($this->mediaIds($draft) as $mediaId) {
            if ($this->db->one('SELECT id FROM media WHERE id = :id', ['id' => $mediaId]) === null) {
                throw new Failure(422, 'missing_media', 'An image in this page is not in the media library.');
            }
        }
    }

    private function record(string $id, int $version, Draft $draft, Caller $caller, string $action): void
    {
        $this->db->execute('INSERT INTO revisions (page_id, version, title, slug, excerpt, body, cover, action, actor, created_at) VALUES (:id, :version, :title, :slug, :excerpt, :body, :cover, :action, :actor, :created)',
            array_merge($draft->data(), ['id' => $id, 'version' => $version, 'action' => $action, 'actor' => $caller->id ?? 'unknown', 'created' => gmdate('c')]));
        foreach ($this->mediaIds($draft) as $mediaId) {
            $this->db->execute('INSERT INTO revision_media VALUES (:id, :version, :media)', ['id' => $id, 'version' => $version, 'media' => $mediaId]);
        }
    }

    /** @return list<string> */
    private function mediaIds(Draft $draft): array
    {
        preg_match_all('~/media/([a-f0-9]{32})(?![a-f0-9])~', $draft->body, $matches);
        $ids = $matches[1];
        if ($draft->cover !== null) {
            $ids[] = $draft->cover;
        }
        return array_values(array_unique($ids));
    }
}
