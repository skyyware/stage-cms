<?php
declare(strict_types=1);

namespace StageCms\Tests;

use PHPUnit\Framework\TestCase;
use Stage\Http\Request;
use Stage\Security\Caller;
use Stage\Security\Forbidden;
use StageCms\Cms;
use StageCms\Content\Draft;
use StageCms\Failure;
use StageCms\Http\Kernel;
use StageCms\Identity\Identity;
use StageCms\Infrastructure\Archive;
use StageCms\Infrastructure\Config;
use StageCms\Input;
use StageCms\Presentation\Markdown;
use ZipArchive;

final class CmsTest extends TestCase
{
    private Cms $cms;
    private Caller $owner;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__) . '/.runtime/tests/' . bin2hex(random_bytes(10));
        $this->cms = new Cms(new Config(dirname(__DIR__), $this->directory));
        $this->owner = new Caller('owner', Identity::PERMISSIONS);
        $this->cms->identity->setup('Editor', 'editor@example.test', 'a long test-only password');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    private function draft(string $title = 'A considered beginning', string $slug = 'a-beginning', string $body = 'A little room for thought.', ?string $cover = null): Draft
    {
        return new Draft($title, $slug, 'An invitation to read.', $body, $cover);
    }

    /** @param callable(): mixed $operation */
    private function fails(string $kind, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected failure: ' . $kind);
        } catch (Failure $failure) {
            self::assertSame($kind, $failure->kind);
        }
    }

    public function testThemeUsesPublishedContentAndAuthenticatedDraftPreviews(): void
    {
        $theme = new class implements \StageCms\Presentation\Theme {
            public function index(int $page): \Stage\Http\Response
            {
                return \Stage\Http\Response::text('Custom index ' . $page);
            }

            public function page(\StageCms\Content\Page $page, bool $preview = false): \Stage\Http\Response
            {
                return \Stage\Http\Response::text(($preview ? 'Preview: ' : 'Published: ') . $page->draft->body);
            }
        };
        $kernel = new Kernel($this->cms, $theme);
        $page = $this->cms->pages->create($this->owner, $this->draft(body: 'Public text'), true);
        $this->cms->pages->save($this->owner, $page->id, $this->draft(body: 'Private text'), $page->version);
        self::assertSame('Custom index 1', $kernel->handle(new Request('GET', '/'))->body);
        self::assertSame('Published: Public text', $kernel->handle(new Request('GET', '/a-beginning'))->body);
        $preview = '/admin/pages/' . $page->id . '/preview';
        self::assertSame(303, $kernel->handle(new Request('GET', $preview))->status);
        $session = $this->cms->identity->login($this->cms->identity->newSession(), 'editor@example.test', 'a long test-only password', 'local');
        $response = $kernel->handle(new Request('GET', $preview, headers: ['cookie' => 'stage_cms=' . $session->secret]));
        self::assertSame('Preview: Private text', $response->body);
        self::assertSame('no-store', $response->headers['cache-control']);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/pages'))->status);
    }

    public function testPackageResourcesDoNotDependOnTheConsumingRoot(): void
    {
        $root = $this->directory . '/consumer';
        mkdir($root);
        $cms = new Cms(new Config($root, $this->directory . '/consumer-data'));
        $kernel = new Kernel($cms);
        foreach (['/assets/cms.css', '/assets/cms.js', '/assets/mark.svg', '/api/schema', '/llms.txt'] as $path) {
            $response = $kernel->handle(new Request('GET', $path));
            self::assertSame(200, $response->status, $path);
            self::assertNotSame('', $response->body, $path);
            self::assertSame(405, $kernel->handle(new Request('POST', $path))->status, $path);
        }
        self::assertNotSame(200, $kernel->handle(new Request('GET', '/assets/../composer.json'))->status);
    }

    public function testDraftPublicationAndSlugChangesAreIndependent(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft());
        $this->fails('not_found', fn () => $this->cms->pages->publishedPage('a-beginning'));
        $page = $this->cms->pages->publish($this->owner, $page->id, $page->version);
        self::assertSame('published', $page->status());
        $page = $this->cms->pages->save($this->owner, $page->id, $this->draft('The next thought', 'next-thought', 'New words.'), $page->version);
        self::assertSame('changed', $page->status());
        self::assertSame('A considered beginning', $this->cms->pages->publishedPage('a-beginning')->draft->title);
        $this->fails('slug_taken', fn () => $this->cms->pages->create($this->owner, $this->draft()));
        $this->fails('not_found', fn () => $this->cms->pages->publishedPage('next-thought'));
        $page = $this->cms->pages->publish($this->owner, $page->id, $page->version);
        self::assertSame('New words.', $this->cms->pages->publishedPage('next-thought')->draft->body);
        $this->fails('not_found', fn () => $this->cms->pages->publishedPage('a-beginning'));
        self::assertSame(4, $page->version);
    }

    public function testConcurrentStaleWriterCannotOverwriteASecondConnection(): void
    {
        $original = $this->cms->pages->create($this->owner, $this->draft());
        $other = new Cms($this->cms->config);
        $other->pages->save($this->owner, $original->id, $this->draft('Won the race'), $original->version);
        $this->fails('stale_revision', fn () => $this->cms->pages->save($this->owner, $original->id, $this->draft('Stale overwrite'), $original->version));
        self::assertSame('Won the race', $this->cms->pages->get($this->owner, $original->id)->draft->title);
        self::assertCount(2, $this->cms->pages->history($this->owner, $original->id));
    }

    public function testArchiveRecoveryAndRevisionRestorePreserveHistory(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(), true);
        $page = $this->cms->pages->save($this->owner, $page->id, $this->draft('Second draft'), $page->version);
        $page = $this->cms->pages->restore($this->owner, $page->id, 1, $page->version);
        self::assertSame('A considered beginning', $page->draft->title);
        self::assertSame(1, $page->publishedVersion);
        $page = $this->cms->pages->archive($this->owner, $page->id, $page->version);
        self::assertSame('archived', $page->status());
        self::assertNull($page->publishedVersion);
        self::assertCount(0, $this->cms->pages->list($this->owner));
        self::assertCount(1, $this->cms->pages->list($this->owner, 'archived'));
        $this->fails('archived', fn () => $this->cms->pages->publish($this->owner, $page->id, $page->version));
        $page = $this->cms->pages->recover($this->owner, $page->id, $page->version);
        self::assertSame('draft', $page->status());
        self::assertCount(5, $this->cms->pages->history($this->owner, $page->id));
    }

    public function testPermissionIsCheckedInsideTheOperationAndDeniedPublishDoesNotSave(): void
    {
        $writer = new Caller('agent:test', ['content:read', 'content:write']);
        $page = $this->cms->pages->create($writer, $this->draft());
        try {
            $this->cms->pages->save($writer, $page->id, $this->draft('Not saved'), $page->version, true);
            self::fail('Publication should be denied.');
        } catch (Forbidden) {
            self::assertSame('A considered beginning', $this->cms->pages->get($this->owner, $page->id)->draft->title);
            self::assertCount(1, $this->cms->pages->history($this->owner, $page->id));
        }
        $page = $this->cms->pages->publish($this->owner, $page->id, $page->version);
        $this->expectException(Forbidden::class);
        $this->cms->pages->archive($writer, $page->id, $page->version);
    }

    public function testInvalidSlugsAndMissingImagesCannotEnterContent(): void
    {
        foreach (['admin', '../escape', 'a/b', '%2f', 'Title', 'has space', 'a--b', ''] as $slug) {
            $this->fails('invalid_slug', fn () => $this->draft(slug: $slug));
        }
        $this->fails('missing_media', fn () => $this->cms->pages->create($this->owner, $this->draft(cover: str_repeat('a', 32))));
        self::assertCount(0, $this->cms->pages->list($this->owner));
    }

    public function testPublishedContentAndPageTitleCannotExecuteHtml(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft('<script>alert(1)</script>', body: "<script>alert(2)</script>\n\n[x](javascript:alert%281%29)\n\n**Safe**"), true);
        $response = (new Kernel($this->cms))->handle(new Request('GET', '/' . $page->draft->slug));
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body);
        self::assertStringNotContainsString('href="javascript:', $response->body);
        self::assertStringContainsString('<strong>Safe</strong>', $response->body);
        self::assertStringContainsString("frame-ancestors 'none'", $response->headers['content-security-policy']);
        self::assertSame('no-store', $response->headers['cache-control']);
        self::assertStringNotContainsString('<img', (new Markdown())->render('<img src=x onerror=alert(1)>'));
    }

    public function testLoginRequiresCsrfAndRotatesSession(): void
    {
        $kernel = new Kernel($this->cms);
        $session = $this->cms->identity->newSession();
        $headers = ['cookie' => 'stage_cms=' . $session->secret];
        $body = http_build_query(['email' => 'editor@example.test', 'password' => 'a long test-only password']);
        self::assertSame(403, $kernel->handle(new Request('POST', '/admin/login', $body, $headers))->status);
        $body .= '&csrf=' . $session->csrf;
        $response = $kernel->handle(new Request('POST', '/admin/login', $body, $headers));
        self::assertSame(303, $response->status);
        self::assertSame('/admin/pages', $response->headers['location']);
        self::assertNull($this->cms->identity->session($session->secret));
        self::assertStringContainsString('HttpOnly; SameSite=Lax', $response->headers['set-cookie']);
        self::assertStringNotContainsString($session->secret, $response->headers['set-cookie']);
        $fresh = explode(';', $response->headers['set-cookie'])[0];
        self::assertSame(200, $kernel->handle(new Request('GET', '/admin/pages', headers: ['cookie' => $fresh]))->status);
    }

    public function testLoginRateLimitAndPasswordResetInvalidateSessions(): void
    {
        $session = $this->cms->identity->newSession();
        for ($i = 0; $i < 10; $i++) {
            $this->fails('invalid_credentials', fn () => $this->cms->identity->login($session, 'editor@example.test', 'incorrect', '192.0.2.1'));
        }
        $this->fails('rate_limited', fn () => $this->cms->identity->login($session, 'editor@example.test', 'a long test-only password', '192.0.2.1'));
        $signedIn = $this->cms->identity->login($session, 'editor@example.test', 'a long test-only password', '192.0.2.2');
        $this->cms->identity->changePassword($this->owner, 'a replacement password');
        self::assertNull($this->cms->identity->session($signedIn->secret));
        $this->fails('invalid_credentials', fn () => $this->cms->identity->login($session, 'editor@example.test', 'a long test-only password', '192.0.2.2'));
    }

    public function testTokensAreHashedScopedRevocableAndExpire(): void
    {
        $secret = $this->cms->identity->createToken($this->owner, 'Writer', ['content:write']);
        $token = $this->cms->identity->tokens($this->owner)[0];
        self::assertArrayNotHasKey('hash', $token);
        self::assertStringNotContainsString($secret, json_encode($token, JSON_THROW_ON_ERROR));
        $this->cms->identity->authenticateToken('Bearer ' . $secret)->require('content:write');
        $this->cms->identity->revokeToken($this->owner, Input::text($token, 'id'));
        $this->fails('unauthorized', fn () => $this->cms->identity->authenticateToken('Bearer ' . $secret));
        $secret = $this->cms->identity->createToken($this->owner, 'Expired', ['content:read']);
        $this->cms->db->execute('UPDATE tokens SET expires = 1');
        $this->fails('unauthorized', fn () => $this->cms->identity->authenticateToken('Bearer ' . $secret));
    }

    public function testApiUsesTheSameRulesAndReturnsActionableConflicts(): void
    {
        $kernel = new Kernel($this->cms);
        $secret = $this->cms->identity->createToken($this->owner, 'Writer', ['content:write']);
        $headers = ['authorization' => 'Bearer ' . $secret, 'content-type' => 'application/json'];
        $page = Input::object(json_decode($kernel->handle(new Request('POST', '/api/pages', json_encode($this->draft()->data(), JSON_THROW_ON_ERROR), $headers))->body, true));
        $id = Input::text($page, 'id');
        self::assertSame('draft', $page['status']);
        $denied = $kernel->handle(new Request('POST', '/api/pages/' . $id . '/publish', '{"expected_version":1}', $headers));
        self::assertSame(403, $denied->status);
        $updated = array_merge($this->draft('Agent wrote this')->data(), ['expected_version' => 1]);
        $first = $kernel->handle(new Request('PUT', '/api/pages/' . $id, json_encode($updated, JSON_THROW_ON_ERROR), $headers));
        self::assertSame(200, $first->status);
        $second = $kernel->handle(new Request('PUT', '/api/pages/' . $id, json_encode($updated, JSON_THROW_ON_ERROR), $headers));
        self::assertSame(409, $second->status);
        self::assertStringContainsString('stale_revision', $second->body);
        self::assertSame(400, $kernel->handle(new Request('POST', '/api/pages', '[]', $headers))->status);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/pages'))->status);
        self::assertSame(200, $kernel->handle(new Request('GET', '/api/schema'))->status);
    }

    public function testWebEditorPreservesTextOnInvalidAndStaleSubmissions(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft());
        $session = $this->cms->identity->newSession(true);
        $kernel = new Kernel($this->cms);
        $headers = ['cookie' => 'stage_cms=' . $session->secret];
        $values = array_merge($this->draft('Work to preserve', body: 'My unsaved words.')->data(), ['csrf' => $session->csrf, 'expected_version' => '1', 'intent' => 'save']);
        $invalid = $values;
        $invalid['slug'] = 'not valid';
        $response = $kernel->handle(new Request('POST', '/admin/pages/' . $page->id, http_build_query($invalid), $headers));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('My unsaved words.', $response->body);
        self::assertStringContainsString('data-unsaved="true"', $response->body);
        $this->cms->pages->save($this->owner, $page->id, $this->draft('Changed elsewhere'), 1);
        $response = $kernel->handle(new Request('POST', '/admin/pages/' . $page->id, http_build_query($values), $headers));
        self::assertSame(409, $response->status);
        self::assertStringContainsString('My unsaved words.', $response->body);
        self::assertStringContainsString('name="expected_version" value="1"', $response->body);
        self::assertStringContainsString('data-unsaved="true"', $response->body);
        self::assertStringContainsString('Open the latest version in another tab', $response->body);
        self::assertSame('Changed elsewhere', $this->cms->pages->get($this->owner, $page->id)->draft->title);
    }

    public function testMediaIsDecodedPrivateAndCannotBeDeletedWhileReferenced(): void
    {
        $bytes = $this->png() . '<?php echo "hostile";';
        $asset = $this->cms->media->upload($this->owner, '../../photo.php', $bytes, 'A small image');
        self::assertSame('photo.php', $asset->name);
        self::assertSame('image/png', $asset->mime);
        self::assertStringNotContainsString('<?php', $this->cms->media->bytes($asset->id, $this->owner));
        $this->fails('not_found', fn () => $this->cms->media->bytes($asset->id));
        $kernel = new Kernel($this->cms);
        self::assertSame(404, $kernel->handle(new Request('GET', '/media/' . $asset->id))->status);
        $page = $this->cms->pages->create($this->owner, $this->draft(cover: $asset->id), true);
        self::assertSame(200, $kernel->handle(new Request('GET', '/media/' . $asset->id))->status);
        $this->fails('image_in_use', fn () => $this->cms->media->delete($this->owner, $asset->id));
        $this->cms->pages->unpublish($this->owner, $page->id, $page->version);
        self::assertSame(404, $kernel->handle(new Request('GET', '/media/' . $asset->id))->status);
        $this->fails('image_in_use', fn () => $this->cms->media->delete($this->owner, $asset->id));
        $this->fails('invalid_image', fn () => $this->cms->media->upload($this->owner, 'hostile.svg', '<svg onload="alert(1)"/>'));
        $secret = $this->cms->identity->createToken($this->owner, 'Reader', ['content:read']);
        self::assertSame(200, $kernel->handle(new Request('GET', '/media/' . $asset->id, headers: ['authorization' => 'Bearer ' . $secret]))->status);
    }

    public function testPortableArchiveRoundTripPreservesContentButNeverCredentials(): void
    {
        $asset = $this->cms->media->upload($this->owner, 'cover.png', $this->png(), 'A small image');
        $page = $this->cms->pages->create($this->owner, $this->draft(cover: $asset->id), true);
        $this->cms->pages->save($this->owner, $page->id, $this->draft('An unpublished edit', cover: $asset->id), $page->version);
        $this->cms->settings->save($this->owner, 'Fieldnotes', 'A thoughtful publication.');
        $secret = $this->cms->identity->createToken($this->owner, 'Not exported', ['content:read']);
        $path = $this->directory . '/export.zip';
        file_put_contents($path, (new Archive($this->cms))->export($this->owner));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $json = $zip->getFromName('content.json');
        self::assertIsString($json);
        self::assertStringNotContainsString('password_hash', $json);
        self::assertStringNotContainsString('editor@example.test', $json);
        self::assertStringNotContainsString('Not exported', $json);
        self::assertStringNotContainsString($secret, $json);
        $zip->close();
        $target = new Cms(new Config(dirname(__DIR__), $this->directory . '/restored'));
        (new Archive($target))->restore($this->owner, $path);
        self::assertNull($target->identity->owner());
        self::assertSame('Fieldnotes', $target->settings->get()['title']);
        self::assertSame('A considered beginning', $target->pages->publishedPage('a-beginning')->draft->title);
        self::assertSame('An unpublished edit', $target->pages->get($this->owner, $page->id)->draft->title);
        self::assertCount(2, $target->pages->history($this->owner, $page->id));
        self::assertSame($asset->sha256, hash('sha256', $target->media->bytes($asset->id)));
        $this->fails('not_empty', fn () => (new Archive($target))->restore($this->owner, $path));
    }

    public function testArchiveRejectsUnexpectedPathsWithoutExtractingAnything(): void
    {
        $path = $this->directory . '/bad.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('../escaped', 'no');
        $zip->close();
        $this->fails('invalid_archive', fn () => (new Archive($this->cms))->restore($this->owner, $path));
        self::assertFileDoesNotExist(dirname($this->directory) . '/escaped');
    }

    public function testPaginationMakesOlderPagesAndRevisionsReachable(): void
    {
        for ($index = 1; $index <= 51; $index++) {
            $this->cms->pages->create($this->owner, $this->draft('Page ' . $index, 'page-' . $index), true);
        }
        $first = $this->cms->pages->list($this->owner);
        $second = $this->cms->pages->list($this->owner, page: 2);
        self::assertCount(50, $first);
        self::assertCount(1, $second);
        self::assertCount(1, $this->cms->pages->published(2));
        self::assertNotContains($second[0]->id, array_map(fn ($page) => $page->id, $first));
        $page = $first[0];
        for ($index = 1; $index <= 50; $index++) {
            $page = $this->cms->pages->save($this->owner, $page->id, $page->draft, $page->version);
        }
        self::assertCount(50, $this->cms->pages->history($this->owner, $page->id));
        self::assertSame(1, $this->cms->pages->history($this->owner, $page->id, 2)[0]->version);
    }

    public function testFrameworkIsLoadedFromTheComposerPackage(): void
    {
        $reflection = new \ReflectionClass(\Stage\Http\Application::class);
        self::assertSame(realpath(dirname(__DIR__) . '/vendor/skyyware/stage/src/Http/Application.php'), $reflection->getFileName());
        self::assertSame('0.1.0.0', \Composer\InstalledVersions::getVersion('skyyware/stage'));
    }

    public function testDataDirectoryCannotBePublic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(dirname(__DIR__), dirname(__DIR__) . '/public');
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(12, 8);
        self::assertNotFalse($image);
        $color = imagecolorallocate($image, 181, 70, 34);
        self::assertNotFalse($color);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);
        return $bytes;
    }
}
