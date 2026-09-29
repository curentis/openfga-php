<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Tools;

use Curentis\OpenFga\Tools\WorkflowPolicy;
use PHPUnit\Framework\TestCase;

final class WorkflowPolicyTest extends TestCase
{
    private WorkflowPolicy $policy;

    #[\Override]
    protected function setUp(): void
    {
        $this->policy = new WorkflowPolicy();
    }

    public function testGoodFixtureHasNoErrors(): void
    {
        $yaml = (string) file_get_contents(__DIR__ . '/../../Support/Fixtures/workflows/good.yml');
        self::assertSame([], $this->policy->check('good.yml', $yaml));
    }

    public function testUnpinnedShaIsRejected(): void
    {
        $yaml = (string) file_get_contents(__DIR__ . '/../../Support/Fixtures/workflows/bad-unpinned-sha.yml');
        $errors = $this->policy->check('bad-unpinned-sha.yml', $yaml);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('40-char commit SHA', implode("\n", $errors));
    }

    public function testMissingPermissionsIsRejected(): void
    {
        $errors = $this->policy->check('x.yml', "name: X\non: push\njobs: {}\n");
        self::assertStringContainsString('permissions', implode("\n", $errors));
    }

    public function testPullRequestTargetIsForbidden(): void
    {
        $yaml = <<<'YAML'
            name: X
            on:
              pull_request_target:
            permissions: {}
            concurrency:
              group: x
            jobs:
              j:
                runs-on: ubuntu-24.04
                timeout-minutes: 1
                steps: []
            YAML;
        $errors = $this->policy->check('x.yml', $yaml);
        self::assertStringContainsString('pull_request_target', implode("\n", $errors));
    }
}
