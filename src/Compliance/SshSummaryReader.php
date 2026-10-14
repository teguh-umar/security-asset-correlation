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
            $user . '@' . $host,
            // The remote forced-command ignores this and always runs its
            // own `cat <fixed path>` — sent anyway so a key installed
            // WITHOUT the forced-command restriction still does the right
            // thing instead of opening an interactive shell.
            'cat ' . escapeshellarg($remotePath),
        ];
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
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return ['ok' => $exitCode === 0, 'exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
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
