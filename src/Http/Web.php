<?php
declare(strict_types=1);

namespace StageCms\Http;

use Stage\Http\Request;
use Stage\Http\Response;
use StageCms\Cms;
use StageCms\Content\Draft;
use StageCms\Failure;
use StageCms\Identity\Session;
use StageCms\Infrastructure\Archive;
use StageCms\Input;
use StageCms\Presentation\View;

final readonly class Web
{
    public function __construct(private Cms $cms, private Context $context, private Session $session, private View $view) {}

    public static function redirect(string $path): Response
    {
        return new Response('', 303, ['location' => $path]);
    }

    public function login(Request $request): Response
    {
        if ($this->session->authenticated) {
            return self::redirect('/admin/pages');
        }
        if ($request->method !== 'POST') {
            return $this->withCookie(Response::html($this->view->login($this->session)), $this->session);
        }
        $input = $this->context->form();
        try {
            $session = $this->cms->identity->login($this->session, Input::text($input, 'email'), Input::text($input, 'password'), $this->context->address);
            return $this->withCookie(self::redirect('/admin/pages'), $session);
        } catch (Failure $error) {
            return Response::html($this->view->login($this->session, $error->getMessage()), $error->status);
        }
    }

    public function logout(Request $request): Response
    {
        $this->cms->identity->logout($this->session);
        return new Response('', 303, ['location' => '/admin/login', 'set-cookie' => 'stage_cms=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax' . ($this->cms->config->secureCookie() ? '; Secure' : '')]);
    }

    public function pages(Request $request): Response
    {
        $input = $this->context->query();
        $filter = Input::text($input, 'status', 'all');
        $query = Input::text($input, 'q', '');
        $number = Input::integer($input['page'] ?? 1);
        $pages = $this->cms->pages->list($this->session->caller(), $filter, $query, $number);
        return $this->shell('Pages', 'pages', $this->view->pages($pages, $filter, $query)
            . View::pagination('/admin/pages', $number, count($pages), ['status' => $filter, 'q' => $query]));
    }

    public function editor(Request $request): Response
    {
        $id = $request->parameters['id'] ?? 'new';
        $page = $id === 'new' ? null : $this->cms->pages->get($this->session->caller(), $id);
        $values = $page === null ? [] : array_merge($page->draft->data(), ['cover' => $page->draft->cover ?? '', 'expected_version' => (string) $page->version]);
        $error = '';
        $status = 200;
        if ($request->method === 'POST') {
            $values = $this->context->form();
            try {
                $draft = Draft::fromInput($values);
                $publish = Input::text($values, 'intent', 'save') === 'publish';
                $saved = $page === null ? $this->cms->pages->create($this->session->caller(), $draft, $publish)
                    : $this->cms->pages->save($this->session->caller(), $id, $draft, Input::integer($values['expected_version'] ?? null), $publish);
                return self::redirect('/admin/pages/' . $saved->id . '?notice=' . ($publish ? 'published' : 'saved'));
            } catch (Failure $failure) {
                $error = $failure->getMessage();
                $status = $failure->status;
            }
        }
        $body = $this->view->editor($page, $values, $this->cms->media->list($this->session->caller()), $this->session, $status !== 200);
        if ($status === 409 && $page !== null) {
            $body = '<p class="conflict-link"><a href="/admin/pages/' . $page->id . '" target="_blank" rel="noopener">Open the latest version in another tab ↗</a></p>' . $body;
        }
        return $this->shell($page?->draft->title ?? 'New page', 'pages', $body, $error, $status);
    }

    public function change(Request $request): Response
    {
        $input = $this->context->form();
        $id = $request->parameters['id'];
        $version = Input::integer($input['expected_version'] ?? null);
        match ($request->parameters['action']) {
            'unpublish' => $this->cms->pages->unpublish($this->session->caller(), $id, $version),
            'archive' => $this->cms->pages->archive($this->session->caller(), $id, $version),
            'recover' => $this->cms->pages->recover($this->session->caller(), $id, $version),
            'restore' => $this->cms->pages->restore($this->session->caller(), $id, Input::integer($input['revision'] ?? null), $version),
            default => throw new Failure(404, 'not_found', 'That operation does not exist.'),
        };
        return self::redirect('/admin/pages/' . $id . '?notice=' . $request->parameters['action']);
    }

    public function history(Request $request): Response
    {
        $page = $this->cms->pages->get($this->session->caller(), $request->parameters['id']);
        $number = Input::integer($this->context->query()['page'] ?? 1);
        $history = $this->cms->pages->history($this->session->caller(), $page->id, $number);
        return $this->shell('History', 'pages', $this->view->history($page, $history, $this->session)
            . View::pagination('/admin/pages/' . $page->id . '/history', $number, count($history)));
    }

    public function preview(Request $request): Response
    {
        $page = $this->cms->pages->get($this->session->caller(), $request->parameters['id']);
        return Response::html($this->view->story($page, true, $page->draft->cover === null ? '' : $this->cms->media->get($page->draft->cover)->alt));
    }

    public function media(Request $request): Response
    {
        $error = '';
        $status = 200;
        if ($request->method === 'POST') {
            try {
                $image = $this->context->image();
                $this->cms->media->upload($this->session->caller(), $image['name'], $image['bytes'], Input::text($this->context->form(), 'alt', ''));
                return self::redirect('/admin/media?notice=uploaded');
            } catch (Failure $failure) {
                $error = $failure->getMessage();
                $status = $failure->status;
            }
        }
        return $this->shell('Media', 'media', $this->view->media($this->cms->media->list($this->session->caller()), $this->session), $error, $status);
    }

    public function image(Request $request): Response
    {
        $id = $request->parameters['id'];
        if (($request->parameters['action'] ?? '') === 'delete') {
            $this->cms->media->delete($this->session->caller(), $id);
        } else {
            $this->cms->media->describe($this->session->caller(), $id, Input::text($this->context->form(), 'alt'));
        }
        return self::redirect('/admin/media?notice=updated');
    }

    public function agents(Request $request): Response
    {
        $secret = null;
        $error = '';
        $status = 200;
        if ($request->method === 'POST') {
            try {
                $input = $this->context->form();
                $scopes = $input['scopes'] ?? [];
                if (!is_array($scopes) || !array_is_list($scopes)) {
                    throw new Failure(422, 'invalid_scopes', 'Choose the permissions for this connection.');
                }
                foreach ($scopes as $scope) {
                    if (!is_string($scope)) {
                        throw new Failure(422, 'invalid_scopes', 'Choose valid permissions.');
                    }
                }
                $scopes[] = 'content:read';
                $secret = $this->cms->identity->createToken($this->session->caller(), Input::text($input, 'name'), $scopes, Input::integer($input['days'] ?? null));
            } catch (Failure $failure) {
                $error = $failure->getMessage();
                $status = $failure->status;
            }
        }
        return $this->shell('Agents', 'agents', $this->view->agents($this->cms->identity->tokens($this->session->caller()), $this->session, $secret), $error, $status);
    }

    public function revoke(Request $request): Response
    {
        $this->cms->identity->revokeToken($this->session->caller(), $request->parameters['id']);
        return self::redirect('/admin/agents?notice=revoked');
    }

    public function settings(Request $request): Response
    {
        if ($request->method === 'POST') {
            $input = $this->context->form();
            $this->cms->settings->save($this->session->caller(), Input::text($input, 'title'), Input::text($input, 'description'));
            return self::redirect('/admin/settings?notice=updated');
        }
        return $this->shell('Settings', 'settings', $this->view->settings($this->cms->settings->get(), $this->session));
    }

    public function export(Request $request): Response
    {
        return new Response((new Archive($this->cms))->export($this->session->caller()), 200, [
            'content-type' => 'application/zip', 'content-disposition' => 'attachment; filename="publication-' . gmdate('Y-m-d') . '.zip"',
        ]);
    }

    public function help(Request $request): Response
    {
        return $this->shell('Guide', '', $this->view->help());
    }

    private function shell(string $title, string $section, string $body, string $error = '', int $status = 200): Response
    {
        $notice = match (Input::text($this->context->query(), 'notice', '')) {
            'saved' => 'Draft saved. Your words are safe here.',
            'published' => 'Published. Your page is ready to read.',
            'unpublish' => 'Page unpublished. It is now a private draft.',
            'archive' => 'Page archived. Recover it whenever you need it.',
            'recover', 'restore' => 'Restored as a draft.',
            'uploaded' => 'Image added to your library.',
            'revoked' => 'Connection revoked. That token no longer has access.',
            'updated' => 'Changes saved.',
            default => '',
        };
        return Response::html($this->view->shell($title, $section, $body, $this->session, $notice, $error), $status);
    }

    private function withCookie(Response $response, Session $session): Response
    {
        return new Response($response->body, $response->status, array_merge($response->headers, [
            'set-cookie' => 'stage_cms=' . $session->secret . '; Path=/; Max-Age=28800; HttpOnly; SameSite=Lax' . ($this->cms->config->secureCookie() ? '; Secure' : ''),
        ]));
    }
}
