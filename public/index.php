<?php
declare(strict_types=1);

use Stage\Http\HttpError;
use Stage\Http\Request;
use Stage\Http\Response;
use StageCms\Cms;
use StageCms\Http\Kernel;
use StageCms\Infrastructure\Config;
use StageCms\Input;

require dirname(__DIR__) . '/vendor/autoload.php';

umask(0077);
try {
    $request = Request::fromGlobals(8388608);
    if (isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 8388608) {
        throw new HttpError(413);
    }
    if (str_starts_with($request->headers['content-type'] ?? '', 'multipart/form-data')) {
        $request = new Request($request->method, $request->path, http_build_query($_POST), $request->headers, $request->query);
    }
    $cms = new Cms(Config::environment(dirname(__DIR__)));
    $response = (new Kernel($cms))->handle($request, Input::object($_FILES), is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
} catch (HttpError $error) {
    $response = Response::json(['error' => ['code' => 'invalid_request', 'message' => 'Check the request size and format.']], $error->status);
} catch (Throwable $error) {
    error_log('Stage CMS startup failed: ' . $error::class);
    $response = Response::text('The publication is temporarily unavailable.', 503);
}
$response->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
