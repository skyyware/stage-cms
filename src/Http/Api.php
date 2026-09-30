<?php
declare(strict_types=1);

namespace StageCms\Http;

use Stage\Http\Request;
use Stage\Http\Response;
use Stage\Security\Caller;
use StageCms\Cms;
use StageCms\Content\Draft;
use StageCms\Content\Page;
use StageCms\Content\PageSummary;
use StageCms\Failure;
use StageCms\Input;

final readonly class Api
{
    public function __construct(private Cms $cms, private Context $context, private Caller $caller) {}

    public function pages(Request $request): Response
    {
        if ($request->method === 'POST') {
            return Response::json(self::pageData($this->cms->pages->create($this->caller, Draft::fromInput($this->context->json(['title', 'slug', 'excerpt', 'body', 'cover', 'type', 'locale', 'fields'])))), 201);
        }
        $query = $this->context->query();
        $number = Input::integer($query['page'] ?? 1);
        $pages = $this->cms->pages->browse($this->caller, Input::text($query, 'status', 'all'), Input::text($query, 'q', ''), $number, $this->includeBody());
        return Response::json(['pages' => array_map(self::pageData(...),
            $pages->items), 'page' => $number, 'next_page' => $pages->nextPage]);
    }

    public function page(Request $request): Response
    {
        $id = $request->parameters['id'];
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return Response::json(self::pageData($this->cms->pages->get($this->caller, $id)));
        }
        $input = $this->context->json(['title', 'slug', 'excerpt', 'body', 'cover', 'type', 'locale', 'fields', 'expected_version']);
        return Response::json(self::pageData($this->cms->pages->save($this->caller, $id, Draft::fromInput($input),
            Input::integer($input['expected_version'] ?? null))));
    }

    public function history(Request $request): Response
    {
        $number = Input::integer($this->context->query()['page'] ?? 1);
        $revisions = $this->cms->pages->revisions($this->caller, $request->parameters['id'], $number, $this->includeBody());
        return Response::json(['revisions' => array_map(self::pageData(...), $revisions->items),
            'page' => $number, 'next_page' => $revisions->nextPage]);
    }

    public function published(Request $request): Response
    {
        $this->caller->require('content:read');
        return Response::json(self::pageData($this->cms->pages->publishedById($request->parameters['id'])));
    }

    public function translations(Request $request): Response
    {
        $id = $request->parameters['id'];
        if ($request->method === 'POST') {
            $input = $this->context->json(['locale', 'slug', 'expected_version']);
            return Response::json(self::pageData($this->cms->pages->translate($this->caller, $id, Input::text($input, 'locale'), Input::text($input, 'slug'), Input::integer($input['expected_version'] ?? null))), 201);
        }
        return Response::json(['translations' => array_map(self::pageData(...), $this->cms->pages->translations($this->caller, $id))]);
    }

    public function revision(Request $request): Response
    {
        return Response::json(self::pageData($this->cms->pages->revision($this->caller, $request->parameters['id'], Input::integer($request->parameters['version']))));
    }

    /** @return array<string, mixed> */
    private static function pageData(PageSummary $page): array
    {
        $data = $page->data();
        if ($page instanceof Page) {
            $data['fields'] = (object) $page->draft->fields;
        }
        return $data;
    }

    private function includeBody(): bool
    {
        return match (Input::text($this->context->query(), 'include', '')) {
            '' => false,
            'body' => true,
            default => throw new Failure(422, 'invalid_include', 'Use include=body for full content, or omit include for summaries.'),
        };
    }

    public function change(Request $request): Response
    {
        $input = $this->context->json($request->parameters['action'] === 'restore' ? ['expected_version', 'revision'] : ['expected_version']);
        $id = $request->parameters['id'];
        $version = Input::integer($input['expected_version'] ?? null);
        $page = match ($request->parameters['action']) {
            'publish' => $this->cms->pages->publish($this->caller, $id, $version),
            'unpublish' => $this->cms->pages->unpublish($this->caller, $id, $version),
            'archive' => $this->cms->pages->archive($this->caller, $id, $version),
            'recover' => $this->cms->pages->recover($this->caller, $id, $version),
            'restore' => $this->cms->pages->restore($this->caller, $id, Input::integer($input['revision'] ?? null), $version),
            default => throw new Failure(404, 'not_found', 'That operation does not exist.'),
        };
        return Response::json(self::pageData($page));
    }

    public function media(Request $request): Response
    {
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            $query = $this->context->query();
            $media = $this->cms->media->browse($this->caller, Input::text($query, 'q', ''), Input::integer($query['page'] ?? 1));
            return Response::json(['media' => array_map(fn ($asset) => $asset->data(), $media->items), 'page' => $media->number, 'next_page' => $media->nextPage]);
        }
        $input = $this->context->json(['name', 'base64', 'alt']);
        $bytes = base64_decode(Input::text($input, 'base64'), true);
        if ($bytes === false) {
            throw new Failure(422, 'invalid_image', 'Send valid base64 image bytes.');
        }
        return Response::json($this->cms->media->upload($this->caller, Input::text($input, 'name'), $bytes, Input::text($input, 'alt', ''))->data(), 201);
    }

    public function image(Request $request): Response
    {
        $id = $request->parameters['id'];
        if ($request->method === 'DELETE') {
            $this->cms->media->delete($this->caller, $id);
            return new Response('', 204);
        }
        $input = $this->context->json(['alt']);
        $this->cms->media->describe($this->caller, $id, Input::text($input, 'alt'));
        return Response::json($this->cms->media->get($id)->data());
    }
}
