<?php

namespace GlpiPlugin\Sac;

/**
 * The one outbound HTTP path in this plugin (endoflife.date).
 *
 * Always goes through `Toolbox::getURLContent()` when GLPI is loaded, which
 * honours the proxy configured in Setup > General > Network
 * (`CFG_GLPI['proxy_name']` / `proxy_port` / `proxy_user` / `proxy_passwd`,
 * core 11.0.5 `Toolbox::doCallCurl()`). Never build a parallel transport that
 * skips it: on a VM with no direct egress, the proxy is the only way out.
 */
final class Http
{
    /** Sent so the far end can identify the caller; some edges reject a blank UA. */
    public const USER_AGENT = 'GLPI-SAC-plugin/1.6 (+https://github.com/teguh-umar/security-asset-correlation)';

    /** @var callable(string,int):?string|null */
    public static $transport = null;

    /** Why the last get() returned null. '' when the last call succeeded. */
    private static string $lastError = '';

    /**
     * Human-readable reason the last get() failed — GLPI's own message plus the
     * underlying curl error. Callers log this; until v1.5.x it was discarded,
     * so a failed fetch only ever showed up as an `errors` counter with no cause.
     */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function get(string $url, int $timeout = 15): ?string
    {
        self::$lastError = '';

        if (self::$transport !== null) {
            $body = (self::$transport)($url, $timeout);
            if ($body === null) {
                self::$lastError = 'stub transport returned null';
            }
            return $body;
        }

        if (class_exists('\\Toolbox') && method_exists('\\Toolbox', 'getURLContent')) {
            // getURLContent() checks the URL against GLPI_SERVERSIDE_URL_ALLOWLIST
            // first (Toolbox::isUrlSafe); a locally narrowed allowlist blocks the
            // call before any network traffic, and that is reported here too.
            $msgerr = null;
            $body   = \Toolbox::getURLContent($url, $msgerr, 0, [
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            if (is_string($body) && $body !== '') {
                return $body;
            }
            self::$lastError = is_string($msgerr) && $msgerr !== ''
                ? $msgerr
                : 'empty response and no error reported by GLPI';
            return null;
        }

        // No GLPI runtime (offline tests only) — this path ignores the proxy.
        $ctx  = stream_context_create(['http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'user_agent'    => self::USER_AGENT,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && $body !== '') {
            return $body;
        }
        $last = error_get_last();
        self::$lastError = 'direct fetch failed (no GLPI proxy support on this path)'
            . (isset($last['message']) ? ': ' . $last['message'] : '');
        return null;
    }

    public static function selfChecks(): array
    {
        return [
            'http/transport-override' => static function (): void {
                self::$transport = static fn(string $u, int $t): ?string => "body:$u";
                sac_assert_eq(self::get('https://x/y'), 'body:https://x/y', 'stub used');
                sac_assert_eq(self::lastError(), '', 'no error recorded on success');
                self::$transport = null;
            },
            'http/records-last-error' => static function (): void {
                self::$transport = static fn(string $u, int $t): ?string => null;
                sac_assert_eq(self::get('https://x/y'), null, 'failure returns null');
                sac_assert(self::lastError() !== '', 'a reason is recorded for the caller to log');
                self::$transport = null;
            },
        ];
    }
}
