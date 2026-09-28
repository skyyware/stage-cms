<?php
declare(strict_types=1);

namespace StageCms\Http;

use Stage\Http\Application;
use Stage\Http\Request;
use Stage\Http\Response;
use Stage\Http\Route;
use Stage\Security\Forbidden;
use StageCms\Cms;
use StageCms\Failure;
use StageCms\Input;
use StageCms\Presentation\View;
use StageCms\Presentation\Publication;
use StageCms\Presentation\Theme;

final readonly class Kernel
{
    private Theme $theme;

    public function __construct(private Cms $cms, ?Theme $theme = null)
    {
        $this->theme = $theme ?? new Publication($cms);
    }

    /** @param array<string, mixed> $uploads */
    public function handle(Request $request, array $uploads = [], string $address = 'local'): Response
    {
        $view = new View($this->cms->settings->get()['title']);
        $api = str_starts_with($request->path, '/api/');
        try {
            $response = $this->dispatch(new Context($request, $uploads, $address), $view);
        } catch (Failure $error) {
            $response = $api ? Response::json(['error' => ['code' => $error->kind, 'message' => $error->getMessage()]], $error->status)
                : Response::html($view->problem($error->getMessage(), $error->status), $error->status);
        } catch (Forbidden) {
            $response = $api ? Response::json(['error' => ['code' => 'forbidden', 'message' => 'This token does not have the required permission.']], 403)
                : Response::html($view->problem('You do not have access to this action.', 403), 403);
        } catch (\Throwable $error) {
            error_log('Stage CMS request failed: ' . $error::class);
            $response = $api ? Response::json(['error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.']], 500)
                : Response::html($view->problem('Something went wrong. Your saved work is safe.', 500), 500);
        }
        if ($api && in_array($response->status, [404, 405], true) && !str_starts_with($response->headers['content-type'] ?? '', 'application/json')) {
            $json = Response::json(['error' => ['code' => $response->status === 404 ? 'not_found' : 'method_not_allowed',
                'message' => $response->status === 404 ? 'That API operation does not exist.' : 'This HTTP method is not supported.']], $response->status);
            $response = new Response($json->body, $json->status, array_merge($response->headers, $json->headers));
        }
        return new Response($response->body, $response->status, array_merge([
            'content-security-policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            'x-content-type-options' => 'nosniff', 'referrer-policy' => 'same-origin',
            'permissions-policy' => 'camera=(), microphone=(), geolocation=()',
            'cache-control' => 'no-store',
        ], $response->headers));
    }

    private function dispatch(Context $context, View $view): Response
    {
        $request = $context->request;
        $assets = ['/assets/cms.css' => 'text/css', '/assets/cms.js' => 'text/javascript', '/assets/mark.svg' => 'image/svg+xml'];
        if (isset($assets[$request->path])) {
            if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                return new Response('', 405, ['allow' => 'GET, HEAD']);
            }
            $bytes = file_get_contents(dirname(__DIR__, 2) . '/public' . $request->path);
            if ($bytes === false) {
                throw new \RuntimeException('Missing CMS asset.');
            }
            return new Response($bytes, 200, ['content-type' => $assets[$request->path]]);
        }
        if (in_array($request->path, ['/health', '/api/schema', '/llms.txt'], true) && !in_array($request->method, ['GET', 'HEAD'], true)) {
            return new Response('', 405, ['allow' => 'GET, HEAD']);
        }
        if ($request->path === '/health') {
            return Response::json(['status' => $this->cms->identity->owner() === null ? 'setup_required' : 'ok']);
        }
        if ($request->path === '/api/schema') {
            $schema = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.json');
            if ($schema === false) {
                throw new \RuntimeException('Missing API schema.');
            }
            return new Response($schema, 200, ['content-type' => 'application/json']);
        }
        if ($request->path === '/llms.txt') {
            $guide = file_get_contents(dirname(__DIR__, 2) . '/docs/agents.txt');
            if ($guide === false) {
                throw new \RuntimeException('Missing agent guide.');
            }
            return Response::text($guide);
        }
        if ($this->cms->identity->owner() === null) {
            throw new Failure(503, 'setup_required', 'This publication is being prepared.');
        }
        if (str_starts_with($request->path, '/api/')) {
            $caller = $this->cms->identity->authenticateToken($request->headers['authorization'] ?? '');
            $api = new Api($this->cms, $context, $caller);
            return (new Application(
                new Route('GET', '/api/pages', $api->pages(...)),
                new Route('POST', '/api/pages', $api->pages(...)),
                new Route('GET', '/api/pages/{id}', $api->page(...)),
                new Route('PUT', '/api/pages/{id}', $api->page(...)),
                new Route('GET', '/api/pages/{id}/history', $api->history(...)),
                new Route('POST', '/api/pages/{id}/{action}', $api->change(...)),
                new Route('GET', '/api/media', $api->media(...)),
                new Route('POST', '/api/media', $api->media(...)),
                new Route('PATCH', '/api/media/{id}', $api->image(...)),
                new Route('DELETE', '/api/media/{id}', $api->image(...)),
            ))->handle($request);
        }
        $session = $this->cms->identity->session($context->cookie('stage_cms'));
        if ($request->path === '/admin' || str_starts_with($request->path, '/admin/')) {
            if ($request->path !== '/admin/login' && !$session?->authenticated) {
                return Web::redirect('/admin/login');
            }
            if ($session === null) {
                if ($request->method === 'POST') {
                    throw new Failure(403, 'expired_session', 'Your session expired. Sign in again.');
                }
                $session = $this->cms->identity->newSession();
            }
            if ($request->method === 'POST' && !hash_equals($session->csrf, Input::text($context->form(), 'csrf', ''))) {
                throw new Failure(403, 'invalid_csrf', 'This form expired. Reload the page and try again.');
            }
            $web = new Web($this->cms, $context, $session, $view, $this->theme);
            return (new Application(
                new Route('GET', '/admin', fn () => Web::redirect('/admin/pages')),
                new Route('GET', '/admin/login', $web->login(...)),
                new Route('POST', '/admin/login', $web->login(...)),
                new Route('POST', '/admin/logout', $web->logout(...)),
                new Route('GET', '/admin/pages', $web->pages(...)),
                new Route('GET', '/admin/pages/new', $web->editor(...)),
                new Route('POST', '/admin/pages/new', $web->editor(...)),
                new Route('GET', '/admin/pages/{id}', $web->editor(...)),
                new Route('POST', '/admin/pages/{id}', $web->editor(...)),
                new Route('GET', '/admin/pages/{id}/history', $web->history(...)),
                new Route('GET', '/admin/pages/{id}/preview', $web->preview(...)),
                new Route('POST', '/admin/pages/{id}/{action}', $web->change(...)),
                new Route('GET', '/admin/media', $web->media(...)),
                new Route('POST', '/admin/media', $web->media(...)),
                new Route('POST', '/admin/media/{id}', $web->image(...)),
                new Route('POST', '/admin/media/{id}/{action}', $web->image(...)),
                new Route('GET', '/admin/agents', $web->agents(...)),
                new Route('POST', '/admin/agents', $web->agents(...)),
                new Route('POST', '/admin/agents/{id}/revoke', $web->revoke(...)),
                new Route('GET', '/admin/settings', $web->settings(...)),
                new Route('POST', '/admin/settings', $web->settings(...)),
                new Route('POST', '/admin/export', $web->export(...)),
                new Route('GET', '/admin/help', $web->help(...)),
            ))->handle($request);
        }
        return (new Application(
            new Route('GET', '/', function () use ($context): Response {
                $number = Input::integer($context->query()['page'] ?? 1);
                return $this->theme->index($number);
            }),
            new Route('GET', '/media/{id}', function (Request $request) use ($session): Response {
                $id = $request->parameters['id'];
                $caller = isset($request->headers['authorization']) ? $this->cms->identity->authenticateToken($request->headers['authorization'])
                    : ($session?->authenticated ? $session->caller() : null);
                $bytes = $this->cms->media->bytes($id, $caller);
                return new Response($bytes, 200, ['content-type' => $this->cms->media->get($id)->mime]);
            }),
            new Route('GET', '/{slug}', function (Request $request): Response {
                $page = $this->cms->pages->publishedPage($request->parameters['slug']);
                return $this->theme->page($page);
            }),
        ))->handle($request);
    }
}
