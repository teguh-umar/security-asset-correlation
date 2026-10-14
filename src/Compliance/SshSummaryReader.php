<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * Pulls the Tier 1 oscap summary line from a monitored host over a
 * restricted SSH connection — read-only, never a shell: the remote
 * `authorized_keys` entry for this key carries a forced `command="cat
 * <path>"` (see `tools/host-agent/sac-oscap-summary.sh`'s docblock), so
 * whatever command this class asks for is ignored remotely in favour of
 * that one `cat`. This replaces an earlier design that assumed GLPI
 * Inventory's "Collect" feature could capture arbitrary command output —
 * VM verification showed Collect only supports file-metadata/registry/WMI
 * collection, none of which fit a Linux cron's text output, so this reads
 * the file directly instead.
 *
 * Every SSH argument is built as a separate argv element (see
 * {@see buildCommand()}) and handed to `proc_open()` with no shell in
 * between — a target's host/user/path value can never be interpreted as
 * shell syntax, regardless of what it contains.
 */
final class SshSummaryReader
{
    /**
     * PURE. The argv for one restricted SSH pull, wrapped in `timeout` so
     * a host that accepts the TCP connection but then hangs (not just one
     * that refuses it — `ConnectTimeout` alone does not bound that) cannot
     * stall the whole cron run.
     *
     * @return array<int,string>
     */
    public static function buildCommand(
        string $sshBinary,
        string $timeoutBinary,
        string $keyPath,
        string $knownHostsPath,
        string $user,
        string $host,
        int $port,
        string $remotePath,
        int $timeoutSeconds
    ): array {
        // argv-form proc_open keeps a shell out of this entirely, but it
        // does NOT stop `ssh` itself from reading a destination starting
        // with "-" as an OPTION rather than a hostname — an ssh_user like
        // "-oProxyCommand=sh -c 'id>/tmp/pwn'" would run that ProxyCommand
        // locally, on the GLPI host, before the remote forced-command is
        // ever reached (confirmed as a real local-exec path in review).
        // `--` is OpenSSH's own documented "end of options" marker; once
        // past it, `$user@$host` can only ever be read as the destination
        // argument, never as a flag, regardless of its first character.
        // front/sshtargets.php additionally rejects a leading "-" at input
        // time — this is the structural guarantee underneath that UX check.
        //
        // $timeoutSeconds is clamped to at least 1: `timeout 0` (GNU
        // coreutils) means "no limit", which would silently remove the one
        // thing stopping a host that accepts the TCP connection and then
        // hangs from stalling the whole cron run.
        $timeoutSeconds = max(1, $timeoutSeconds);

        return [
            $timeoutBinary,
            (string) $timeoutSeconds,
            $sshBinary,
            '-i', $keyPath,
            '-o', 'UserKnownHostsFile=' . $knownHostsPath,
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=' . $timeoutSeconds,
            '-p', (string) $port,
            '--',
            $user . '@' . $host,
            // The remote forced-command ignores this and always runs its
            // own `cat <fixed path>` — sent anyway so a key installed
            // WITHOUT the forced-command restriction still does the right
            // thing instead of opening an interactive shell.
            'cat ' . escapeshellarg($remotePath),
        ];
    }

    /**
     * PURE. Whether a host/ssh_user value is safe to place in an SSH
     * destination even without the `--` guard above — defense in depth,
     * checked both here (defensively, whatever the value's origin) and at
     * input time in `front/sshtargets.php` (so an admin gets a clear error
     * instead of a silently-skipped target). Deliberately conservative:
     * only hostname/username-shaped characters, and never a leading `-`.
     */
    public static function isSafeSshIdentifier(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1;
    }

    /**
     * Runs an argv command with no shell involved, with a hard wall-clock
     * timeout already baked into $cmd[0] (the `timeout` wrapper) rather
     * than reimplemented here — a `timeout`-wrapped child that still hangs
     * past its bound gets killed by `timeout` itself, which then exits
     * non-zero (124) and makes this call return promptly either way.
     *
     * @param array<int,string> $cmd
     * @return array{ok:bool,exit_code:int,stdout:string,stderr:string}
     */
    public static function runCommand(array $cmd): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);

        // A blocking stream_get_contents() on stdout before stderr is read
        // deadlocks if the child writes enough to stderr to fill its pipe
        // buffer (~64KB) before exiting — it blocks writing stderr while
        // this call blocks reading stdout, and neither side ever proceeds.
        // Reading both via stream_select(), draining whichever is ready,
        // has no such ordering dependency.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffers = [1 => '', 2 => ''];
        $open    = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read   = array_values($open);
            $write  = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $stream) {
                $fd    = (int) array_search($stream, $open, true);
                $chunk = fread($stream, 65536);
                if ($chunk !== '' && $chunk !== false) {
                    $buffers[$fd] .= $chunk;
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$fd]);
                }
            }
        }
        $exitCode = proc_close($proc);

        return ['ok' => $exitCode === 0, 'exit_code' => $exitCode, 'stdout' => $buffers[1], 'stderr' => $buffers[2]];
    }

    /**
     * The full pull for one target: builds the command from config,
     * validates the key/known_hosts files exist on THIS (the GLPI) host
     * before ever shelling out, runs it, and returns the trimmed stdout
     * line or null. Null covers every failure mode uniformly — missing
     * local key material, unreachable host, timeout, remote file absent —
     * so {@see ConfigScan} never has to distinguish "never configured"
     * from "configured but failing right now"; a caller that needs to
     * tell those apart reads $result['stderr'] from {@see runCommand()}
     * directly via {@see fetchRaw()}.
     *
     * @param array{host:string,port:int,ssh_user:string} $target
     * @param array{key_path:string,known_hosts_path:string,remote_path:string,timeout_seconds:int} $sshConfig
     */
    public static function fetch(array $target, array $sshConfig): ?string
    {
        $raw = self::fetchRaw($target, $sshConfig);
        return ($raw !== null && $raw['ok']) ? trim($raw['stdout']) : null;
    }

    /**
     * Same as {@see fetch()} but returns the full run result (or null if
     * local prerequisites — the key/known_hosts files — are missing,
     * which is a configuration error rather than a per-host transport
     * failure and is checked without ever invoking `ssh`).
     *
     * @param array{host:string,port:int,ssh_user:string} $target
     * @param array{key_path:string,known_hosts_path:string,remote_path:string,timeout_seconds:int} $sshConfig
     * @return array{ok:bool,exit_code:int,stdout:string,stderr:string}|null
     */
    public static function fetchRaw(array $target, array $sshConfig): ?array
    {
        if (!is_readable($sshConfig['key_path']) || !is_readable($sshConfig['known_hosts_path'])) {
            return null;
        }

        // Defense in depth alongside buildCommand()'s `--` guard: refuse a
        // host/ssh_user that isn't hostname/username-shaped BEFORE ever
        // building the command, whatever the row's origin (normally
        // front/sshtargets.php already rejected this at input time, but
        // this path has no such guarantee for a row written any other way).
        if (!self::isSafeSshIdentifier($target['host']) || !self::isSafeSshIdentifier($target['ssh_user'])) {
            return null;
        }

        $cmd = self::buildCommand(
            'ssh',
            'timeout',
            $sshConfig['key_path'],
            $sshConfig['known_hosts_path'],
            $target['ssh_user'],
            $target['host'],
            $target['port'],
            $sshConfig['remote_path'],
            $sshConfig['timeout_seconds']
        );

        return self::runCommand($cmd);
    }

    /** @return array<string,callable():void> First two are PURE (run offline); the rest need a real ssh/timeout binary. */
    public static function selfChecks(): array
    {
        return [
            // PURE — runs in tools/offline-selfcheck.php. The single most
            // valuable test here: every argument lands as its OWN argv
            // element, never concatenated into a string a shell could
            // reinterpret, even when a value itself contains shell
            // metacharacters (the `;rm -rf /` host below).
            'sshreader/build-command-argv-shape' => static function (): void {
                $cmd = SshSummaryReader::buildCommand(
                    '/usr/bin/ssh',
                    '/usr/bin/timeout',
                    '/etc/sac-collect/sac_collect_key',
                    '/etc/sac-collect/known_hosts',
                    'pgnusr',
                    '10.129.2.112; rm -rf /',
                    22,
                    '/var/lib/sac/oscap_summary.txt',
                    15
                );
                sac_assert_eq($cmd[0], '/usr/bin/timeout', 'timeout wrapper is argv[0]');
                sac_assert_eq($cmd[1], '15', 'timeout seconds is its own element');
                sac_assert_eq($cmd[2], '/usr/bin/ssh', 'ssh binary follows the timeout wrapper');
                sac_assert(in_array('-i', $cmd, true), 'has -i flag');
                $hostArg = $cmd[array_search('pgnusr@10.129.2.112; rm -rf /', $cmd, true) ?: -1] ?? null;
                sac_assert_eq($hostArg, 'pgnusr@10.129.2.112; rm -rf /', 'the malicious-looking host string is ONE argv element, never split/interpreted');
                sac_assert_eq(end($cmd), 'cat ' . escapeshellarg('/var/lib/sac/oscap_summary.txt'), 'remote command is the last argv element, shell-escaped for defence in depth even though the remote forced-command ignores it');

                $destIndex = array_search('pgnusr@10.129.2.112; rm -rf /', $cmd, true);
                sac_assert_eq($cmd[$destIndex - 1], '--', 'a "--" end-of-options marker immediately precedes the destination, so ssh can never read it as a flag');
            },

            // PURE. A destination starting with "-" is the real local
            // command-execution risk this file guards against: without
            // "--", ssh reads an ssh_user like "-oProxyCommand=..." as an
            // OPTION, not a username, and runs that ProxyCommand locally —
            // on the GLPI host — before ever contacting the remote forced
            // command. "--" alone (tested above) already closes this; this
            // test pins it at the exact value a real attack would use.
            'sshreader/build-command-neutralizes-leading-dash-destination' => static function (): void {
                $cmd = SshSummaryReader::buildCommand(
                    '/usr/bin/ssh',
                    '/usr/bin/timeout',
                    '/etc/sac-collect/sac_collect_key',
                    '/etc/sac-collect/known_hosts',
                    "-oProxyCommand=sh -c 'id>/tmp/pwn'",
                    '10.129.2.112',
                    22,
                    '/var/lib/sac/oscap_summary.txt',
                    15
                );
                $dashUserIndex = array_search("-oProxyCommand=sh -c 'id>/tmp/pwn'@10.129.2.112", $cmd, true);
                sac_assert($dashUserIndex !== false, 'the dangerous-looking destination is present as a single argv element');
                sac_assert_eq($cmd[$dashUserIndex - 1], '--', '"--" precedes it, so ssh treats it as a literal destination, never as -oProxyCommand=...');
            },

            'sshreader/build-command-clamps-zero-timeout' => static function (): void {
                $cmd = SshSummaryReader::buildCommand(
                    '/usr/bin/ssh', '/usr/bin/timeout',
                    '/k', '/kh', 'u', 'h', 22, '/r',
                    0 // admin set compliance_ssh_timeout_seconds to 0
                );
                sac_assert_eq($cmd[1], '1', 'a configured 0 is clamped to 1 — GNU timeout treats 0 as "no limit", which would remove the hang-protection entirely');
                sac_assert(in_array('ConnectTimeout=1', $cmd, true), 'ConnectTimeout gets the same clamped value');
            },

            'sshreader/is-safe-ssh-identifier' => static function (): void {
                sac_assert(SshSummaryReader::isSafeSshIdentifier('pgnusr'), 'plain username is safe');
                sac_assert(SshSummaryReader::isSafeSshIdentifier('10.129.2.112'), 'IPv4 address is safe');
                sac_assert(SshSummaryReader::isSafeSshIdentifier('vm-dev-damara'), 'hyphenated hostname is safe');
                sac_assert(!SshSummaryReader::isSafeSshIdentifier('-oProxyCommand=x'), 'a leading dash is rejected');
                sac_assert(!SshSummaryReader::isSafeSshIdentifier(''), 'empty string is rejected');
                sac_assert(!SshSummaryReader::isSafeSshIdentifier('host; rm -rf /'), 'shell metacharacters are rejected');
            },

            // PURE. __FILE__ is used as a stand-in "key path" only to pass
            // the is_readable() precondition — this test never actually
            // shells out, because the unsafe identifier check must reject
            // it first. Proves the end-to-end guard in fetchRaw(), not just
            // the pure building blocks above.
            'sshreader/fetchraw-rejects-unsafe-identifier-before-shelling-out' => static function (): void {
                $sshConfig = [
                    'key_path'         => __FILE__,
                    'known_hosts_path' => __FILE__,
                    'remote_path'      => '/var/lib/sac/oscap_summary.txt',
                    'timeout_seconds'  => 5,
                ];
                $result = SshSummaryReader::fetchRaw(
                    ['host' => '10.129.2.112', 'port' => 22, 'ssh_user' => '-oProxyCommand=x'],
                    $sshConfig
                );
                sac_assert($result === null, 'an unsafe ssh_user is rejected before ssh is ever invoked');

                $result2 = SshSummaryReader::fetchRaw(
                    ['host' => '-oProxyCommand=x', 'port' => 22, 'ssh_user' => 'pgnusr'],
                    $sshConfig
                );
                sac_assert($result2 === null, 'an unsafe host is rejected before ssh is ever invoked');
            },

            'sshreader/fetchraw-missing-local-files-returns-null' => static function (): void {
                $result = SshSummaryReader::fetchRaw(
                    ['host' => '127.0.0.1', 'port' => 22, 'ssh_user' => 'nobody'],
                    [
                        'key_path'         => '/this/path/does/not/exist/key',
                        'known_hosts_path' => '/this/path/does/not/exist/known_hosts',
                        'remote_path'      => '/var/lib/sac/oscap_summary.txt',
                        'timeout_seconds'  => 5,
                    ]
                );
                sac_assert($result === null, 'missing local key/known_hosts never shells out, returns null directly');
            },

            // PURE but exercises the real proc_open/pipe plumbing, using
            // this dev machine's own php.exe as a stand-in process — the
            // one thing a syntax check alone cannot prove: that stdout is
            // actually captured and the exit code actually reaches the
            // caller, which is exactly the class of bug (blocking reads,
            // an unclosed pipe deadlocking the child) that's easy to get
            // subtly wrong in exec-wrapping code.
            'sshreader/runcommand-captures-stdout-and-exit-code' => static function (): void {
                $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
                $ok = SshSummaryReader::runCommand([$php, '-r', 'echo "hello-from-subprocess";']);
                sac_assert($ok['ok'], 'exit code 0 reported as ok');
                sac_assert_eq($ok['exit_code'], 0, 'exit code captured');
                sac_assert_eq(trim($ok['stdout']), 'hello-from-subprocess', 'stdout captured correctly');

                $fail = SshSummaryReader::runCommand([$php, '-r', 'fwrite(STDERR, "boom"); exit(3);']);
                sac_assert(!$fail['ok'], 'non-zero exit reported as not ok');
                sac_assert_eq($fail['exit_code'], 3, 'non-zero exit code captured');
                sac_assert(str_contains($fail['stderr'], 'boom'), 'stderr captured correctly');
            },
        ];
    }
}
