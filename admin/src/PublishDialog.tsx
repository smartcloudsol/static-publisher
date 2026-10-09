import { Alert, Button, Group, Modal, Select, Stack, Text, TextInput } from "@mantine/core";
import { __ } from "@wordpress/i18n";
import { useState } from "react";

const DOMAIN = "smartcloud-static-publisher";
const DEFAULT_TARGET = "__configured_default__";
type Command = "publish" | "crawl" | "deploy" | "invalidate" | "retry-timeouts" | "url" | "content-sync";
export type PublishRequest = {
  command: Command;
  crawlMode?: "full" | "incremental";
  deploymentProfile?: string;
  contentSyncRuleId?: string;
  url?: string;
};
type Target = { awsProfile?: string; targetOrigin?: string; s3?: { bucket?: string; prefix?: string } };
export type PublishConfig = Target & {
  defaultDeploymentProfile?: string;
  deploymentProfiles?: Record<string, Target>;
  scheduler?: { enabled?: boolean; rules: Array<{ id: string; command: string; enabled?: boolean; deploymentProfile?: string }> };
};
type Props = {
  opened: boolean;
  onClose: () => void;
  config: PublishConfig | null;
  configured: boolean;
  pro: boolean;
  loading: boolean;
  loadError: string;
  onQueue: (request: PublishRequest) => Promise<void>;
};

/** These are launch options only. Saving a job never changes publisher configuration. */
export default function PublishDialog({ opened, onClose, config, configured, pro, loading, loadError, onQueue }: Props) {
  const [command, setCommand] = useState<Command>("publish");
  const [crawlMode, setCrawlMode] = useState<"full" | "incremental">("full");
  const [target, setTarget] = useState(DEFAULT_TARGET);
  const [rule, setRule] = useState("");
  const [url, setUrl] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState("");
  const deploys = ["publish", "deploy", "invalidate", "content-sync"].includes(command);
  const crawls = command === "publish" || command === "crawl";
  const profiles = config?.deploymentProfiles ?? {};
  const explicitTarget = target === DEFAULT_TARGET ? "" : target;
  const effectiveName = explicitTarget || config?.defaultDeploymentProfile || "";
  const effective = effectiveName ? profiles[effectiveName] : undefined;
  const missingTarget = deploys && !!effectiveName && !effective;
  const rules = config?.scheduler?.enabled ? config.scheduler.rules.filter(item =>
    item.command === "content-sync" && item.enabled !== false &&
    (item.deploymentProfile || "") === explicitTarget) : [];
  const validRule = rules.some(item => item.id === rule);
  const unavailable = loading || !!loadError || !config || !configured || missingTarget ||
    (deploys && explicitTarget !== "" && !pro) ||
    (crawls && crawlMode === "incremental" && !pro) ||
    (command === "content-sync" && (!pro || !validRule)) ||
    (command === "url" && !url.trim());
  const submit = async () => {
    if (pending || unavailable) return;
    setPending(true);
    setError("");
    try {
      await onQueue({ command,
        ...(crawls ? { crawlMode } : {}),
        ...(deploys && explicitTarget ? { deploymentProfile: explicitTarget } : {}),
        ...(command === "content-sync" ? { contentSyncRuleId: rule } : {}),
        ...(command === "url" ? { url: url.trim() } : {}),
      });
      onClose();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : __("Could not queue the publishing job.", DOMAIN));
    } finally { setPending(false); }
  };
  return <Modal opened={opened} onClose={onClose} title={__("Publish site", DOMAIN)} centered size="lg" zIndex={100001}
    closeOnClickOutside={!pending} closeOnEscape={!pending} withCloseButton={!pending}>
    <Stack gap="md">
      <Text size="sm" c="dimmed">{__("Choose this run's options. Your saved publishing settings stay unchanged.", DOMAIN)}</Text>
      {loading && <Text role="status">{__("Loading current publishing settings…", DOMAIN)}</Text>}
      {loadError && <Alert color="red" role="alert">{loadError}</Alert>}
      {!loading && !loadError && !configured && <Alert color="yellow">{__("Complete and save publishing setup before starting a job.", DOMAIN)}</Alert>}
      <Select comboboxProps={{ withinPortal: false }} label={__("Task", DOMAIN)} value={command} allowDeselect={false} disabled={pending || loading}
        onChange={value => { setCommand(value as Command); setRule(""); setError(""); }} data={[
          { value: "publish", label: __("Export and publish", DOMAIN) },
          { value: "crawl", label: __("Export only", DOMAIN) },
          { value: "deploy", label: __("Deploy existing export", DOMAIN) },
          { value: "url", label: __("Export one URL", DOMAIN) },
          { value: "content-sync", label: __("Synchronize content (Pro)", DOMAIN), disabled: !pro },
          { value: "invalidate", label: __("Clear distribution cache", DOMAIN) },
          { value: "retry-timeouts", label: __("Retry timed-out pages", DOMAIN) },
        ]} />
      {crawls && <Select comboboxProps={{ withinPortal: false }} label={__("Export mode", DOMAIN)} value={crawlMode} allowDeselect={false} disabled={pending || loading}
        onChange={value => setCrawlMode(value as "full" | "incremental")}
        data={[{ value: "full", label: __("Full export", DOMAIN) },
          { value: "incremental", label: __("Incremental export (Pro)", DOMAIN), disabled: !pro }]} />}
      {command === "url" && <TextInput label={__("URL path", DOMAIN)} value={url} placeholder="/blog/post/" disabled={pending || loading}
        onChange={event => setUrl(event.currentTarget.value)} />}
      {deploys && <>
        <Select comboboxProps={{ withinPortal: false }} label={__("Target", DOMAIN)} value={target} allowDeselect={false} disabled={pending || loading}
          onChange={value => { setTarget(value || DEFAULT_TARGET); setRule(""); }} data={[
            { value: DEFAULT_TARGET, label: config?.defaultDeploymentProfile
              ? `${__("Configured default", DOMAIN)}: ${config.defaultDeploymentProfile}` : __("Base target", DOMAIN) },
            ...Object.keys(profiles).sort().map(value => ({ value, label: value, disabled: !pro })),
          ]} />
        {missingTarget ? <Alert color="yellow">{__("The selected target is no longer available. Review the saved publishing settings.", DOMAIN)}</Alert> :
          <Text size="sm">{effective?.targetOrigin ?? config?.targetOrigin} · {effective?.s3?.bucket ?? config?.s3?.bucket}/{effective?.s3?.prefix ?? config?.s3?.prefix}</Text>}
        <TextInput label={__("AWS profile", DOMAIN)} readOnly value={effective?.awsProfile ?? config?.awsProfile ?? ""}
          placeholder={__("Runner's default credentials", DOMAIN)}
          description={__("Inherited from the selected target. Change its AWS profile in detailed settings; no credentials are requested here.", DOMAIN)} />
      </>}
      {command === "content-sync" && <>
        <Select comboboxProps={{ withinPortal: false }} label={__("Content-sync rule", DOMAIN)} value={rule || null} allowDeselect={false} disabled={pending || loading || !pro}
          onChange={value => setRule(value || "")} data={rules.map(item => ({ value: item.id, label: item.id }))}
          placeholder={rules.length ? __("Select a rule", DOMAIN) : __("No enabled rule for this target", DOMAIN)} />
        <Text size="sm" c="dimmed">{__("Requires a verified publish baseline and an up-to-date runner. The job service checks these before accepting the request.", DOMAIN)}</Text>
      </>}
      {!pro && <Text size="sm" c="dimmed">{__("Incremental export, content sync and additional targets require an active Pro subscription.", DOMAIN)}</Text>}
      {error && <Alert color="red" role="alert">{error}</Alert>}
      <Group justify="flex-end">
        <Button variant="default" onClick={onClose} disabled={pending || loading}>{__("Cancel", DOMAIN)}</Button>
        <Button onClick={() => void submit()} loading={pending} disabled={unavailable}>{__("Queue publishing job", DOMAIN)}</Button>
      </Group>
    </Stack>
  </Modal>;
}
