<?php

namespace Spatie\MediaLibrary\Tests\TestSupport;

use RuntimeException;

/**
 * PHP's built-in web server on a free local port, so url downloads are tested without the internet.
 */
class LocalHttpServer
{
    /** @var resource|null */
    protected static $process = null;

    /** @var array<int, resource> */
    protected static array $pipes = [];

    protected static int $port = 0;

    public static function url(string $path = ''): string
    {
        static::start();

        return 'http://127.0.0.1:'.static::$port.$path;
    }

    public static function port(): int
    {
        static::start();

        return static::$port;
    }

    public static function start(): void
    {
        if (static::$process !== null) {
            return;
        }

        foreach (range(1, 20) as $attempt) {
            $port = random_int(20000, 60000);

            // Quiet mode leaves only the startup line on the pipes, so they never fill up. Pipes and not
            // files, because mutation testing replaces the file stream wrapper, which proc_open can't use.
            $process = proc_open(
                [PHP_BINARY, '-q', '-S', "127.0.0.1:{$port}", __DIR__.'/HttpServer/router.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            foreach (range(1, 100) as $wait) {
                if ($connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1)) {
                    fclose($connection);

                    static::$process = $process;
                    static::$pipes = $pipes;
                    static::$port = $port;

                    register_shutdown_function([static::class, 'stop']);

                    return;
                }

                if (! proc_get_status($process)['running']) {
                    break;
                }

                usleep(20000);
            }

            proc_terminate($process);
            array_map(fclose(...), $pipes);
        }

        throw new RuntimeException('Could not start the local http server');
    }

    public static function stop(): void
    {
        if (static::$process === null) {
            return;
        }

        proc_terminate(static::$process);
        array_map(fclose(...), static::$pipes);
        proc_close(static::$process);

        static::$process = null;
        static::$pipes = [];
    }
}
