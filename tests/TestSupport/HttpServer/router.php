<?php

// Router for PHP's built-in web server, see LocalHttpServer.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);

switch (true) {
    case str_starts_with($path, '/files/'):
        $file = __DIR__.'/../testfiles/'.basename(rawurldecode(substr($path, strlen('/files/'))));

        if (! is_file($file)) {
            http_response_code(404);

            return;
        }

        header('Content-Type: '.mime_content_type($file));
        readfile($file);

        return;

    case $path === '/redirect':
        header('Location: '.$query['to'], true, (int) ($query['status'] ?? 302));

        return;

    case $path === '/redirect-loop':
        header('Location: /redirect-loop', true, 302);

        return;

    case $path === '/large':
        // Streams the given number of bytes without announcing a length.
        $remaining = (int) $query['bytes'];

        while ($remaining > 0) {
            $chunk = min($remaining, 65536);
            echo str_repeat('a', $chunk);
            flush();
            $remaining -= $chunk;
        }

        return;

    case $path === '/status':
        http_response_code((int) $query['code']);
        echo 'the body of the response';

        return;

    case $path === '/host':
        echo $_SERVER['HTTP_HOST'];

        return;

    default:
        http_response_code(404);
}
