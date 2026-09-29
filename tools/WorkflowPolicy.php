<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tools;

final class WorkflowPolicy
{
    /** @var list<string> */
    private const ALLOWED_THIRD_PARTY = [
        'step-security/harden-runner',
        'shivammathur/setup-php',
        'amannn/action-semantic-pull-request',
        'codecov/codecov-action',
        'codecov/test-results-action',
        'symfonycorp/security-checker-action',
    ];

    /**
     * @return list<string>
     */
    public function check(string $fileName, string $yaml): array
    {
        $errors = [];
        $lines = explode("\n", $yaml);

        if (preg_match('/^permissions:\s*(\{\}|\n)/m', $yaml) !== 1) {
            $errors[] = "{$fileName}: missing top-level 'permissions:' (use 'permissions: {}')";
        }
        if (preg_match('/^\s*pull_request_target\s*:/m', $yaml) === 1) {
            $errors[] = "{$fileName}: 'pull_request_target' is forbidden";
        }
        if (preg_match('/^concurrency:/m', $yaml) !== 1) {
            $errors[] = "{$fileName}: missing top-level 'concurrency:'";
        }

        $jobsSection = explode("\njobs:\n", $yaml, 2)[1] ?? '';
        $jobs = preg_match_all('/^  [a-z0-9_-]+:\s*$/m', $jobsSection);
        $reusable = preg_match_all('/^    uses:\s*\.\//m', $yaml);
        $timeouts = preg_match_all('/^\s+timeout-minutes:/m', $yaml);
        if ($jobs === false || $reusable === false || $timeouts === false) {
            $errors[] = "{$fileName}: failed to parse job metadata";
        } elseif ($timeouts < $jobs - $reusable) {
            $errors[] = "{$fileName}: every job needs 'timeout-minutes'";
        }

        if (substr_count($yaml, 'uses: actions/checkout@') !== substr_count($yaml, 'persist-credentials: false')) {
            $errors[] = "{$fileName}: every actions/checkout needs 'persist-credentials: false'";
        }

        foreach ($lines as $i => $line) {
            if (
                preg_match('/\$\{\{\s*github\.event\.[^}]*\}\}/', $line) === 1
                && preg_match('/^\s*-?\s*run:/', $line) === 1
            ) {
                $errors[] = sprintf('%s:%d: interpolate github.event.* via env:, not inline', $fileName, $i + 1);
            }

            if (preg_match('/^\s*-?\s*uses:\s*(\S+)(.*)$/', $line, $m) !== 1) {
                continue;
            }

            [$ref, $rest] = [$m[1], $m[2]];
            if (str_starts_with($ref, './')) {
                continue;
            }

            if (str_starts_with($ref, 'docker://')) {
                if (preg_match('/@sha256:[0-9a-f]{64}$/', $ref) !== 1) {
                    $errors[] = sprintf('%s:%d: docker action must be pinned by sha256 digest', $fileName, $i + 1);
                }

                continue;
            }

            if (preg_match('#^([\w.-]+/[\w.-]+)(/[\w./-]+)?@([0-9a-f]{40})$#', $ref, $r) !== 1) {
                $errors[] = sprintf('%s:%d: "%s" must be pinned to a full 40-char commit SHA', $fileName, $i + 1, $ref);

                continue;
            }

            if (preg_match('/#\s*v?\S+/', $rest) !== 1) {
                $errors[] = sprintf('%s:%d: add a "# vX.Y.Z" comment after the SHA', $fileName, $i + 1);
            }

            $repo = $r[1];
            $owner = explode('/', $repo)[0];
            if (!in_array($owner, ['actions', 'github'], true) && !in_array($repo, self::ALLOWED_THIRD_PARTY, true)) {
                $errors[] = sprintf('%s:%d: action "%s" is not on the allow-list', $fileName, $i + 1, $repo);
            }
        }

        return $errors;
    }
}
