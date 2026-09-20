<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('SMARTCLOUD_STATIC_PUBLISHER_BOOTSTRAPPED', true);

$statusRuntimeRoot = sys_get_temp_dir() . '/wpsuite-job-status-' . bin2hex(random_bytes(6));
$statusOptions = array();

function __(string $value, string $domain = ''): string { return $value; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_file_name(string $value): string { return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $value); }
function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value)); }
function absint(mixed $value): int { return abs((int) $value); }
function trailingslashit(string $value): string { return rtrim($value, '/\\') . '/'; }
function get_site_url(): string { return 'https://wordpress.example'; }
function home_url(string $path = ''): string { return 'https://wordpress.example' . $path; }
function esc_url_raw(string $value, array $protocols = array()): string { return $value; }
function wp_parse_url(string $value): array|false { return parse_url($value); }
function wp_get_upload_dir(): array
{
    global $statusRuntimeRoot;
    return array('basedir' => $statusRuntimeRoot, 'baseurl' => 'https://wordpress.example/uploads');
}
function get_option(string $key, mixed $default = false): mixed
{
    global $statusOptions;
    return $statusOptions[$key] ?? $default;
}
function update_option(string $key, mixed $value, bool $autoload = false): bool
{
    global $statusOptions;
    $statusOptions[$key] = $value;
    return true;
}
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = false): bool
{
    global $statusOptions;
    if (array_key_exists($key, $statusOptions)) {
        return false;
    }
    $statusOptions[$key] = $value;
    return true;
}
function delete_option(string $key): bool
{
    global $statusOptions;
    unset($statusOptions[$key]);
    return true;
}
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-' . bin2hex(random_bytes(6)); }

function status_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function status_write_json(string $runtime, string $name, mixed $value): void
{
    file_put_contents($runtime . '/' . $name, json_encode($value, JSON_THROW_ON_ERROR));
}

require dirname(__DIR__) . '/smartcloud-static-publisher.php';
require dirname(__DIR__) . '/includes/class-job-abilities.php';

$runtime = $statusRuntimeRoot . '/smartcloud-static-publisher/runtime';
mkdir($runtime, 0777, true);
$plugin = (new ReflectionClass(\SmartCloud\WPSuite\StaticPublisher\Plugin::class))->newInstanceWithoutConstructor();

status_write_json($runtime, 'current-run.json', array(
    'id' => 'active-1',
    'command' => 'deploy',
    'status' => 'running',
    'createdAt' => '2026-09-20T10:00:00Z',
    'startedAt' => '2026-09-20T10:01:00Z',
));
status_write_json($runtime, 'queue.json', array(
    array('id' => 'queued-0', 'command' => 'crawl', 'status' => 'queued', 'createdAt' => '2026-09-20T10:02:00Z'),
    array('id' => 'queued-1', 'command' => 'deploy', 'status' => 'queued', 'createdAt' => '2026-09-20T10:03:00Z'),
));

$queued = $plugin->getJobStatus('queued-1');
status_expect($queued['status'] === 'queued', 'A queued job must be reported as queued.');
status_expect($queued['jobs_ahead'] === 2, 'Jobs ahead must include the active job and earlier queue entries.');
status_expect($queued['queue_position'] === 3, 'Queue position must be one-based.');

$running = $plugin->getJobStatus('active-1');
status_expect($running['status'] === 'running', 'The active snapshot must win over other retained state.');
status_expect($running['started_at'] === '2026-09-20T10:01:00Z', 'Running status must retain its start timestamp.');

status_write_json($runtime, 'current-run.json', null);
status_write_json($runtime, 'queue.json', array());
status_write_json($runtime, 'last-run.json', array(
    'id' => 'failed-1',
    'command' => 'publish',
    'status' => 'failed',
    'createdAt' => '2026-09-20T09:00:00Z',
    'startedAt' => '2026-09-20T09:01:00Z',
    'endedAt' => '2026-09-20T09:02:00Z',
    'error' => 'AWS_SECRET_ACCESS_KEY=do-not-return X-Api-Key: sk-live-private postgres://user:pass@db.example/app failed at /workspace/private/file.json with Bearer header.payload.signature and eyJabc.eyJdef.signature',
));

$failed = $plugin->getJobStatus('failed-1');
status_expect($failed['status'] === 'failed' && $failed['terminal'] === true, 'A failed last run must be terminal.');
status_expect($failed['ended_at'] === '2026-09-20T09:02:00Z', 'A failed job must return its completion timestamp.');
status_expect(str_contains($failed['message'], '2026-09-20T09:02:00Z'), 'A failed job message must state when it failed.');
status_expect(!str_contains($failed['error'], 'do-not-return'), 'Failure output must redact secret values.');
status_expect(!str_contains($failed['error'], 'sk-live-private'), 'Failure output must redact API key headers.');
status_expect(!str_contains($failed['error'], 'user:pass'), 'Failure output must redact URL credentials for non-HTTP schemes.');
status_expect(!str_contains($failed['error'], '/workspace/private'), 'Failure output must redact host filesystem paths.');
status_expect(!str_contains($failed['error'], 'header.payload.signature'), 'Failure output must redact bearer values.');
status_expect(!str_contains($failed['error'], 'eyJabc.eyJdef.signature'), 'Failure output must redact raw JWT values.');

$completeAuditEvent = json_encode(array(
    'jobId' => 'ingested-success',
    'command' => 'deploy',
    'eventType' => 'job-run-finished',
    'status' => 'success',
    'occurredAt' => '2026-09-20T08:30:00Z',
    'details' => array('endedAt' => '2026-09-20T08:30:00Z'),
), JSON_THROW_ON_ERROR) . "\n";
$partialAuditEvent = json_encode(array(
    'jobId' => 'ingested-failure',
    'command' => 'crawl',
    'eventType' => 'job-run-finished',
    'status' => 'failed',
    'occurredAt' => '2026-09-20T08:40:00Z',
    'details' => array('endedAt' => '2026-09-20T08:40:00Z', 'error' => 'safe failure'),
), JSON_THROW_ON_ERROR);
$partialBreak = intdiv(strlen($partialAuditEvent), 2);
file_put_contents($runtime . '/audit-events.jsonl', $completeAuditEvent . substr($partialAuditEvent, 0, $partialBreak));
$ingestedSuccess = $plugin->getJobStatus('ingested-success');
status_expect($ingestedSuccess['status'] === 'success', 'Complete runtime audit lines must be ingested.');
status_expect(
    $statusOptions['smartcloud_static_publisher_audit_cursor'] === strlen($completeAuditEvent),
    'Audit cursor must stop before an incomplete trailing JSONL record.'
);
file_put_contents($runtime . '/audit-events.jsonl', substr($partialAuditEvent, $partialBreak) . "\n", FILE_APPEND);
$ingestedFailure = $plugin->getJobStatus('ingested-failure');
status_expect($ingestedFailure['status'] === 'failed', 'A completed trailing runtime audit line must be ingested on the next read.');

$statusOptions['smartcloud_static_publisher_audit_log'] = array(
    array(
        'jobId' => 'audit-success',
        'command' => 'crawl',
        'eventType' => 'job-run-finished',
        'status' => 'success',
        'occurredAt' => '2026-09-20T08:05:00Z',
        'details' => array('startedat' => '2026-09-20T08:01:00Z', 'endedat' => '2026-09-20T08:05:00Z'),
    ),
    array(
        'jobId' => 'audit-success',
        'command' => 'crawl',
        'eventType' => 'job-created',
        'status' => 'success',
        'occurredAt' => '2026-09-20T08:00:00Z',
        'details' => array(),
    ),
);
$auditSuccess = $plugin->getJobStatus('audit-success');
status_expect($auditSuccess['status'] === 'success', 'A terminal outcome must be recoverable from retained audit history.');
status_expect($auditSuccess['created_at'] === '2026-09-20T08:00:00Z', 'Audit history must retain the queue creation timestamp.');
status_expect($auditSuccess['ended_at'] === '2026-09-20T08:05:00Z', 'Audit history must retain the completion timestamp.');

$abilities = new \SmartCloud\WPSuite\StaticPublisher\JobAbilities($plugin);
$actor = static fn(string $mode, string $role): object => new class($mode, $role) {
    public function __construct(private string $mode, private string $role) {}
    public function mode(): string { return $this->mode; }
    public function role(): string { return $this->role; }
};
status_expect(
    $abilities->authorizeComposerTool(true, \SmartCloud\WPSuite\StaticPublisher\JobAbilities::GET_JOB_STATUS_ABILITY, array('job_id' => 'audit-success'), $actor('PROTECTED', 'contributor')),
    'Protected Contributors must be allowed to inspect crawl/deploy status.'
);
status_expect(
    !$abilities->authorizeComposerTool(true, \SmartCloud\WPSuite\StaticPublisher\JobAbilities::GET_JOB_STATUS_ABILITY, array('job_id' => 'failed-1'), $actor('PROTECTED', 'contributor')),
    'Protected Contributors must not inspect publish/content-sync status.'
);
status_expect(
    $abilities->authorizeComposerTool(true, \SmartCloud\WPSuite\StaticPublisher\JobAbilities::GET_JOB_STATUS_ABILITY, array('job_id' => 'failed-1'), $actor('PROTECTED', 'publisher')),
    'Protected Publishers must be allowed to inspect publish/content-sync status.'
);
status_expect(
    !$abilities->authorizeComposerTool(true, \SmartCloud\WPSuite\StaticPublisher\JobAbilities::GET_JOB_STATUS_ABILITY, array('job_id' => 'unknown-authorization-id'), $actor('PROTECTED', 'contributor')),
    'Unknown job IDs must fail closed to Publisher in protected modes.'
);
status_expect(
    $abilities->authorizeComposerTool(true, \SmartCloud\WPSuite\StaticPublisher\JobAbilities::GET_JOB_STATUS_ABILITY, array('job_id' => 'failed-1'), $actor('OPEN', 'reader')),
    'Open mode must not add role restrictions to job-status lookup.'
);

$missing = $plugin->getJobStatus('missing-1');
status_expect($missing['found'] === false && $missing['status'] === 'not-found', 'An absent or expired job must be reported without guessing.');

foreach (glob($runtime . '/*') ?: array() as $path) {
    unlink($path);
}
rmdir($runtime);
rmdir(dirname($runtime));
rmdir($statusRuntimeRoot);

echo "Static Publisher job status tests passed.\n";
