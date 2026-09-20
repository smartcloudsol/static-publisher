<?php

namespace SmartCloud\WPSuite\StaticPublisher;

final class JobAbilities
{
    public const CATEGORY = 'smartcloud-static-publisher';
    public const SCHEDULE_ABILITY = 'smartcloud-static-publisher/schedule-job';
    public const LIST_TARGETS_ABILITY = 'smartcloud-static-publisher/list-targets';
    public const LIST_CONTENT_SYNC_RULES_ABILITY = 'smartcloud-static-publisher/list-content-sync-rules';
    public const GET_JOB_STATUS_ABILITY = 'smartcloud-static-publisher/get-job-status';
    public const ABILITY = self::SCHEDULE_ABILITY;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function registerHooks(): void
    {
        add_action('wp_abilities_api_categories_init', array($this, 'registerCategory'));
        add_action('wp_abilities_api_init', array($this, 'registerAbilities'), 40);
        add_filter('smartcloud_agent_composer_mcp_ability_names', array($this, 'addComposerAbilities'));
        add_filter('smartcloud_agent_composer_mcp_visible_tools', array($this, 'filterComposerVisibleTools'), 20, 2);
        add_filter('smartcloud_agent_composer_mcp_authorize_tool', array($this, 'authorizeComposerTool'), 20, 4);
    }

    public function registerCategory(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category(self::CATEGORY, array(
            'label' => __('SmartCloud Static Publisher', 'smartcloud-static-publisher'),
            'description' => __('Discover publisher targets and content-sync rules, then queue governed jobs for the external exporter.', 'smartcloud-static-publisher'),
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        $this->registerScheduleAbility();
        $this->registerTargetDiscoveryAbility();
        $this->registerRuleDiscoveryAbility();
        $this->registerJobStatusAbility();
    }

    private function registerScheduleAbility(): void
    {
        wp_register_ability(self::SCHEDULE_ABILITY, array(
            'label' => __('Schedule a Static Publisher job', 'smartcloud-static-publisher'),
            'description' => __('Queues crawl, deploy, publish, or content-sync work and returns a stable job_id plus smartcloud-static-publisher/get-job-status for later progress checks. Never guess a missing target or content-sync rule. If the target is missing or uncertain, explain that smartcloud-static-publisher/list-targets can retrieve the choices and ask whether to call it. For content-sync, content_sync_rule is also required; if it is missing or uncertain, explain that smartcloud-static-publisher/list-content-sync-rules can retrieve the current rules and ask whether to call it. job_type is required only for crawl and publish.', 'smartcloud-static-publisher'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'required' => array('job', 'target'),
                'properties' => array(
                    'job' => array(
                        'type' => 'string',
                        'enum' => array('crawl', 'deploy', 'publish', 'content-sync'),
                        'description' => __('Publisher job to queue.', 'smartcloud-static-publisher'),
                    ),
                    'job_type' => array(
                        'type' => 'string',
                        'enum' => array('full', 'incremental'),
                        'description' => __('Required for crawl and publish; omit for deploy and content-sync.', 'smartcloud-static-publisher'),
                    ),
                    'target' => array(
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 128,
                        'pattern' => '^(default|[A-Za-z0-9._-]+)$',
                        'description' => __('Use a target returned by smartcloud-static-publisher/list-targets. Ask before calling that discovery tool when the requested target is missing or uncertain.', 'smartcloud-static-publisher'),
                    ),
                    'content_sync_rule' => array(
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 256,
                        'description' => __('Required only for content-sync. Use the exact rule_id returned by smartcloud-static-publisher/list-content-sync-rules; ask before calling that discovery tool when the rule is missing or uncertain.', 'smartcloud-static-publisher'),
                    ),
                ),
            ),
            'output_schema' => array(
                'type' => 'object',
                'additionalProperties' => true,
                'required' => array('success', 'job', 'job_id', 'status', 'status_tool', 'queueLength', 'message'),
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'job' => array('type' => 'object', 'additionalProperties' => true),
                    'job_id' => array('type' => 'string'),
                    'status' => array('type' => 'string'),
                    'status_tool' => array('type' => 'string'),
                    'queueLength' => array('type' => 'integer', 'minimum' => 0),
                    'coalesced' => array('type' => 'boolean'),
                    'message' => array('type' => 'string'),
                ),
            ),
            'execute_callback' => array($this, 'execute'),
            'permission_callback' => array($this, 'canExecute'),
            'meta' => array(
                'show_in_rest' => false,
                'mcp' => array('public' => false),
                'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
            ),
        ));
    }

    private function registerTargetDiscoveryAbility(): void
    {
        wp_register_ability(self::LIST_TARGETS_ABILITY, array(
            'label' => __('List Static Publisher targets', 'smartcloud-static-publisher'),
            'description' => __('Read-only preflight for smartcloud-static-publisher/schedule-job. Returns target values that can be copied verbatim into schedule-job.target. It does not queue work. In protected modes it is Publisher-only; in OPEN mode it is available to every admitted agent.', 'smartcloud-static-publisher'),
            'category' => self::CATEGORY,
            'input_schema' => array('type' => 'object', 'additionalProperties' => false),
            'output_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'required' => array('success', 'evaluated_at', 'targets', 'message'),
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'evaluated_at' => array('type' => 'string'),
                    'targets' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => array('target', 'kind', 'resolved_target'),
                            'properties' => array(
                                'target' => array('type' => 'string'),
                                'kind' => array('type' => 'string', 'enum' => array('default', 'profile')),
                                'resolved_target' => array('type' => 'string'),
                            ),
                        ),
                    ),
                    'message' => array('type' => 'string'),
                ),
            ),
            'execute_callback' => array($this, 'listTargets'),
            'permission_callback' => array($this, 'canDiscover'),
            'meta' => array(
                'show_in_rest' => false,
                'mcp' => array('public' => false),
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),
        ));
    }

    private function registerRuleDiscoveryAbility(): void
    {
        wp_register_ability(self::LIST_CONTENT_SYNC_RULES_ABILITY, array(
            'label' => __('List Static Publisher content-sync rules', 'smartcloud-static-publisher'),
            'description' => __('Read-only preflight for smartcloud-static-publisher/schedule-job with job=content-sync. Returns the exact rule_id and target pairs accepted by the scheduler and indicates whether each rule is currently schedulable. It does not queue work. In protected modes it is Publisher-only; in OPEN mode it is available to every admitted agent.', 'smartcloud-static-publisher'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'target' => array(
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 128,
                        'pattern' => '^(default|[A-Za-z0-9._-]+)$',
                        'description' => __('Optional schedule-job target filter. Omit it to list rules for every target.', 'smartcloud-static-publisher'),
                    ),
                ),
            ),
            'output_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'required' => array('success', 'evaluated_at', 'rules', 'message'),
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'evaluated_at' => array('type' => 'string'),
                    'rules' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => array('rule_id', 'name', 'target', 'effective_target', 'enabled', 'active', 'baseline_status', 'schedulable', 'post_types', 'interval_minutes'),
                            'properties' => array(
                                'rule_id' => array('type' => 'string'),
                                'name' => array('type' => 'string'),
                                'target' => array('type' => 'string'),
                                'effective_target' => array('type' => 'string'),
                                'enabled' => array('type' => 'boolean'),
                                'active' => array('type' => 'boolean'),
                                'baseline_status' => array('type' => 'string', 'enum' => array('ready', 'required', 'missing')),
                                'schedulable' => array('type' => 'boolean'),
                                'post_types' => array('type' => 'array', 'items' => array('type' => 'string')),
                                'interval_minutes' => array('type' => 'integer', 'minimum' => 1),
                            ),
                        ),
                    ),
                    'message' => array('type' => 'string'),
                ),
            ),
            'execute_callback' => array($this, 'listContentSyncRules'),
            'permission_callback' => array($this, 'canDiscover'),
            'meta' => array(
                'show_in_rest' => false,
                'mcp' => array('public' => false),
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),
        ));
    }

    private function registerJobStatusAbility(): void
    {
        wp_register_ability(self::GET_JOB_STATUS_ABILITY, array(
            'label' => __('Get Static Publisher job status', 'smartcloud-static-publisher'),
            'description' => __('Checks a job previously returned by smartcloud-static-publisher/schedule-job. Use the exact job_id from that response when the user asks whether the job has run. Reports queued jobs with the number of jobs ahead, retry wait, active execution, success, failure with its retained reason, stop/cancellation, timestamps, or an expired/not-found outcome. It never queues or changes work.', 'smartcloud-static-publisher'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'required' => array('job_id'),
                'properties' => array(
                    'job_id' => array(
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 128,
                        'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$',
                        'description' => __('Exact job_id returned by schedule-job.', 'smartcloud-static-publisher'),
                    ),
                ),
            ),
            'output_schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'required' => array('success', 'found', 'job_id', 'job', 'status', 'terminal', 'jobs_ahead', 'queue_position', 'created_at', 'started_at', 'ended_at', 'next_attempt_at', 'error', 'message'),
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'found' => array('type' => 'boolean'),
                    'job_id' => array('type' => 'string'),
                    'job' => array('type' => 'string'),
                    'status' => array('type' => 'string', 'enum' => array('queued', 'retry-wait', 'running', 'success', 'failed', 'stopped', 'cancelled', 'unknown', 'not-found')),
                    'terminal' => array('type' => 'boolean'),
                    'jobs_ahead' => array('type' => 'integer', 'minimum' => 0),
                    'queue_position' => array('type' => 'integer', 'minimum' => 0),
                    'created_at' => array('type' => 'string'),
                    'started_at' => array('type' => 'string'),
                    'ended_at' => array('type' => 'string'),
                    'next_attempt_at' => array('type' => 'string'),
                    'error' => array('type' => 'string'),
                    'message' => array('type' => 'string'),
                ),
            ),
            'execute_callback' => array($this, 'getJobStatus'),
            'permission_callback' => array($this, 'canGetJobStatus'),
            'meta' => array(
                'show_in_rest' => false,
                'mcp' => array('public' => false),
                'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            ),
        ));
    }

    public function execute(array $input): array|\WP_Error
    {
        $job = sanitize_text_field((string) ($input['job'] ?? ''));
        $jobType = sanitize_text_field((string) ($input['job_type'] ?? ''));
        $target = sanitize_text_field((string) ($input['target'] ?? ''));
        $contentSyncRule = sanitize_text_field((string) ($input['content_sync_rule'] ?? ''));
        if (in_array($job, array('crawl', 'publish'), true) && !in_array($jobType, array('full', 'incremental'), true)) {
            return new \WP_Error('publisher_job_type_required', __('job_type must be full or incremental for crawl and publish jobs.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        if (!in_array($job, array('crawl', 'deploy', 'publish', 'content-sync'), true)) {
            return new \WP_Error('invalid_publisher_job', __('job must be crawl, deploy, publish, or content-sync.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        if (in_array($job, array('deploy', 'content-sync'), true) && $jobType !== '') {
            return new \WP_Error('publisher_job_type_not_applicable', __('job_type applies only to crawl and publish jobs.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        if ($job === 'content-sync' && $contentSyncRule === '') {
            return new \WP_Error('content_sync_rule_required', __('content_sync_rule is required for content-sync. Ask whether to call smartcloud-static-publisher/list-content-sync-rules to retrieve the choices.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        if ($job !== 'content-sync' && $contentSyncRule !== '') {
            return new \WP_Error('publisher_content_sync_rule_not_applicable', __('content_sync_rule applies only to content-sync jobs.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        if ($target === '') {
            return new \WP_Error('publisher_target_required', __('target is required. Ask whether to call smartcloud-static-publisher/list-targets to retrieve the choices.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        $deploymentProfile = $target === 'default' ? '' : $this->plugin->sanitizeDeploymentProfileName($target);
        if ($target !== 'default' && $deploymentProfile !== $target) {
            return new \WP_Error('invalid_publisher_target', __('target contains unsupported characters.', 'smartcloud-static-publisher'), array('status' => 400));
        }

        return $this->plugin->enqueueStandardJob(array(
            'command' => $job,
            'crawlMode' => $jobType,
            'deploymentProfile' => $deploymentProfile,
            'contentSyncRuleId' => $contentSyncRule,
        ), 'wp-ability', get_current_user_id());
    }

    public function listTargets(array $input = array()): array|\WP_Error
    {
        unset($input);
        $snapshot = $this->readDiscoverySnapshot('targets');
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $targets = array();
        foreach ((array) $snapshot['targets'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $target = sanitize_text_field((string) ($entry['target'] ?? ''));
            if (
                $target === ''
                || ($target !== 'default' && $this->plugin->sanitizeDeploymentProfileName($target) !== $target)
            ) {
                continue;
            }
            $targets[$target] = array(
                'target' => $target,
                'kind' => $target === 'default' ? 'default' : 'profile',
                'resolved_target' => sanitize_text_field((string) ($entry['resolvedDeploymentProfile'] ?? '')) ?: 'default',
            );
        }
        ksort($targets, SORT_NATURAL | SORT_FLAG_CASE);
        if (isset($targets['default'])) {
            $default = $targets['default'];
            unset($targets['default']);
            $targets = array('default' => $default) + $targets;
        }
        return array(
            'success' => true,
            'evaluated_at' => sanitize_text_field((string) $snapshot['evaluatedAt']),
            'targets' => array_values($targets),
            'message' => __('Copy a target value verbatim into smartcloud-static-publisher/schedule-job.target.', 'smartcloud-static-publisher'),
        );
    }

    public function listContentSyncRules(array $input = array()): array|\WP_Error
    {
        $targetFilter = sanitize_text_field((string) ($input['target'] ?? ''));
        if ($targetFilter !== '' && $targetFilter !== 'default' && $this->plugin->sanitizeDeploymentProfileName($targetFilter) !== $targetFilter) {
            return new \WP_Error('invalid_publisher_target', __('target contains unsupported characters.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        $snapshot = $this->readDiscoverySnapshot('rules');
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $paths = $this->plugin->getRuntimePaths();
        $baselines = $this->plugin->readJsonFile((string) ($paths['contentSyncBaseline'] ?? ''));
        $state = $this->plugin->readJsonFile((string) ($paths['contentSyncState'] ?? ''));
        $baselineEntries = is_array($baselines) && is_array($baselines['entries'] ?? null) ? $baselines['entries'] : array();
        $stateEntries = is_array($state) && is_array($state['rules'] ?? null) ? $state['rules'] : array();
        $activeEntries = array();
        foreach ((array) ($snapshot['entries'] ?? array()) as $entry) {
            if (is_array($entry)) {
                $key = sanitize_text_field((string) ($entry['coalesceKey'] ?? ''));
                if ($key !== '') {
                    $activeEntries[$key] = $entry;
                }
            }
        }
        $identityCounts = array();
        foreach ((array) $snapshot['rules'] as $rule) {
            if (is_array($rule)) {
                $identity = (string) ($rule['ruleId'] ?? '');
                $identityCounts[$identity] = ($identityCounts[$identity] ?? 0) + 1;
            }
        }

        $rules = array();
        foreach ((array) $snapshot['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $ruleId = sanitize_text_field((string) ($rule['ruleId'] ?? ''));
            $coalesceKey = sanitize_text_field((string) ($rule['coalesceKey'] ?? ''));
            $target = sanitize_text_field((string) ($rule['target'] ?? '')) ?: 'default';
            if (
                $ruleId === ''
                || $coalesceKey === ''
                || ($target !== 'default' && $this->plugin->sanitizeDeploymentProfileName($target) !== $target)
                || ($targetFilter !== '' && $target !== $targetFilter)
            ) {
                continue;
            }
            $active = !empty($rule['active']);
            $activeEntry = is_array($activeEntries[$coalesceKey] ?? null) ? $activeEntries[$coalesceKey] : array();
            $consumerId = sanitize_text_field((string) ($activeEntry['consumerId'] ?? ''));
            $baseline = is_array($baselineEntries[$coalesceKey] ?? null) ? $baselineEntries[$coalesceKey] : array();
            $ruleState = is_array($stateEntries[$coalesceKey] ?? null) ? $stateEntries[$coalesceKey] : array();
            $identityMatches = $consumerId !== ''
                && ($activeEntry['ruleId'] ?? '') === $ruleId
                && ($baseline['ruleId'] ?? '') === $ruleId
                && ($baseline['coalesceKey'] ?? '') === $coalesceKey
                && ($baseline['consumerId'] ?? '') === $consumerId
                && ($ruleState['ruleId'] ?? '') === $ruleId
                && ($ruleState['coalesceKey'] ?? '') === $coalesceKey
                && ($ruleState['consumerId'] ?? '') === $consumerId;
            $baselineStatus = $identityMatches && ($ruleState['baselineStatus'] ?? '') === 'ready'
                ? 'ready'
                : (($ruleState['baselineStatus'] ?? '') === 'required' ? 'required' : 'missing');
            $unique = ($identityCounts[$ruleId] ?? 0) === 1;
            $postTypes = array_values(array_filter(array_map(
                static fn(mixed $value): string => sanitize_key((string) $value),
                is_array($rule['postTypes'] ?? null) ? $rule['postTypes'] : array()
            )));
            $rules[] = array(
                'rule_id' => $ruleId,
                'name' => $ruleId,
                'target' => $target,
                'effective_target' => sanitize_text_field((string) ($rule['effectiveTarget'] ?? '')) ?: $target,
                'enabled' => !empty($rule['enabled']),
                'active' => $active,
                'baseline_status' => $baselineStatus,
                'schedulable' => $active && $unique && $baselineStatus === 'ready',
                'post_types' => $postTypes,
                'interval_minutes' => max(1, (int) ($rule['intervalMinutes'] ?? 1)),
            );
        }
        usort($rules, static fn(array $left, array $right): int => strcasecmp(
            (string) $left['target'] . '/' . (string) $left['rule_id'],
            (string) $right['target'] . '/' . (string) $right['rule_id']
        ));
        return array(
            'success' => true,
            'evaluated_at' => sanitize_text_field((string) $snapshot['evaluatedAt']),
            'rules' => $rules,
            'message' => __('Choose a schedulable rule and copy rule_id into schedule-job.content_sync_rule with its returned target. Disabled, inactive, duplicate, or non-ready rules cannot be queued.', 'smartcloud-static-publisher'),
        );
    }

    public function getJobStatus(array $input): array|\WP_Error
    {
        $jobId = sanitize_text_field((string) ($input['job_id'] ?? ''));
        if ($jobId === '') {
            return new \WP_Error(
                'publisher_job_id_required',
                __('job_id is required. Use the exact job_id returned by smartcloud-static-publisher/schedule-job.', 'smartcloud-static-publisher'),
                array('status' => 400)
            );
        }
        return $this->plugin->getJobStatus($jobId);
    }

    public function canExecute(mixed $input = null): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $actor = $this->composerActor();
        if ($actor === null) {
            return false;
        }
        $args = is_array($input) ? $input : array();
        $job = sanitize_text_field((string) ($args['job'] ?? ''));
        if ((string) $actor->mode() === 'OPEN') {
            return in_array((string) $actor->role(), array('contributor', 'publisher'), true);
        }
        if (in_array($job, array('publish', 'content-sync'), true)) {
            return (string) $actor->role() === 'publisher';
        }
        return in_array((string) $actor->role(), array('contributor', 'publisher'), true);
    }

    public function canDiscover(mixed $input = null): bool
    {
        unset($input);
        if (current_user_can('manage_options')) {
            return true;
        }
        $actor = $this->composerActor();
        if ($actor === null) {
            return false;
        }
        return (string) $actor->mode() === 'OPEN' || (string) $actor->role() === 'publisher';
    }

    public function canGetJobStatus(mixed $input = null): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $actor = $this->composerActor();
        if ($actor === null) {
            return false;
        }
        if ((string) $actor->mode() === 'OPEN') {
            return true;
        }
        $jobId = is_array($input) ? sanitize_text_field((string) ($input['job_id'] ?? '')) : '';
        return $this->actorCanReadJobStatus($actor, $jobId);
    }

    public function addComposerAbilities(mixed $names): array
    {
        $abilityNames = is_array($names) ? $names : array();
        array_push($abilityNames, self::SCHEDULE_ABILITY, self::LIST_TARGETS_ABILITY, self::LIST_CONTENT_SYNC_RULES_ABILITY, self::GET_JOB_STATUS_ABILITY);
        return array_values(array_unique($abilityNames));
    }

    public function authorizeComposerTool(bool $authorized, string $toolName, mixed $args, mixed $actor): bool
    {
        $publisherAbilities = array(self::SCHEDULE_ABILITY, self::LIST_TARGETS_ABILITY, self::LIST_CONTENT_SYNC_RULES_ABILITY, self::GET_JOB_STATUS_ABILITY);
        if (!$authorized || !in_array($toolName, $publisherAbilities, true)) {
            return $authorized;
        }
        if (!is_object($actor) || !method_exists($actor, 'role') || !method_exists($actor, 'mode')) {
            return false;
        }
        if ((string) $actor->mode() === 'OPEN') {
            return true;
        }
        if (in_array($toolName, array(self::LIST_TARGETS_ABILITY, self::LIST_CONTENT_SYNC_RULES_ABILITY), true)) {
            return (string) $actor->role() === 'publisher';
        }
        if ($toolName === self::GET_JOB_STATUS_ABILITY) {
            $jobId = is_array($args) ? sanitize_text_field((string) ($args['job_id'] ?? '')) : '';
            return $this->actorCanReadJobStatus($actor, $jobId);
        }
        $input = is_array($args) ? $args : array();
        $job = sanitize_text_field((string) ($input['job'] ?? ''));
        if (in_array($job, array('publish', 'content-sync'), true)) {
            return (string) $actor->role() === 'publisher';
        }
        return in_array((string) $actor->role(), array('contributor', 'publisher'), true);
    }

    public function filterComposerVisibleTools(array $tools, mixed $actor): array
    {
        if (
            is_object($actor)
            && method_exists($actor, 'mode')
            && method_exists($actor, 'role')
            && ((string) $actor->mode() === 'OPEN' || (string) $actor->role() === 'publisher')
        ) {
            return $tools;
        }
        $publisherOnlyAbilities = array(self::LIST_TARGETS_ABILITY, self::LIST_CONTENT_SYNC_RULES_ABILITY);
        $statusAbility = self::GET_JOB_STATUS_ABILITY;
        $actorRole = is_object($actor) && method_exists($actor, 'role') ? (string) $actor->role() : '';
        return array_values(array_filter($tools, static function (mixed $tool) use ($publisherOnlyAbilities, $statusAbility, $actorRole): bool {
            $name = '';
            if (is_object($tool) && method_exists($tool, 'toArray')) {
                $value = $tool->toArray();
                $name = is_array($value) ? (string) ($value['name'] ?? '') : '';
            } elseif (is_object($tool) && method_exists($tool, 'getName')) {
                $name = (string) $tool->getName();
            } elseif (is_array($tool)) {
                $name = (string) ($tool['name'] ?? '');
            }
            if (in_array($name, $publisherOnlyAbilities, true)) {
                return false;
            }
            return $name !== $statusAbility || in_array($actorRole, array('contributor', 'publisher'), true);
        }));
    }

    private function actorCanReadJobStatus(object $actor, string $jobId): bool
    {
        if ($jobId === '') {
            return false;
        }
        $status = $this->plugin->getJobStatus($jobId);
        $job = sanitize_text_field((string) ($status['job'] ?? ''));
        if (in_array($job, array('publish', 'content-sync'), true)) {
            return (string) $actor->role() === 'publisher';
        }
        if (in_array($job, array('crawl', 'deploy'), true)) {
            return in_array((string) $actor->role(), array('contributor', 'publisher'), true);
        }
        return (string) $actor->role() === 'publisher';
    }

    private function composerActor(): ?object
    {
        $actorClass = '\\SmartCloud\\AgentComposer\\Security\\ActorIdentity';
        if (!class_exists($actorClass) || !method_exists($actorClass, 'context')) {
            return null;
        }
        $actor = $actorClass::context();
        return is_object($actor) && method_exists($actor, 'role') && method_exists($actor, 'mode') ? $actor : null;
    }

    private function readDiscoverySnapshot(string $requiredField): array|\WP_Error
    {
        $paths = $this->plugin->getRuntimePaths();
        $snapshot = $this->plugin->readJsonFile((string) ($paths['contentSyncActiveRules'] ?? ''));
        if (
            !is_array($snapshot)
            || (int) ($snapshot['contractVersion'] ?? 0) !== 1
            || empty($snapshot['discoveryReady'])
            || sanitize_text_field((string) ($snapshot['evaluatedAt'] ?? '')) === ''
            || !array_key_exists($requiredField, $snapshot)
            || !is_array($snapshot[$requiredField])
        ) {
            return new \WP_Error(
                'publisher_discovery_snapshot_unavailable',
                __('Publisher discovery data is unavailable. Run or restart the queue runner so it refreshes the target and content-sync rule snapshot, then try again.', 'smartcloud-static-publisher'),
                array('status' => 503)
            );
        }
        return $snapshot;
    }
}
