import { ActionIcon, Group, Textarea } from "@mantine/core";
import { IconInfoCircle } from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";

const DOMAIN = "smartcloud-static-publisher";

export default function UrlJobInput({ value, onChange, disabled, onHelp }: {
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  onHelp?: () => void;
}) {
  return <Group gap="xs" align="flex-start" wrap="nowrap" style={{ minWidth: 0 }}>
    <Textarea label={__("URLs (one per line)", DOMAIN)} value={value}
      onChange={event => onChange(event.currentTarget.value)} disabled={disabled}
      autosize minRows={4} maxRows={10} style={{ flex: 1, minWidth: 0 }}
      placeholder={"/blog/first-page/\n/blog/second-page/"}
      description={__("One URL or path per line. Blank lines and duplicates are ignored. All URLs run in one job.", DOMAIN)} />
    {onHelp && <ActionIcon variant="subtle" size="sm" onClick={onHelp}
      aria-label={__("Open URL list help", DOMAIN)}>
      <IconInfoCircle size={16} />
    </ActionIcon>}
  </Group>;
}
