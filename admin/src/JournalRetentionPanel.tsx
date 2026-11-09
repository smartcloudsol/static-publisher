import { Alert, Button, Card, Group, Loader, Modal, Stack, Table, Text, Title } from "@mantine/core";
import { __ } from "@wordpress/i18n";
import { useRef, useState } from "react";

const DOMAIN = "smartcloud-static-publisher";
type Report = {
  days: number; batchSize: number; cutoff: string; expired: number; skipped: number;
  eligible: number; selected: number; deleted: number; retained: number; remaining: number;
  activeConsumers: unknown[];
};
type Preview = { report: Report; confirmationToken: string; networkWide: boolean; wpCronDisabled: boolean };
export type JournalRequest = <T>(path: string, init?: RequestInit) => Promise<T>;

export default function JournalRetentionPanel({ request }: { request: JournalRequest }) {
  const [opened, setOpened] = useState(false);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [result, setResult] = useState<Report | null>(null);
  const [loading, setLoading] = useState(false);
  const [executing, setExecuting] = useState(false);
  const [error, setError] = useState("");
  const executingRef = useRef(false);

  const review = async () => {
    setOpened(true); setPreview(null); setError(""); setLoading(true);
    try { setPreview(await request<Preview>("/journal-retention")); }
    catch (failure) { setError(failure instanceof Error ? failure.message : __("Journal preview could not be loaded.", DOMAIN)); }
    finally { setLoading(false); }
  };
  const execute = async () => {
    if (!preview || executingRef.current || preview.report.selected < 1) return;
    executingRef.current = true;
    setExecuting(true); setError("");
    try {
      const response = await request<{ report: Report }>("/journal-retention", {
        method: "POST", body: JSON.stringify({ confirmed: true, confirmationToken: preview.confirmationToken }),
      });
      setResult(response.report); setPreview(null); setOpened(false);
    } catch (failure) {
      setPreview(null);
      setError(failure instanceof Error ? failure.message : __("Journal cleanup could not be completed. Review its current state before retrying.", DOMAIN));
    } finally { executingRef.current = false; setExecuting(false); }
  };

  return <>
    <Card withBorder padding="lg">
      <Stack gap="sm">
        <Title order={3}>{__("Content journal cleanup", DOMAIN)}</Title>
        <Text size="sm">{__("WordPress schedules daily journal cleanup automatically. You can also review and remove old journal entries here, one bounded batch at a time.", DOMAIN)}</Text>
        <Text size="sm" c="dimmed">{__("When scheduled WordPress tasks are disabled or site traffic is low, use a reliable server schedule or run cleanup manually.", DOMAIN)}</Text>
        {result && <Alert color="teal" title={__("Cleanup completed", DOMAIN)} role="status">
          <Text>{__("Deleted:", DOMAIN)} {result.deleted} · {__("Retained:", DOMAIN)} {result.retained} · {__("Eligible entries remaining:", DOMAIN)} {result.remaining}</Text>
          {result.remaining > 0 && <Text size="sm">{__("Review and confirm another batch to continue.", DOMAIN)}</Text>}
        </Alert>}
        <Group><Button variant="default" onClick={() => void review()} disabled={loading || executing}>
          {result && result.remaining > 0 ? __("Review next batch", DOMAIN) : __("Review journal cleanup", DOMAIN)}
        </Button></Group>
      </Stack>
    </Card>
    <Modal opened={opened} onClose={() => { if (!executing) setOpened(false); }} centered size="lg" zIndex={100001}
      closeOnEscape={!executing} closeOnClickOutside={!executing} withCloseButton={!executing}
      title={__("Clean up old journal events?", DOMAIN)}>
      <Stack gap="md">
        {loading && <Group><Loader size="sm" /><Text>{__("Checking journal and active consumers…", DOMAIN)}</Text></Group>}
        {error && <Alert color="red" title={__("Cleanup unavailable", DOMAIN)} role="alert">{error}</Alert>}
        {preview && <>
          <Alert color="orange" title={__("Permanent removal of journal history", DOMAIN)}>
            <Text size="sm">{__("Deleted journal history cannot be restored by this action. A consumer whose cursor is older than retained history must complete a new verified full publish before content sync can continue.", DOMAIN)}</Text>
          </Alert>
          <Text size="sm">{__("Existing posts, pages and published static files are preserved. Events still required by active consumers remain protected. Database table rebuilding is a separate maintenance operation.", DOMAIN)}</Text>
          <Text size="sm">{preview.networkWide
            ? __("This shared journal covers all sites in the network and all configured backend profiles.", DOMAIN)
            : __("Cleanup checks all configured backend profiles for this site.", DOMAIN)}</Text>
          {preview.wpCronDisabled && <Alert color="yellow">{__("Automatic WordPress task execution is disabled on this site. Use a server schedule or this manual cleanup action.", DOMAIN)}</Alert>}
          <Table><Table.Tbody>
            {[
              [__("Retention (days)", DOMAIN), preview.report.days],
              [__("Expired entries", DOMAIN), preview.report.expired],
              [__("Protected entries", DOMAIN), preview.report.skipped],
              [__("Eligible entries", DOMAIN), preview.report.eligible],
              [__("Entries in this batch", DOMAIN), preview.report.selected],
              [__("Batch limit", DOMAIN), preview.report.batchSize],
              [__("Active consumer scopes", DOMAIN), preview.report.activeConsumers.length],
            ].map(([label, value]) => <Table.Tr key={label}><Table.Th>{label}</Table.Th><Table.Td>{value}</Table.Td></Table.Tr>)}
          </Table.Tbody></Table>
          <Text size="sm">{__("Retention cutoff:", DOMAIN)} {new Date(preview.report.cutoff.replace(" ", "T") + "Z").toLocaleString()}</Text>
          {preview.report.selected === 0 && <Text>{__("No eligible journal entries need to be removed.", DOMAIN)}</Text>}
        </>}
        <Group justify="flex-end">
          <Button variant="default" disabled={executing} onClick={() => setOpened(false)}>{__("Cancel", DOMAIN)}</Button>
          {!preview && !loading && <Button variant="default" onClick={() => void review()}>{__("Refresh preview", DOMAIN)}</Button>}
          <Button color="red" loading={executing} disabled={!preview || loading || executing || preview.report.selected === 0} onClick={() => void execute()}>
            {__("Delete eligible journal entries", DOMAIN)}
          </Button>
        </Group>
      </Stack>
    </Modal>
  </>;
}
