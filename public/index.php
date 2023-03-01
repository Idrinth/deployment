<?php

use BrandEmbassy\FileTypeDetector\Detector;
use BrandEmbassy\FileTypeDetector\FileInfo;
use De\Idrinth\Yaml\Yaml;
use Dotenv\Dotenv;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

require_once (__DIR__ . '/../vendor/autoload.php');

Dotenv::createImmutable(dirname(__DIR__))->load();

function getMime(string $file): string
{
    $out = Detector::detectByContent($file);        
    if (!($out instanceof FileInfo)) {
        error_log('Couldn\'t detect mime type.');
        return 'application/octet-stream';
    }
    return $out->getMimeType();
}

$headers = apache_request_headers();
$id = $headers[$_ENV['HEADER_KEY_NAME']] ?? false;

if ($id === false) {
    header ('Content-Type: text/plain; charset=utf-8', true, 403);
    echo '403 FORBIDDEN';
    exit;
}
if (isset($_ENV['SECRET_VALUE']) && $_ENV['SECRET_VALUE']) {
    if (!isset($headers[$_ENV['HEADER_SECRET_KEY']]) || $headers[$_ENV['HEADER_SECRET_KEY']] !== $_ENV['SECRET_VALUE']) {
        header ('Content-Type: text/plain; charset=utf-8', true, 403);
        echo '403 FORBIDDEN';
        exit;
    }
}
foreach (Yaml::decodeFromFile(__DIR__ . '/../config.yml') as $allowed) {
    if ($allowed === $id) {
        $download = function (string $file): string {
            $file = __DIR__ . '/../storage/' . preg_replace(['/\/{2,}/', '/\.{2,}/'], ['/', '.'], $file);
            if (!is_file($file)) {
                header ('Content-Type: text/plain; charset=utf-8', true, 404);
                return '404 NOT FOUND';
            }
            header('Content-Type: ' . getMime($file), true, 200);
            header('Content-Disposition: attachment; filename="' . $file . '"');
            if ($pointer = fopen($file, 'r')) {
                fpassthru($pointer);
                fclose($pointer);
            }
            return '';
        };
        $dispatcher = simpleDispatcher(function(RouteCollector $r) use ($download) {
            $r->addRoute('GET', '/', function () {
                $data = '';
                foreach (array_diff(scandir(__DIR__ . '/../storage'), ['.', '..']) as $file) {
                    $size = filesize(__DIR__ . '/../storage/' . $file);
                    if ($size > 0) {
                        $data .= "<li><a href=\"/$file\">$file ($size bytes)</a></li>";
                    }
                }
                return "<!DOCTYPE HTML><html><head><title>Overview</title><meta charset=\"utf-8\"/></head><body><ul>$data</ul></body>";
            });
            $r->addRoute('GET', '/{file:[a-zA-Z0-9_-/]+}', $download);
            $r->addRoute('GET', '/{file:[a-zA-Z0-9_-/]+\.[a-u0-9A-Z]+}', $download);
        });
        $httpMethod = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $routeInfo = $dispatcher->dispatch($httpMethod, rawurldecode($uri));
        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                header('Content-Type: text/plain; charset=utf-8', true, 404);
                echo '404 NOT FOUND';
                exit;
            case Dispatcher::METHOD_NOT_ALLOWED:
                header('Content-Type: text/plain; charset=utf-8', true, 405);
                echo '405 METHOD NOT ALLOWED';
                exit;
            case Dispatcher::FOUND:
                echo $routeInfo[1](...$routeInfo[2]);
                exit;
        }
    }
}
header ('Content-Type: text/plain; charset=utf-8', true, 403);
echo '403 FORBIDDEN';
die();