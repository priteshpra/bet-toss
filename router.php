<?php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#^/(data|vendor|lib)(/|$)#', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}
if ($uri === '/' || $uri === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}
$path = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if (is_file($path)) {
    return false;
}
http_response_code(404);
echo 'Not found';
return true;
