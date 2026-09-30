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
        mkdir($root . '/vendor');
        file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        $cms = new Cms(new Config($root, $this->directory . '/consumer-data'));
        $kernel = new Kernel($cms);
        foreach (['/assets/cms.css', '/assets/cms.js', '/assets/mark.svg', '/api/schema', '/llms.txt'] as $path) {
            $response = $kernel->handle(new Request('GET', $path));
            self::assertSame(200, $response->status, $path);
            self::assertNotSame('', $response->body, $path);
            self::assertSame(405, $kernel->handle(new Request('POST', $path))->status, $path);
        }
        self::assertNotSame(200, $kernel->handle(new Request('GET', '/assets/../composer.json'))->status);
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/cms', 'doctor', '--json'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
            ['CMS_ROOT' => $root, 'CMS_DATA_DIR' => $this->directory . '/cli-data', 'CMS_URL' => 'https://example.test']);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error ?: 'CLI failed');
        self::assertIsString($output);
        self::assertStringContainsString('"status":"ok"', $output);
        self::assertStringContainsString('https://example.test', $output);
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
        self::assertSame('0.1.1.0', \Composer\InstalledVersions::getVersion('skyyware/stage'));
    }

    public function testCompactListsHaveExactPaginationAndExplicitFullContent(): void
    {
        for ($index = 1; $index <= 50; $index++) {
            $this->cms->pages->create($this->owner, $this->draft('Page ' . $index, 'page-' . $index, 'PRIVATE_BODY_' . $index), true);
        }
        $listing = $this->cms->pages->browse($this->owner);
        self::assertCount(50, $listing->items);
        self::assertNull($listing->nextPage);
        self::assertArrayNotHasKey('body', $listing->items[0]->data());
        self::assertNull($this->cms->pages->publication()->nextPage);
        $last = $this->cms->pages->create($this->owner, $this->draft('Older page', 'older-page', 'FULL_BODY'));
        self::assertSame(2, $this->cms->pages->browse($this->owner)->nextPage);
        self::assertCount(1, $this->cms->pages->browse($this->owner, page: 2)->items);
        self::assertNull($this->cms->pages->browse($this->owner, page: 2)->nextPage);
        $secret = $this->cms->identity->createToken($this->owner, 'Reader', ['content:read']);
        $headers = ['authorization' => 'Bearer ' . $secret];
        $kernel = new Kernel($this->cms);
        $summary = $kernel->handle(new Request('GET', '/api/pages', headers: $headers, query: 'q=older-page'));
        self::assertStringNotContainsString('FULL_BODY', $summary->body);
        self::assertStringContainsString('FULL_BODY', $kernel->handle(new Request('GET', '/api/pages', headers: $headers, query: 'q=older-page&include=body'))->body);
        self::assertStringContainsString('FULL_BODY', $kernel->handle(new Request('GET', '/api/pages/' . $last->id, headers: $headers))->body);
        self::assertSame(422, $kernel->handle(new Request('GET', '/api/pages', headers: $headers, query: 'include=unknown'))->status);
        self::assertSame(422, $kernel->handle(new Request('GET', '/api/pages', headers: $headers, query: 'page=0'))->status);
        $this->expectException(Forbidden::class);
        $this->cms->pages->browse(new Caller(null));
    }

    public function testRevisionBodiesLoadIndividuallyAndStayPrivate(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(body: 'PRIVATE_REVISION_ONE'));
        for ($index = 2; $index <= 50; $index++) {
            $page = $this->cms->pages->save($this->owner, $page->id, $this->draft(body: 'PRIVATE_REVISION_' . $index), $page->version);
        }
        $listing = $this->cms->pages->revisions($this->owner, $page->id);
        self::assertCount(50, $listing->items);
        self::assertNull($listing->nextPage);
        self::assertArrayNotHasKey('body', $listing->items[0]->data());
        self::assertSame('PRIVATE_REVISION_ONE', $this->cms->pages->revision($this->owner, $page->id, 1)->draft->body);
        $kernel = new Kernel($this->cms);
        $session = $this->cms->identity->newSession(true);
        $headers = ['cookie' => 'stage_cms=' . $session->secret];
        $path = '/admin/pages/' . $page->id . '/history';
        $history = $kernel->handle(new Request('GET', $path, headers: $headers));
        self::assertStringNotContainsString('PRIVATE_REVISION_', $history->body);
        self::assertStringNotContainsString('page=2', $history->body);
        self::assertStringContainsString('PRIVATE_REVISION_ONE', $kernel->handle(new Request('GET', $path . '/1', headers: $headers))->body);
        self::assertSame(303, $kernel->handle(new Request('GET', $path . '/1'))->status);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/pages/' . $page->id . '/history/1'))->status);
        $token = $this->cms->identity->createToken($this->owner, 'Reader', ['content:read']);
        $response = $kernel->handle(new Request('GET', '/api/pages/' . $page->id . '/history/1', headers: ['authorization' => 'Bearer ' . $token]));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('PRIVATE_REVISION_ONE', $response->body);
        $this->fails('not_found', fn () => $this->cms->pages->revision($this->owner, $page->id, 51));
    }

    public function testMediaSearchPaginationAndCoverSelectionPreservePublication(): void
    {
        $assets = [];
        $bytes = $this->png();
        for ($index = 1; $index <= 51; $index++) {
            $assets[] = $this->cms->media->upload($this->owner, 'Image ' . $index . '.png', $bytes, $index === 51 ? 'Searchable amber cover' : '');
        }
        $first = $this->cms->media->browse($this->owner);
        $second = $this->cms->media->browse($this->owner, page: 2);
        self::assertCount(50, $first->items);
        self::assertSame(2, $first->nextPage);
        self::assertCount(1, $second->items);
        self::assertNull($second->nextPage);
        self::assertNotContains($second->items[0]->id, array_map(fn ($asset) => $asset->id, $first->items));
        $found = $this->cms->media->browse($this->owner, 'amber');
        self::assertSame($assets[50]->id, $found->items[0]->id);
        self::assertNull($found->nextPage);
        $page = $this->cms->pages->create($this->owner, $this->draft(body: 'Original publication'), true);
        $session = $this->cms->identity->newSession(true);
        $headers = ['cookie' => 'stage_cms=' . $session->secret];
        $kernel = new Kernel($this->cms);
        $path = '/admin/pages/' . $page->id;
        $values = array_merge($this->draft(body: 'Work before choosing')->data(), ['csrf' => $session->csrf, 'expected_version' => 1, 'intent' => 'cover']);
        $saved = $kernel->handle(new Request('POST', $path, http_build_query($values), $headers));
        self::assertSame($path . '/cover', $saved->headers['location']);
        $picker = $kernel->handle(new Request('GET', $path . '/cover', headers: $headers, query: 'q=amber'));
        self::assertSame(200, $picker->status);
        self::assertStringContainsString('Image 51.png', $picker->body);
        self::assertStringNotContainsString('Image 1.png', $picker->body);
        $choice = http_build_query(['csrf' => $session->csrf, 'expected_version' => 2, 'cover' => $assets[50]->id]);
        self::assertSame(303, $kernel->handle(new Request('POST', $path . '/cover', $choice, $headers))->status);
        $draft = $this->cms->pages->get($this->owner, $page->id);
        self::assertSame('Work before choosing', $draft->draft->body);
        self::assertSame($assets[50]->id, $draft->cover);
        self::assertNull($this->cms->pages->publishedPage($page->slug)->cover);
        self::assertSame('Original publication', $this->cms->pages->publishedPage($page->slug)->draft->body);
        self::assertSame(409, $kernel->handle(new Request('POST', $path . '/cover', $choice, $headers))->status);
        self::assertSame(403, $kernel->handle(new Request('POST', $path . '/cover', 'cover=' . $assets[0]->id, $headers))->status);
        $editor = $kernel->handle(new Request('GET', $path, headers: $headers));
        self::assertStringContainsString('Image 51.png', $editor->body);
        self::assertStringNotContainsString('Image 1.png', $editor->body);
    }

    public function testWarmAgentReadsDoNotNeedTheDatabaseWriteLock(): void
    {
        $secret = $this->cms->identity->createToken($this->owner, 'Reader', ['content:read']);
        $this->cms->identity->authenticateToken('Bearer ' . $secret);
        $other = new Cms($this->cms->config);
        $this->cms->db->pdo->exec('PRAGMA busy_timeout = 1');
        $other->db->transaction(function () use ($secret): void {
            $caller = $this->cms->identity->authenticateToken('Bearer ' . $secret);
            $caller->require('content:read');
            self::assertNotNull($caller->id);
            self::assertStringStartsWith('agent:', $caller->id);
        });
        $token = $this->cms->identity->tokens($this->owner)[0];
        self::assertNotNull($token['last_used']);
        $this->cms->identity->revokeToken($this->owner, Input::text($token, 'id'));
        $this->fails('unauthorized', fn () => $this->cms->identity->authenticateToken('Bearer ' . $secret));
    }

    public function testCmsAgentGuideHasItsOwnStableAddress(): void
    {
        $kernel = new Kernel($this->cms);
        $guide = $kernel->handle(new Request('GET', '/api/guide'));
        self::assertSame(200, $guide->status);
        self::assertStringContainsString('Stage CMS', $guide->body);
        self::assertSame($guide->body, $kernel->handle(new Request('GET', '/llms.txt'))->body);
        self::assertSame(405, $kernel->handle(new Request('POST', '/api/guide'))->status);
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
    public function testStructuredPagesKeepFieldsAcrossPublicationRestoreAndExport(): void
    {
        $types = new \StageCms\Content\PageTypes(new \StageCms\Content\PageType('homepage', 'Homepage', [new \StageCms\Content\Field('hero.title', 'Headline')], false));
        $cms = new Cms($this->cms->config, $types);
        $draft = new Draft('Home', 'home', '', '', type: 'homepage', locale: 'de', fields: ['hero.title' => 'Hallo']);
        $page = $cms->pages->create($this->owner, $draft, true);
        $edited = new Draft('Home', 'home', '', '', type: 'homepage', locale: 'de', fields: ['hero.title' => '<script>private</script>']);
        $saved = $cms->pages->save($this->owner, $page->id, $edited, 1);
        self::assertSame('Hallo', $cms->pages->publishedPage('home')->draft->fields['hero.title']);
        self::assertSame('homepage', $cms->pages->browse($this->owner)->items[0]->type);
        self::assertArrayNotHasKey('fields', $cms->pages->browse($this->owner)->items[0]->data());
        $this->fails('stale_revision', fn () => $cms->pages->save($this->owner, $page->id, $draft, 1));
        $restored = $cms->pages->restore($this->owner, $page->id, 1, $saved->version);
        self::assertSame($draft->data(), $restored->draft->data());
        $this->fails('unknown_page_type', fn () => $cms->pages->create($this->owner, new Draft('Bad', 'bad', '', '', type: 'unknown')));
        $this->fails('invalid_field', fn () => $cms->pages->create($this->owner, new Draft('Bad', 'bad', '', '', type: 'homepage', fields: ['unknown' => 'value'])));
        $cms->settings->save($this->owner, 'Website', '', 'skyyware');
        $path = $this->directory . '/structured.zip';
        file_put_contents($path, (new Archive($cms))->export($this->owner));
        $target = new Cms(new Config(dirname(__DIR__), $this->directory . '/structured-copy'), $types);
        (new Archive($target))->restore($this->owner, $path);
        self::assertSame($draft->data(), $target->pages->get($this->owner, $page->id)->draft->data());
        self::assertSame('skyyware', $target->settings->get()['theme']);
        $token = $cms->identity->createToken($this->owner, 'Drafts', ['content:read', 'content:write'], 30);
        $headers = ['authorization' => 'Bearer ' . $token, 'content-type' => 'application/json'];
        $kernel = new Kernel($cms);
        self::assertSame(200, $kernel->handle(new Request('GET', '/api/types', headers: $headers))->status);
        $response = $kernel->handle(new Request('PUT', '/api/pages/' . $page->id, json_encode($edited->data() + ['expected_version' => $restored->version], JSON_THROW_ON_ERROR), $headers));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('hero.title', $response->body);
    }

    public function testChangingTypeKeepsTextVisibleUntilExplicitlyCleared(): void
    {
        $types = new \StageCms\Content\PageTypes(new \StageCms\Content\PageType('homepage', 'Homepage', [new \StageCms\Content\Field('headline', 'Headline')], false));
        $cms = new Cms($this->cms->config, $types);
        $page = $cms->pages->create($this->owner, $this->draft(body: 'Keep my Markdown'));
        $session = $cms->identity->newSession(true);
        $kernel = new Kernel($cms);
        $headers = ['cookie' => 'stage_cms=' . $session->secret];
        $values = $page->draft->data() + ['csrf' => $session->csrf, 'expected_version' => '1', 'intent' => 'type'];
        $values['type'] = 'homepage';
        $path = '/admin/pages/' . $page->id;
        $response = $kernel->handle(new Request('POST', $path, http_build_query($values), $headers));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Keep my Markdown', $response->body);
        self::assertStringNotContainsString('class="markdown-editor" hidden', $response->body);
        self::assertSame(1, $cms->pages->get($this->owner, $page->id)->version);
        $values['intent'] = 'save';
        self::assertSame(422, $kernel->handle(new Request('POST', $path, http_build_query($values), $headers))->status);
        $values['body'] = '';
        $values['fields'] = ['headline' => 'Moved words'];
        self::assertSame(303, $kernel->handle(new Request('POST', $path, http_build_query($values), $headers))->status);
        $values['expected_version'] = '2';
        $values['type'] = 'page';
        $values['intent'] = 'type';
        $response = $kernel->handle(new Request('POST', $path, http_build_query($values), $headers));
        self::assertStringContainsString('Previous fields', $response->body);
        self::assertStringContainsString('Moved words', $response->body);
        $values['intent'] = 'save';
        self::assertSame(422, $kernel->handle(new Request('POST', $path, http_build_query($values), $headers))->status);
        $values['fields']['headline'] = '';
        $values['body'] = 'Moved words';
        self::assertSame(303, $kernel->handle(new Request('POST', $path, http_build_query($values), $headers))->status);
        $saved = $cms->pages->get($this->owner, $page->id);
        self::assertSame('page', $saved->type);
        self::assertSame([], $saved->draft->fields);
        self::assertSame('Moved words', $saved->draft->body);
    }

    public function testVersionOneExportStillRestoresIntoTheCurrentSchema(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(), true);
        $path = $this->directory . '/legacy.zip';
        file_put_contents($path, (new Archive($this->cms))->export($this->owner));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $json = $zip->getFromName('content.json');
        self::assertIsString($json);
        $data = Input::object(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $data['format'] = 'stage-cms/1';
        $settings = Input::object($data['settings']);
        unset($settings['theme']);
        $data['settings'] = $settings;
        $tables = Input::object($data['tables']);
        $rows = $this->cms->db->all('SELECT page_id, version, title, slug, excerpt, body, cover, action, actor, created_at FROM revisions');
        $tables['revisions'] = $rows;
        $tables['pages'] = $this->cms->db->all('SELECT id, slug, version, published_version, published_slug, archived, created_at FROM pages');
        unset($tables['page_bindings'], $tables['page_redirects']);
        $data['tables'] = $tables;
        $zip->addFromString('content.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();
        $target = new Cms(new Config(dirname(__DIR__), $this->directory . '/legacy-copy'));
        (new Archive($target))->restore($this->owner, $path);
        self::assertSame($page->draft->data(), $target->pages->publishedPage($page->slug)->draft->data());
        self::assertSame('', $target->settings->get()['theme']);
    }

    public function testApiAlwaysRepresentsFieldAndLocaleMapsAsObjects(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft());
        $token = $this->cms->identity->createToken($this->owner, 'Reader', ['content:read']);
        $kernel = new Kernel($this->cms);
        $headers = ['authorization' => 'Bearer ' . $token];
        $response = $kernel->handle(new Request('GET', '/api/pages/' . $page->id, headers: $headers));
        self::assertStringContainsString('"fields":{}', $response->body);
        $response = $kernel->handle(new Request('GET', '/api/types', headers: $headers));
        self::assertStringContainsString('"locales":{}', $response->body);
    }

    public function testThemeSelectionUsesInstalledChoicesAndRejectsUnknownThemes(): void
    {
        $theme = new class implements \StageCms\Presentation\Theme {
            public function index(int $page): \Stage\Http\Response { return \Stage\Http\Response::text('Website'); }
            public function page(\StageCms\Content\Page $page, bool $preview = false): \Stage\Http\Response { return \Stage\Http\Response::text($page->title); }
        };
        $themes = new \StageCms\Presentation\Themes($this->cms, new \StageCms\Presentation\ThemeOption('website', 'Website', $theme));
        $kernel = new Kernel($this->cms, $themes);
        $session = $this->cms->identity->login($this->cms->identity->newSession(), 'editor@example.test', 'a long test-only password', 'local');
        $headers = ['cookie' => 'stage_cms=' . $session->secret, 'content-type' => 'application/x-www-form-urlencoded'];
        $settings = $kernel->handle(new Request('GET', '/admin/settings', headers: $headers));
        self::assertStringContainsString('value="website" selected', $settings->body);
        $response = $kernel->handle(new Request('POST', '/admin/settings', http_build_query(['csrf' => $session->csrf, 'title' => 'Site', 'description' => '', 'theme' => 'website']), $headers));
        self::assertSame(303, $response->status);
        self::assertSame('website', $this->cms->settings->get()['theme']);
        self::assertSame('Website', $kernel->handle(new Request('GET', '/'))->body);
        $response = $kernel->handle(new Request('POST', '/admin/settings', http_build_query(['csrf' => $session->csrf, 'title' => 'Bad', 'description' => '', 'theme' => 'unknown']), $headers));
        self::assertSame(422, $response->status);
        self::assertSame('Site', $this->cms->settings->get()['title']);
    }

    public function testVersionOneDatabaseMigratesWithoutChangingPublishedContent(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(), true);
        $this->cms->db->pdo->exec('DROP TABLE page_bindings; DROP TABLE page_redirects; DROP INDEX page_languages; ALTER TABLE pages DROP COLUMN translation_group; ALTER TABLE pages DROP COLUMN locale; ALTER TABLE revisions DROP COLUMN type; ALTER TABLE revisions DROP COLUMN locale; ALTER TABLE revisions DROP COLUMN fields; ALTER TABLE settings DROP COLUMN theme; PRAGMA user_version = 1;');
        $upgraded = new Cms($this->cms->config);
        $restored = $upgraded->pages->publishedPage($page->slug);
        self::assertSame($page->draft->data(), $restored->draft->data());
        $version = $upgraded->db->pdo->query('PRAGMA user_version');
        self::assertNotFalse($version);
        self::assertSame(3, $version->fetchColumn());
        $upgraded->db->migrate();
        self::assertSame($page->version, $upgraded->pages->get($this->owner, $page->id)->version);
    }
    public function testNamedPagesKeepTheirRoutesAndTranslationIdentity(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(), true);
        $page = $this->cms->pages->bind($this->owner, 'homepage', $page->id, '/');
        self::assertSame('/', $this->cms->pages->bind($this->owner, 'homepage', $page->id, '/')->path());
        $german = $this->cms->pages->translate($this->owner, $page->id, 'de', 'anfang', $page->version);
        self::assertSame('draft', $german->status());
        self::assertSame($page->translationGroup, $german->translationGroup);
        self::assertCount(2, $this->cms->pages->translations($this->owner, $page->id));
        $this->fails('translation_exists', fn () => $this->cms->pages->translate($this->owner, $page->id, 'de', 'zweiter-anfang', 1));
        $this->fails('stale_revision', fn () => $this->cms->pages->translate($this->owner, $page->id, 'fr', 'bonjour', 99));
        $this->fails('binding_taken', fn () => $this->cms->pages->bind($this->owner, 'unrelated', $german->id, '/de'));
        $german = $this->cms->pages->bind($this->owner, 'homepage', $german->id, '/de');
        $this->fails('not_found', fn () => $this->cms->pages->publishedBinding('homepage', 'de'));
        $this->cms->pages->publish($this->owner, $german->id, $german->version);
        self::assertSame('/de', $this->cms->pages->publishedBinding('homepage', 'de')->path());
        $renamed = $this->cms->pages->save($this->owner, $page->id, $this->draft(slug: 'renamed'), $page->version, true);
        self::assertSame($page->id, $this->cms->pages->publishedBinding('homepage', 'en')->id);
        self::assertSame('/', $this->cms->pages->resolvePath('/a-beginning')->path());
        self::assertSame($page->id, $this->cms->pages->resolvePath('/renamed')->id);
        $this->fails('slug_taken', fn () => $this->cms->pages->create($this->owner, $this->draft()));
        $kernel = new Kernel($this->cms);
        self::assertSame(200, $kernel->handle(new Request('GET', '/'))->status);
        self::assertSame('/', $kernel->handle(new Request('GET', '/a-beginning'))->headers['location']);
        self::assertSame('/de', $kernel->handle(new Request('GET', '/anfang'))->headers['location']);
        $this->fails('fixed_language', fn () => $this->cms->pages->save($this->owner, $page->id, new Draft('Changed', 'renamed', '', '', locale: 'de'), $renamed->version));
        $path = $this->directory . '/identity.zip';
        file_put_contents($path, (new Archive($this->cms))->export($this->owner));
        $copy = new Cms(new Config(dirname(__DIR__), $this->directory . '/identity-copy'));
        (new Archive($copy))->restore($this->owner, $path);
        self::assertSame($renamed->data(), $copy->pages->publishedById($page->id)->data());
        self::assertCount(2, $copy->pages->translations($this->owner, $page->id));
        self::assertSame('/', $copy->pages->resolvePath('/a-beginning')->path());
        $archived = $copy->pages->archive($this->owner, $page->id, $renamed->version);
        $this->fails('not_found', fn () => $copy->pages->resolvePath('/a-beginning'));
        $recovered = $copy->pages->recover($this->owner, $page->id, $archived->version);
        $this->fails('not_found', fn () => $copy->pages->publishedById($page->id));
        self::assertSame('/', $recovered->path());
    }

    public function testPublicationValidatesTheExactCandidateAcrossAllWritePaths(): void
    {
        $rule = new class implements \StageCms\Content\PublicationRule {
            /** @var list<\StageCms\Content\PublicationCandidate> */
            public array $seen = [];
            public function validate(\StageCms\Content\PublicationCandidate $candidate): void
            {
                $this->seen[] = $candidate;
                if ($candidate->draft->fields['headline'] === 'Refuse') {
                    throw new Failure(422, 'review_required', 'Review this headline.', ['fields.headline' => 'Use verified text.']);
                }
            }
        };
        $types = new \StageCms\Content\PageTypes(new \StageCms\Content\PageType('article', 'Article', [new \StageCms\Content\Field('headline', 'Headline', required: true)], false));
        $cms = new Cms($this->cms->config, $types, ['en' => 'English', 'de' => 'Deutsch'], $rule);
        $draft = fn (string $headline): Draft => new Draft('Article', 'article', '', '', type: 'article', fields: ['headline' => $headline]);
        $this->fails('incomplete_publication', fn () => $cms->pages->create($this->owner, $draft(''), true));
        $this->fails('review_required', fn () => $cms->pages->create($this->owner, $draft('Refuse'), true));
        self::assertCount(0, $cms->pages->list($this->owner));
        $page = $cms->pages->create($this->owner, $draft('Original'), true);
        self::assertSame($page->id, $rule->seen[1]->pageId);
        self::assertSame(1, $rule->seen[1]->version);
        $this->fails('review_required', fn () => $cms->pages->save($this->owner, $page->id, $draft('Refuse'), 1, true));
        self::assertSame(1, $cms->pages->get($this->owner, $page->id)->version);
        $page = $cms->pages->save($this->owner, $page->id, $draft('Refuse'), 1);
        $this->fails('review_required', fn () => $cms->pages->publish($this->owner, $page->id, $page->version));
        self::assertSame(3, $rule->seen[3]->version);
        self::assertSame('Original', $cms->pages->publishedById($page->id)->draft->fields['headline']);
        self::assertCount(2, $cms->pages->history($this->owner, $page->id));
        $this->fails('stale_revision', fn () => $cms->pages->publish($this->owner, $page->id, 1));
        self::assertCount(4, $rule->seen);
        $token = $cms->identity->createToken($this->owner, 'Publisher', ['content:read', 'content:write', 'content:publish']);
        $headers = ['authorization' => 'Bearer ' . $token, 'content-type' => 'application/json'];
        $kernel = new Kernel($cms);
        $response = $kernel->handle(new Request('POST', '/api/pages/' . $page->id . '/publish', '{"expected_version":2}', $headers));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('"fields.headline":"Use verified text."', $response->body);
        self::assertStringContainsString('Original', $kernel->handle(new Request('GET', '/api/pages/' . $page->id . '/published', headers: $headers))->body);
        $session = $cms->identity->login($cms->identity->newSession(), 'editor@example.test', 'a long test-only password', 'local');
        $form = $draft('')->data() + ['expected_version' => 2, 'csrf' => $session->csrf, 'intent' => 'publish'];
        $response = $kernel->handle(new Request('POST', '/admin/pages/' . $page->id, http_build_query($form), ['cookie' => 'stage_cms=' . $session->secret, 'content-type' => 'application/x-www-form-urlencoded']));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="field-headline-error"', $response->body);
        self::assertStringContainsString('Required to publish', $response->body);
        self::assertSame(2, $cms->pages->get($this->owner, $page->id)->version);
        $form['intent'] = 'save';
        self::assertSame(303, $kernel->handle(new Request('POST', '/admin/pages/' . $page->id, http_build_query($form), ['cookie' => 'stage_cms=' . $session->secret, 'content-type' => 'application/x-www-form-urlencoded']))->status);
        $bound = $cms->pages->bind($this->owner, 'article', $page->id, '/news/article');
        $this->fails('fixed_page_type', fn () => $cms->pages->save($this->owner, $page->id, new Draft('Other', 'article', '', ''), $bound->version));
        self::assertSame(200, $kernel->handle(new Request('GET', '/news/article'))->status);
    }

    public function testTranslationsApiHonorsScopesVersionsAndSeparateDrafts(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(), true);
        $kernel = new Kernel($this->cms);
        $reader = $this->cms->identity->createToken($this->owner, 'Reader', ['content:read']);
        $writer = $this->cms->identity->createToken($this->owner, 'Writer', ['content:read', 'content:write']);
        $path = '/api/pages/' . $page->id . '/translations';
        $body = '{"locale":"de","slug":"anfang","expected_version":1}';
        self::assertSame(403, $kernel->handle(new Request('POST', $path, $body, ['authorization' => 'Bearer ' . $reader, 'content-type' => 'application/json']))->status);
        $headers = ['authorization' => 'Bearer ' . $writer, 'content-type' => 'application/json'];
        self::assertSame(201, $kernel->handle(new Request('POST', $path, $body, $headers))->status);
        self::assertSame(409, $kernel->handle(new Request('POST', $path, $body, $headers))->status);
        self::assertStringContainsString('"locale":"de"', $kernel->handle(new Request('GET', $path, headers: $headers))->body);
        self::assertCount(1, $this->cms->pages->published());
        self::assertSame(1, $this->cms->pages->get($this->owner, $page->id)->version);
        $this->fails('invalid_binding', fn () => $this->cms->pages->bind($this->owner, 'home', $page->id, '/admin'));
    }
    public function testUnpublishedAddressesStayReservedUntilPublicationReturns(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(), true);
        $page = $this->cms->pages->unpublish($this->owner, $page->id, $page->version);
        $this->fails('not_found', fn () => $this->cms->pages->resolvePath('/a-beginning'));
        $page = $this->cms->pages->save($this->owner, $page->id, $this->draft(slug: 'new-address'), $page->version);
        $this->fails('slug_taken', fn () => $this->cms->pages->create($this->owner, $this->draft()));
        $this->cms->pages->publish($this->owner, $page->id, $page->version);
        self::assertSame('/new-address', $this->cms->pages->resolvePath('/a-beginning')->path());
    }

    public function testVersionTwoMigrationAndArchiveKeepNonEnglishPublishedRevisions(): void
    {
        $page = $this->cms->pages->create($this->owner, new Draft('Bonjour', 'bonjour', '', 'Texte', locale: 'fr'), true);
        $path = $this->directory . '/version-two.zip';
        file_put_contents($path, (new Archive($this->cms))->export($this->owner));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $json = $zip->getFromName('content.json');
        self::assertIsString($json);
        $data = Input::object(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $data['format'] = 'stage-cms/2';
        $tables = Input::object($data['tables']);
        $tables['pages'] = $this->cms->db->all('SELECT id, slug, version, published_version, published_slug, archived, created_at FROM pages');
        unset($tables['page_bindings'], $tables['page_redirects']);
        $data['tables'] = $tables;
        $zip->addFromString('content.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();
        $copy = new Cms(new Config(dirname(__DIR__), $this->directory . '/version-two-copy'));
        (new Archive($copy))->restore($this->owner, $path);
        self::assertSame($page->data(), $copy->pages->publishedById($page->id)->data());
        $this->cms->db->pdo->exec('DROP TABLE page_bindings; DROP TABLE page_redirects; DROP INDEX page_languages; ALTER TABLE pages DROP COLUMN translation_group; ALTER TABLE pages DROP COLUMN locale; PRAGMA user_version = 2;');
        $upgraded = new Cms($this->cms->config);
        self::assertSame($page->data(), $upgraded->pages->publishedById($page->id)->data());
        $row = $upgraded->db->one('SELECT locale FROM pages WHERE id = :id', ['id' => $page->id]);
        self::assertNotNull($row);
        self::assertSame('fr', Input::text($row, 'locale'));
        $upgraded->db->migrate();
        self::assertCount(1, $upgraded->pages->history($this->owner, $page->id));
    }
    public function testRestoreRejectsInconsistentBindingsWithoutPartialContent(): void
    {
        $page = $this->cms->pages->create($this->owner, $this->draft(), true);
        $this->cms->pages->bind($this->owner, 'homepage', $page->id, '/');
        $path = $this->directory . '/conflicting-binding.zip';
        file_put_contents($path, (new Archive($this->cms))->export($this->owner));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $json = $zip->getFromName('content.json');
        self::assertIsString($json);
        $data = Input::object(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $tables = Input::object($data['tables']);
        $tables['page_bindings'] = [['name' => 'homepage', 'locale' => 'de', 'page_id' => $page->id, 'path' => '/', 'type' => 'page']];
        $data['tables'] = $tables;
        $zip->addFromString('content.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();
        $copy = new Cms(new Config(dirname(__DIR__), $this->directory . '/invalid-copy'));
        $this->fails('invalid_archive', fn () => (new Archive($copy))->restore($this->owner, $path));
        self::assertCount(0, $copy->pages->list($this->owner));
        self::assertSame([], $copy->db->all('SELECT * FROM page_bindings'));
        self::assertSame([], $copy->db->all('SELECT * FROM revisions'));
    }
}
