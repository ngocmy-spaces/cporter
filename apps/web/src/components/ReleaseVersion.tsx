import { Text, Tooltip } from '@mantine/core';
import { isShortenedRef, shortRef } from '@/lib/format';

/**
 * Renders a release version. When the value looks like a git commit SHA it is shortened
 * to its first 8 chars with a Tooltip revealing the full SHA on hover; human labels/tags
 * (e.g. `v1.2.3`, `20260720_001`) render verbatim. Display-only — storage is unchanged.
 */
export function ReleaseVersion({
  version,
  fallback = '—',
}: {
  version: string | null | undefined;
  fallback?: string;
}) {
  if (!version) return <>{fallback}</>;

  const short = shortRef(version);
  if (!isShortenedRef(version)) return <>{short}</>;

  return (
    <Tooltip label={version} withArrow>
      <Text span inherit ff="monospace" style={{ cursor: 'help' }}>
        {short}
      </Text>
    </Tooltip>
  );
}
