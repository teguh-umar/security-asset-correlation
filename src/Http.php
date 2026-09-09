<?php

namespace GlpiPlugin\Sac;

final class Http
{
    /** @var callable(string,int):?string|null */
    public static $transport = null;

    public static function get(string $url, int $timeout = 15): ?string
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $timeout);
        }
        if (class_exists('\\Toolbox') && method_exists('\\Toolbox', 'getURLContent')) {
            $body = \Toolbox::getURLContent($url);
            return $body !== false && $body !== '' ? $body : null;
        }
        $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false && $body !== '' ? $body : null;
    }

    public static function selfChecks(): array
    {
        return [
            'http/transport-override' => static function (): void {
                self::$transport = static fn(string $u, int $t): ?string => "body:$u";
                sac_assert_eq(self::get('https://x/y'), 'body:https://x/y', 'stub used');
                self::$transport = null;
            },
        ];
    }
}
