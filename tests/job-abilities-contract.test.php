<?php

declare(strict_types=1);

namespace {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function job_ability_expect(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }
}

namespace SmartCloud\WPSuite\StaticPublisher {
    require_once dirname(__DIR__) . '/includes/class-job-abilities.php';

    $reflection = new \ReflectionClass(JobAbilities::class);
    /** @var JobAbilities $abilities */
    $abilities = $reflection->newInstanceWithoutConstructor();

    $actor = static fn(string $mode, string $role): object => new class($mode, $role) {
        public function __construct(private string $mode, private string $role) {}
        public function mode(): string { return $this->mode; }
        public function role(): string { return $this->role; }
    };

    foreach (array('crawl', 'deploy') as $job) {
        \job_ability_expect(
            $abilities->authorizeComposerTool(true, JobAbilities::ABILITY, array('job' => $job), $actor('PROTECTED', 'contributor')),
            'Protected Contributors must be allowed to queue ' . $job . ' jobs.'
        );
    }
    foreach (array('publish', 'content-sync') as $job) {
        \job_ability_expect(
            !$abilities->authorizeComposerTool(true, JobAbilities::ABILITY, array('job' => $job), $actor('PROTECTED', 'contributor')),
            'Protected Contributors must not be allowed to queue ' . $job . ' jobs.'
        );
        \job_ability_expect(
            $abilities->authorizeComposerTool(true, JobAbilities::ABILITY, array('job' => $job), $actor('PROTECTED', 'publisher')),
            'Protected Publishers must be allowed to queue ' . $job . ' jobs.'
        );
        \job_ability_expect(
            $abilities->authorizeComposerTool(true, JobAbilities::ABILITY, array('job' => $job), $actor('OPEN', 'contributor')),
            'Open mode must preserve contributor access for ' . $job . ' jobs.'
        );
    }
    foreach (array(JobAbilities::LIST_TARGETS_ABILITY, JobAbilities::LIST_CONTENT_SYNC_RULES_ABILITY) as $abilityName) {
        \job_ability_expect(
            !$abilities->authorizeComposerTool(true, $abilityName, array(), $actor('PROTECTED', 'contributor')),
            'Protected Contributors must not be allowed to discover Publisher configuration.'
        );
        \job_ability_expect(
            $abilities->authorizeComposerTool(true, $abilityName, array(), $actor('PROTECTED', 'publisher')),
            'Protected Publishers must be allowed to discover Publisher configuration.'
        );
        \job_ability_expect(
            $abilities->authorizeComposerTool(true, $abilityName, array(), $actor('OPEN', 'contributor')),
            'OPEN mode must expose Publisher discovery tools.'
        );
    }
    $visibleTools = array(
        array('name' => JobAbilities::ABILITY),
        array('name' => JobAbilities::LIST_TARGETS_ABILITY),
        array('name' => JobAbilities::LIST_CONTENT_SYNC_RULES_ABILITY),
        array('name' => JobAbilities::GET_JOB_STATUS_ABILITY),
    );
    \job_ability_expect(
        count($abilities->filterComposerVisibleTools($visibleTools, $actor('PROTECTED', 'contributor'))) === 2,
        'Protected Contributors must see scheduling and status tools, but not Publisher-only discovery tools.'
    );
    \job_ability_expect(
        count($abilities->filterComposerVisibleTools($visibleTools, $actor('PROTECTED', 'publisher'))) === 4,
        'Protected Publishers must see Publisher discovery and status tools.'
    );
    \job_ability_expect(
        !$abilities->authorizeComposerTool(false, JobAbilities::ABILITY, array('job' => 'crawl'), $actor('PROTECTED', 'publisher')),
        'The Publisher policy must never override a prior Composer denial.'
    );

    $source = file_get_contents(dirname(__DIR__) . '/includes/class-job-abilities.php');
    $plugin = file_get_contents(dirname(__DIR__) . '/smartcloud-static-publisher.php');
    \job_ability_expect(is_string($source) && is_string($plugin), 'Job Ability sources must be readable.');
    foreach (array("'crawl'", "'deploy'", "'publish'", "'content-sync'") as $jobLiteral) {
        \job_ability_expect(str_contains($source, $jobLiteral), 'Ability schema is missing job enum value ' . $jobLiteral . '.');
    }
    \job_ability_expect(str_contains($source, "'job_type'"), 'Ability must expose job_type.');
    \job_ability_expect(str_contains($source, "'target'"), 'Ability must expose target.');
    \job_ability_expect(str_contains($source, "'content_sync_rule'"), 'Ability must expose the exact content-sync rule selector.');
    \job_ability_expect(str_contains($source, "'full', 'incremental'"), 'Ability must constrain crawl and publish job types.');
    \job_ability_expect(str_contains($source, 'smartcloud-static-publisher/list-targets'), 'Ability must expose target discovery.');
    \job_ability_expect(str_contains($source, 'smartcloud-static-publisher/list-content-sync-rules'), 'Ability must expose content-sync rule discovery.');
    \job_ability_expect(str_contains($source, 'smartcloud-static-publisher/get-job-status'), 'Ability must expose later job-status lookup.');
    \job_ability_expect(substr_count($source, "'readonly' => true") >= 3, 'Discovery and status Abilities must be declared read-only.');
    \job_ability_expect(str_contains($source, "'job_id'"), 'Schedule output and status input must expose job_id.');
    \job_ability_expect(str_contains($source, "'jobs_ahead'"), 'Status output must expose the number of jobs ahead.');
    \job_ability_expect(str_contains($source, "'ended_at'"), 'Status output must expose a completion timestamp.');
    \job_ability_expect(str_contains($source, "'error'"), 'Status output must expose a retained failure reason.');
    \job_ability_expect(str_contains($source, 'Never guess a missing target or content-sync rule.'), 'Schedule-job must explicitly forbid guessing incomplete inputs.');
    \job_ability_expect(str_contains($source, 'ask whether to call it'), 'Schedule-job must offer discovery before calling it.');
    \job_ability_expect(str_contains($source, 'smartcloud_agent_composer_mcp_ability_names'), 'Ability must opt into the Composer MCP surface.');
    \job_ability_expect(str_contains($source, 'smartcloud_agent_composer_mcp_authorize_tool'), 'Ability must enforce argument-aware Composer roles.');
    \job_ability_expect(str_contains($source, 'smartcloud_agent_composer_mcp_visible_tools'), 'Ability must hide protected discovery tools from non-Publishers.');
    \job_ability_expect(str_contains($plugin, 'resolveManualContentSyncContext'), 'Manual content-sync must resolve a canonical active rule context.');
    \job_ability_expect(str_contains($plugin, "'content_sync_rule_ambiguous'"), 'Duplicate content-sync rule identities must fail closed.');
    \job_ability_expect(str_contains($plugin, "'content_sync_rule_required'"), 'Manual content-sync must require an exact rule ID.');
    \job_ability_expect(str_contains($plugin, "'baselineStatus'] ?? '') !== 'ready'"), 'Manual content-sync must require a ready baseline.');
    \job_ability_expect(str_contains($plugin, "'coalesceKey'"), 'Manual content-sync must retain the runner-owned coalesce key.');
    \job_ability_expect(str_contains($plugin, "'status_tool' => 'smartcloud-static-publisher/get-job-status'"), 'Schedule responses must identify the status tool.');
    \job_ability_expect(str_contains($plugin, '$currentBeforeQueue'), 'Status lookup must inspect the active run around its queue snapshot.');
    \job_ability_expect(str_contains($plugin, '$jobsAhead = max(0, (int) $index + ($currentActive ? 1 : 0))'), 'Queued status must count both earlier queued work and an active job.');
    \job_ability_expect(str_contains($plugin, 'sanitizeJobStatusReason'), 'Failure reasons must be redacted before Ability output.');
    \job_ability_expect(str_contains($plugin, "'job-run-finished'"), 'Status lookup must recover retained terminal outcomes from audit history.');

    echo "Static Publisher job Ability contract passed.\n";
}
