<?php
declare(strict_types=1);

namespace StageCms\Http;

use Stage\Http\Request;
use Stage\Http\Response;
use Stage\Security\Caller;
use StageCms\Cms;
use StageCms\Content\Draft;
use StageCms\Failure;
use StageCms\Input;

final readonly class Api
{
    public function __construct(private Cms $cms, private Context $context, private Caller $caller) {}

    public function pages(Request $request): Response
    {
        if ($request->method === 'POST') {
            return Response::json($this->cms->pages->create($this->caller, Draft::fromInput($this->context->json(['title', 'slug', 'excerpt', 'body', 'cover'])))->data(), 201);
        }
        $query = $this->context->query();
        $number = Input::integer($query['page'] ?? 1);
        $pages = $this->cms->pages->list($this->caller, Input::text($query, 'status', 'all'), Input::text($query, 'q', ''), $number);
        return Response::json(['pages' => array_map(fn ($page) => $page->data(),
            $pages), 'page' => $number, 'next_page' => count($pages) === 50 ? $number + 1 : null]);
    }

    public function page(Request $request): Response
    {
        $id = $request->parameters['id'];
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return Response::json($this->cms->pages->get($this->caller, $id)->data());
        }
        $input = $this->context->json(['title', 'slug', 'excerpt', 'body', 'cover', 'expected_version']);
        return Response::json($this->cms->pages->save($this->caller, $id, Draft::fromInput($input),
            Input::integer($input['expected_version'] ?? null))->data());
    }

    public function history(Request $request): Response
    {
        $number = Input::integer($this->context->query()['page'] ?? 1);
        $revisions = $this->cms->pages->history($this->caller, $request->parameters['id'], $number);
        return Response::json(['revisions' => array_map(fn ($page) => $page->data(), $revisions),
            'page' => $number, 'next_page' => count($revisions) === 50 ? $number + 1 : null]);
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
        return Response::json($page->data());
    }

    public function media(Request $request): Response
    {
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return Response::json(['media' => array_map(fn ($asset) => $asset->data(), $this->cms->media->list($this->caller))]);
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
