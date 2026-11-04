import { ActionIcon, Checkbox, Group } from "@mantine/core";
import { IconInfoCircle } from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";

const DOMAIN = "smartcloud-static-publisher";

export default function TargetedAssetOptions({ checked, onChange, disabled, onHelp }: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
  onHelp?: () => void;
}) {
  return <Group gap="xs" align="flex-start" wrap="nowrap" style={{ minWidth: 0 }}>
    <Checkbox checked={checked} disabled={disabled} onChange={event => onChange(event.currentTarget.checked)}
      style={{ flex: 1, minWidth: 0 }}
      label={__("Download assets again for this job", DOMAIN)}
      description={__("Reuse completed assets by default. Enable to fetch them again; the selected pages stay the same.", DOMAIN)} />
    {onHelp && <ActionIcon variant="subtle" size="sm" onClick={onHelp}
      aria-label={__("Open asset refresh help", DOMAIN)}>
      <IconInfoCircle size={16} />
    </ActionIcon>}
  </Group>;
}
