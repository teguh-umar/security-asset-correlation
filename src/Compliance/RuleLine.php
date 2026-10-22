<?php

namespace GlpiPlugin\Sac\Compliance;

/**
 * Parses one `SACRULE1` line — a single failing SCAP rule, emitted by the
 * host's oscap wrapper alongside the summary line in the same file.
 *
 * Format:
 *   SACRULE1|<rule_id>|<result>|<severity>|<framework_refs>|<title>
 *
 * `title` is LAST on purpose: a rule title may legitimately contain `|`
 * (e.g. "Ensure A | B is set"), and putting it last means those pipes
 * cannot shift any other field.
 */
final class RuleLine
{
    public static function selfChecks(): array
    {
        return [
            'ruleline/parses-well-formed' => static function (): void {
                $r = self::parse('SACRULE1|xccdf_org.ssgproject.content_rule_sshd_disable_root_login|fail|high|CISv7:4,CISv7:5|Disable SSH root login');
                sac_assert($r !== null, 'well-formed line parses');
                sac_assert_eq($r['rule_id'], 'xccdf_org.ssgproject.content_rule_sshd_disable_root_login', 'rule_id');
                sac_assert_eq($r['result'], 'fail', 'result');
                sac_assert_eq($r['severity'], 'high', 'severity');
                sac_assert_eq($r['framework_refs'], 'CISv7:4,CISv7:5', 'framework refs kept verbatim');
                sac_assert_eq($r['title'], 'Disable SSH root login', 'title');
            },
            'ruleline/title-may-contain-pipes' => static function (): void {
                $r = self::parse('SACRULE1|rule_x|fail|low||Ensure A | B is set');
                sac_assert($r !== null, 'parses');
                sac_assert_eq($r['title'], 'Ensure A | B is set', 'a pipe in the title does not shift fields');
                sac_assert_eq($r['framework_refs'], '', 'empty refs field is allowed');
            },
            'ruleline/rejects-malformed' => static function (): void {
                sac_assert(self::parse('') === null, 'empty');
                sac_assert(self::parse('SACCFG1|bm|50|1|2|3|2026-10-14T00:00:00Z') === null, 'a summary line is not a rule line');
                sac_assert(self::parse('SACRULE1|rule_x|fail|low') === null, 'too few fields');
                sac_assert(self::parse('SACRULE1||fail|low||t') === null, 'blank rule id');
                sac_assert(self::parse('SACRULE1|rule_x|pass|low||t') === null, 'only fail/error are stored');
                sac_assert(self::parse('SACRULE2|rule_x|fail|low||t') === null, 'wrong tag');
            },
            'ruleline/normalises-severity' => static function (): void {
                sac_assert_eq(self::parse('SACRULE1|r|fail|HIGH||t')['severity'], 'high', 'severity lowercased');
                sac_assert_eq(self::parse('SACRULE1|r|fail|||t')['severity'], 'unknown', 'missing severity becomes unknown');
            },
        ];
    }

    private const TAG = 'SACRULE1';

    /** Results worth storing — everything else is noise for mitigation. */
    private const KEPT_RESULTS = ['fail', 'error'];

    /**
     * PURE. Parses one rule line. Returns null on ANY malformation, and on a
     * result this module does not store.
     *
     * @return array{rule_id:string,result:string,severity:string,framework_refs:string,title:string}|null
     */
    public static function parse(string $line): ?array
    {
        // Limit 6: the title is last and may legitimately contain '|'.
        $parts = explode('|', trim($line), 6);
        if (count($parts) !== 6 || $parts[0] !== self::TAG) {
            return null;
        }
        [, $ruleId, $result, $severity, $refs, $title] = $parts;

        $ruleId = trim($ruleId);
        $result = strtolower(trim($result));
        if ($ruleId === '' || !in_array($result, self::KEPT_RESULTS, true)) {
            return null;
        }

        $severity = strtolower(trim($severity));
        if ($severity === '') {
            $severity = 'unknown';
        }

        return [
            'rule_id'        => $ruleId,
            'result'         => $result,
            'severity'       => $severity,
            'framework_refs' => trim($refs),
            'title'          => trim($title),
        ];
    }
}
