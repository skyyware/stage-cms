<?php
declare(strict_types=1);

$target = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($target) ? explode('?', $target, 2)[0] : '/';
if (in_array($path, ['/assets/cms.css', '/assets/cms.js', '/assets/mark.svg'], true)) {
    return false;
}
require __DIR__ . '/index.php';
