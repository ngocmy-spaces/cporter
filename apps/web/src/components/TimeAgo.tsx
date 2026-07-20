import { Text, Tooltip } from '@mantine/core';
import { formatDateTime, formatRelativeTime } from '@/lib/format';

/**
 * Relative timestamp ("3 hours ago") with the absolute local time revealed in a
 * Tooltip on hover — so feeds stay scannable while the exact time is one hover away.
 */
export function TimeAgo({ iso }: { iso: string | null | undefined }) {
  if (!iso) return <>—</>;
  return (
    <Tooltip label={formatDateTime(iso)} withArrow>
      <Text span inherit style={{ cursor: 'help' }}>
        {formatRelativeTime(iso)}
      </Text>
    </Tooltip>
  );
}
