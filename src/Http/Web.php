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
use StageCms\Presentation\Theme;
use StageCms\Presentation\Themes;

final readonly class Web
{
    public function __construct(private Cms $cms, private Context $context, private Session $session, private View $view, private ?Theme $theme = null) {}

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
        $pages = $this->cms->pages->browse($this->session->caller(), $filter, $query, $number);
        return $this->shell('Pages', 'pages', $this->view->pages($pages->items, $filter, $query)
            . View::pagination('/admin/pages', $number, count($pages->items), ['status' => $filter, 'q' => $query], $pages->nextPage !== null));
    }

    public function editor(Request $request): Response
    {
        $id = $request->parameters['id'] ?? 'new';
        $page = $id === 'new' ? null : $this->cms->pages->get($this->session->caller(), $id);
        $values = $page === null ? [] : array_merge($page->draft->data(), ['cover' => $page->draft->cover ?? '', 'expected_version' => (string) $page->version]);
        $error = '';
        $status = 200;
        $errors = [];
        $translations = $page === null ? [] : $this->cms->pages->translations($this->session->caller(), $id);
        if ($request->method === 'POST') {
            $values = $this->context->form();
            try {
                $intent = Input::text($values, 'intent', 'save');
                if ($intent === 'type') {
                    $this->cms->types->get(Input::text($values, 'type', 'page'));
                    return $this->shell($page->title ?? 'New page', 'pages', $this->view->editor($page, $values, [], $this->session, true, translations: $translations));
                }
                $definition = $this->cms->types->get(Input::text($values, 'type', 'page'));
                $keys = array_fill_keys(array_map(fn ($field) => $field->key, $definition->fields), true);
                $values['fields'] = array_filter(Input::object($values['fields'] ?? []), fn ($value, $key) => $value !== '' || isset($keys[$key]), ARRAY_FILTER_USE_BOTH);
                $draft = Draft::fromInput($values);
                $publish = $intent === 'publish';
                $saved = $page === null ? $this->cms->pages->create($this->session->caller(), $draft, $publish)
                    : $this->cms->pages->save($this->session->caller(), $id, $draft, Input::integer($values['expected_version'] ?? null), $publish);
                return self::redirect('/admin/pages/' . $saved->id . ($intent === 'cover' ? '/cover' : '?notice=' . ($publish ? 'published' : 'saved')));
            } catch (Failure $failure) {
                $error = $failure->getMessage();
                $status = $failure->status;
                $errors = $failure->errors;
            }
        }
        $cover = Input::text($values, 'cover', '');
        $media = [];
        if ($cover !== '') {
            try {
                $media[] = $this->cms->media->get($cover);
            } catch (Failure) {
                $values['cover'] = '';
            }
        }
        $body = $this->view->editor($page, $values, $media, $this->session, $status !== 200, $errors, $translations);
        if ($status === 409 && $page !== null) {
            $body = '<p class="conflict-link"><a href="/admin/pages/' . $page->id . '" target="_blank" rel="noopener">Open the latest version in another tab ↗</a></p>' . $body;
        }
        return $this->shell($page?->draft->title ?? 'New page', 'pages', $body, $error, $status);
    }

    public function translate(Request $request): Response
    {
        $input = $this->context->form();
        $page = $this->cms->pages->translate($this->session->caller(), $request->parameters['id'], Input::text($input, 'locale'), Input::text($input, 'slug'), Input::integer($input['expected_version'] ?? null));
        return self::redirect('/admin/pages/' . $page->id . '?notice=translated');
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
        $history = $this->cms->pages->revisions($this->session->caller(), $page->id, $number);
        return $this->shell('History', 'pages', $this->view->history($page, $history->items, $this->session)
            . View::pagination('/admin/pages/' . $page->id . '/history', $number, count($history->items), hasMore: $history->nextPage !== null));
    }

    public function revision(Request $request): Response
    {
        $page = $this->cms->pages->get($this->session->caller(), $request->parameters['id']);
        $revision = $this->cms->pages->revision($this->session->caller(), $page->id, Input::integer($request->parameters['version']));
        return $this->shell('Revision ' . $revision->version, 'pages', $this->view->revision($page, $revision, $this->session));
    }

    public function cover(Request $request): Response
    {
        $page = $this->cms->pages->get($this->session->caller(), $request->parameters['id']);
        if ($request->method === 'POST') {
            $input = $this->context->form();
            $draft = new Draft($page->title, $page->slug, $page->excerpt, $page->draft->body, Input::optional($input, 'cover'), $page->type, $page->locale, $page->draft->fields);
            $this->cms->pages->save($this->session->caller(), $page->id, $draft, Input::integer($input['expected_version'] ?? null));
            return self::redirect('/admin/pages/' . $page->id . '?notice=saved');
        }
        $query = $this->context->query();
        $search = Input::text($query, 'q', '');
        $media = $this->cms->media->browse($this->session->caller(), $search, Input::integer($query['page'] ?? 1));
        return $this->shell('Choose a cover', 'pages', $this->view->cover($page, $media->items, $this->session, $search)
            . View::pagination('/admin/pages/' . $page->id . '/cover', $media->number, count($media->items), ['q' => $search], $media->nextPage !== null));
    }

    public function preview(Request $request): Response
    {
        $page = $this->cms->pages->get($this->session->caller(), $request->parameters['id']);
        if ($this->theme !== null) {
            return $this->theme->page($page, true);
        }
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
        $query = $this->context->query();
        $search = Input::text($query, 'q', '');
        $media = $this->cms->media->browse($this->session->caller(), $search, Input::integer($query['page'] ?? 1));
        return $this->shell('Media', 'media', $this->view->media($media->items, $this->session, $search)
            . View::pagination('/admin/media', $media->number, count($media->items), ['q' => $search], $media->nextPage !== null), $error, $status);
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
            $theme = Input::text($input, 'theme', $this->cms->settings->get()['theme']);
            if ($this->theme instanceof Themes) {
                $this->theme->get($theme);
            }
            $this->cms->settings->save($this->session->caller(), Input::text($input, 'title'), Input::text($input, 'description'), $theme);
            return self::redirect('/admin/settings?notice=updated');
        }
        return $this->shell('Settings', 'settings', $this->view->settings($this->cms->settings->get(), $this->session, $this->theme instanceof Themes ? $this->theme : null));
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
            'saved' => 'Draft saved.',
            'published' => 'Page published.',
            'translated' => 'Copied as a draft. Translate the text before publishing.',
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
