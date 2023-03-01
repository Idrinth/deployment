<?php

use BrandEmbassy\FileTypeDetector\Detector;
use BrandEmbassy\FileTypeDetector\FileInfo;
use De\Idrinth\Yaml\Yaml;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

require_once (__DIR__ . '/../vendor/autoload.php');

function getRemoteMacAdress(){
    foreach(explode("\n", `arp -n`) as $line){
        $cols=preg_split('/\s+/', trim($line));

        if ($cols[0]==$_SERVER['REMOTE_ADDR']){
            return $cols[2];
        }
    }

    return false;
}
function getMime(string $file): string
{
    $out = Detector::detectByContent($file);        
    if (!($out instanceof FileInfo)) {
        error_log('Couldn\'t detect mime type.');
        return 'application/octet-stream';
    }
    return $out->getMimeType();
}

$mac = getRemoteMacAdress();

if ($mac === false) {
    header ('Content-Type: text/plain; charset=utf-8', true, 403);
    echo '403 FORBIDDEN';
    exit;
}
foreach (Yaml::decodeFromFile(__DIR__ . '/../config.yml') as $allowed) {
    if ($allowed === $mac) {
        
        $dispatcher = simpleDispatcher(function(RouteCollector $r) {
            $r->addRoute('GET', '/', function () {
                $data = '';
                foreach (array_diff(scandir(__DIR__ . '/../storage'), ['.', '..']) as $file) {
                    $data .= "<li><a href=\"/$file\">$file</a></li>";
                }
                return "<!DOCTYPE HTML><html><head><title>Overview</title><meta charset=\"utf-8\"/></head><body><ul>$data</ul></body>";
            });
            $r->addRoute('GET', '/{file:[a-zA-Z0-9_-]+(\.[a-u0-9A-Z]+)?}', function ($file): string {
                $file = __DIR__ . '/../storage/' . $file;
                if (!is_file($file)) {
                    header ('Content-Type: text/plain; charset=utf-8', true, 404);
                    return '404 NOT FOUND';
                }
                header('Content-Type: '.getMime($file), true, 200);
                header('Content-Disposition: attachment; filename="' . $file . '"');
                if ($pointer = fopen($file, 'r')) {
                    fpassthru($pointer);
                    fclose($pointer);
                }
                return '';
            });
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