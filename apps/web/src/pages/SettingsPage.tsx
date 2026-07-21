import { Alert, Badge, Button, Card, Code, Group, List, SimpleGrid, Skeleton, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { IconCheck, IconRefresh, IconX } from '@tabler/icons-react';
import { PanelBody } from '@/components/PanelBody';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { notifyError, notifySuccess } from '@/lib/feedback';
import { formatBytes, formatDateTime, formatRelativeTime } from '@/lib/format';
import type { Capabilities, CronStatus, StorageStatus, StorageWarningCode } from '@/lib/types';

interface CapabilitiesResponse {
  data: Capabilities;
  probed_at: string;
}

export function SettingsPage() {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  const capabilities = useQuery({
    queryKey: ['system', 'capabilities'],
    queryFn: async () => (await api.get<CapabilitiesResponse>('/system/capabilities')).data,
  });

  const cron = useQuery({
    queryKey: ['system', 'cron'],
    queryFn: async () => (await api.get<{ data: CronStatus }>('/system/cron')).data.data,
    refetchInterval: 30_000, // keep the liveness view fresh
  });

  const storage = useQuery({
    queryKey: ['system', 'storage'],
    queryFn: async () => (await api.get<{ data: StorageStatus }>('/system/storage')).data.data,
    refetchInterval: 30_000, // keep the liveness view fresh
  });

  const refresh = useMutation({
    mutationFn: async () => (await api.post<CapabilitiesResponse>('/system/capabilities/refresh')).data,
    onSuccess: (data) => {
      queryClient.setQueryData(['system', 'capabilities'], data);
      notifySuccess('Capabilities re-probed', 'Host capabilities have been refreshed.');
    },
    onError: (error) => notifyError('Re-probe failed', error),
  });

  const result = capabilities.data?.data;

  return (
    <Stack gap="lg">
      <Group justify="space-between">
        <div>
          <Title order={2}>System</Title>
          <Text c="dimmed" size="sm">
            Host capabilities — last probed {formatDateTime(capabilities.data?.probed_at)}
          </Text>
        </div>
        {isAdmin && (
          <Button
            leftSection={<IconRefresh size={16} />}
            onClick={() => refresh.mutate()}
            loading={refresh.isPending}
          >
            Re-probe
          </Button>
        )}
      </Group>

      <CronCard status={cron.data} loading={cron.isLoading} />

      <StorageCard status={storage.data} loading={storage.isLoading} />

      <PanelBody
        query={capabilities}
        errorTitle="Couldn't load system capabilities"
        loader={
          <Stack gap="lg">
            <SimpleGrid cols={{ base: 1, sm: 2 }}>
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} height={180} radius="md" />
              ))}
            </SimpleGrid>
            <Skeleton height={120} radius="md" />
          </Stack>
        }
      >
        {result && (
          <Stack gap="lg">
            <SimpleGrid cols={{ base: 1, sm: 2 }}>
              <Card withBorder radius="md" p="md">
                <Text fw={600} mb="xs">
                  Runtime
                </Text>
                <Stack gap={4}>
                  <Row label="PHP version" value={result.php_version} />
                  <Row label="SAPI" value={result.sapi} />
                  <Row label="open_basedir" value={result.open_basedir ?? '—'} />
                  <Row label="Command driver" value={result.command_driver ?? '—'} />
                  <Row label="Cron token" value={result.cron_token_configured ? 'configured' : 'missing'} />
                  <Row label="Symlink runtime" value={result.symlink_runtime ? 'available' : 'unavailable'} />
                </Stack>
              </Card>

              <Card withBorder radius="md" p="md">
                <Text fw={600} mb="xs">
                  Limits &amp; disk
                </Text>
                <Stack gap={4}>
                  <Row label="Upload max filesize" value={result.limits.upload_max_filesize} />
                  <Row label="Post max size" value={result.limits.post_max_size} />
                  <Row label="Memory limit" value={result.limits.memory_limit} />
                  <Row label="Max execution time" value={`${result.limits.max_execution_time}s`} />
                  <Row label="Disk free" value={formatBytes(result.disk.free)} />
                  <Row label="Disk total" value={formatBytes(result.disk.total)} />
                </Stack>
              </Card>

              <Card withBorder radius="md" p="md">
                <Text fw={600} mb="xs">
                  Extensions
                </Text>
                <FlagList flags={result.extensions} />
              </Card>

              <Card withBorder radius="md" p="md">
                <Text fw={600} mb="xs">
                  Functions
                </Text>
                <FlagList flags={result.functions} />
              </Card>
            </SimpleGrid>

            <Card withBorder radius="md" p="md">
              <Group justify="space-between" mb={4}>
                <Text fw={600}>Detected binaries</Text>
                <Badge variant="light" color={result.binaries_source === 'cron' ? 'green' : 'yellow'}>
                  {result.binaries_source === 'cron' ? 'cron shell' : 'PATH scan'}
                </Badge>
              </Group>
              <Text c="dimmed" size="xs" mb="sm">
                {result.binaries_source === 'cron' ? (
                  <>
                    Detected in the cron shell via <Code>command -v</Code> — the exact PATH hooks run
                    with, so this is authoritative. Probed{' '}
                    {formatRelativeTime(result.binaries_detected_at)}.
                  </>
                ) : (
                  <>
                    Fallback scan of the web server&apos;s <Code>$PATH</Code> — the cron shell that
                    runs hooks may differ, so treat this as a hint. It becomes authoritative once the
                    cron-worker runs (<Code>cporter:probe-binaries</Code>).
                  </>
                )}
              </Text>
              {Object.keys(result.binaries ?? {}).length === 0 ? (
                <Text c="dimmed" size="sm">
                  Not probed yet — click <b>Re-probe</b> to detect binaries.
                </Text>
              ) : (
                <List spacing={8} size="sm" center>
                  {Object.entries(result.binaries ?? {}).map(([name, path]) => (
                    <List.Item
                      key={name}
                      icon={
                        <ThemeIcon color={path ? 'green' : 'red'} size={18} radius="xl" variant="light">
                          {path ? <IconCheck size={12} /> : <IconX size={12} />}
                        </ThemeIcon>
                      }
                    >
                      <Group gap="xs" wrap="nowrap">
                        <Text size="sm" fw={500} w={80}>
                          {name}
                        </Text>
                        {path ? <Code>{path}</Code> : <Text size="sm" c="dimmed">not found</Text>}
                      </Group>
                    </List.Item>
                  ))}
                </List>
              )}
            </Card>

            <Card withBorder radius="md" p="md">
              <Text fw={600} mb="xs">
                Allowed base paths
              </Text>
              {result.allowed_base_paths.length === 0 ? (
                <Text c="dimmed" size="sm">
                  None configured.
                </Text>
              ) : (
                <List spacing={4} size="sm">
                  {result.allowed_base_paths.map((p) => (
                    <List.Item key={p.path}>
                      {p.path} — {p.exists ? 'exists' : 'missing'}, {p.writable ? 'writable' : 'read-only'}
                    </List.Item>
                  ))}
                </List>
              )}
            </Card>
          </Stack>
        )}
      </PanelBody>
    </Stack>
  );
}

const MODE_LABEL: Record<'A' | 'B', string> = {
  A: 'A — schedule:run (1-minute cron)',
  B: 'B — cporter:work (5-minute cron loop)',
};

function CronCard({ status, loading }: { status: CronStatus | undefined; loading: boolean }) {
  const meta = {
    healthy: { color: 'green', label: 'Running' },
    down: { color: 'red', label: 'Not running' },
    unknown: { color: 'gray', label: 'Never run' },
  } as const;

  const state = status?.state ?? 'unknown';
  const { color, label } = meta[state];

  return (
    <Card withBorder radius="md" p="md">
      <Group justify="space-between" mb="xs">
        <Text fw={600}>Cron / Worker</Text>
        <Badge color={color} variant="light">
          {loading ? 'Checking…' : label}
        </Badge>
      </Group>

      <Stack gap={4}>
        <Row label="Mode" value={status?.mode ? MODE_LABEL[status.mode] : '—'} />
        <Row label="Last beat" value={formatRelativeTime(status?.last_run_at)} />
        <Row label="Host" value={status?.host ?? '—'} />
        <Row label="Passes (last tick)" value={status?.passes != null ? String(status.passes) : '—'} />
      </Stack>

      {state === 'down' && (
        <Alert color="red" mt="md" title="Cron is not running">
          Laravel deploys will stall in <b>hooks_pending</b> until the cron resumes. Check the cPanel
          cron job runs the scheduler (or <b>cporter:work</b> on 5-minute hosts) — see
          docs/DEPLOYMENT-CPANEL.md §4.
        </Alert>
      )}
      {state === 'unknown' && (
        <Alert color="yellow" mt="md" title="No cron heartbeat yet">
          The cron has never checked in. Add the cPanel cron job (schedule:run every minute, or
          cporter:work every 5 minutes) — see docs/DEPLOYMENT-CPANEL.md §4.
        </Alert>
      )}
    </Card>
  );
}

const STORAGE_WARNING_COPY: Record<StorageWarningCode, string> = {
  pruning_disabled:
    'Artifact pruning is disabled (CPORTER_PRUNE_ARTIFACTS) — zips are not reclaimed.',
  store_over_threshold: 'Artifact store exceeds the warning threshold.',
  sweep_stale: 'Last cleanup sweep is stale — the housekeeper may not be running.',
};

function StorageCard({ status, loading }: { status: StorageStatus | undefined; loading: boolean }) {
  const meta = {
    healthy: { color: 'green', label: 'Healthy' },
    warning: { color: 'yellow', label: 'Warning' },
    unknown: { color: 'gray', label: 'Never run' },
  } as const;

  const state = status?.state ?? 'unknown';
  const { color, label } = meta[state];
  const warnings = status?.warnings ?? [];

  return (
    <Card withBorder radius="md" p="md">
      <Group justify="space-between" mb="xs">
        <Text fw={600}>Artifact storage</Text>
        <Badge color={color} variant="light">
          {loading ? 'Checking…' : label}
        </Badge>
      </Group>

      <Stack gap={4}>
        <Row label="Store size" value={formatBytes(status?.store_bytes)} />
        <Row
          label="Archives on disk"
          value={status?.unpruned_count != null ? `${status.unpruned_count} archives` : '—'}
        />
        <Row label="Last cleanup" value={formatRelativeTime(status?.last_run_at)} />
        <Row
          label="Last sweep result"
          value={
            status?.reclaimed_count != null
              ? `reclaimed ${status.reclaimed_count} · freed ${formatBytes(status.freed_bytes)}`
              : '—'
          }
        />
        <Row label="Host" value={status?.host ?? '—'} />
      </Stack>

      {state === 'unknown' && (
        <Alert color="gray" mt="md" title="No cleanup sweep yet">
          No cleanup sweep has run yet.
        </Alert>
      )}
      {warnings.length > 0 && (
        <Alert color={state === 'warning' ? 'yellow' : 'gray'} mt="md" title="Storage warnings">
          <List spacing={4} size="sm">
            {warnings.map((code) => (
              <List.Item key={code}>
                {code === 'store_over_threshold'
                  ? `Artifact store exceeds the warning threshold (${formatBytes(status?.warn_bytes)}).`
                  : STORAGE_WARNING_COPY[code]}
              </List.Item>
            ))}
          </List>
        </Alert>
      )}
    </Card>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <Group justify="space-between" gap="xs">
      <Text size="sm" c="dimmed">
        {label}
      </Text>
      <Text size="sm">{value}</Text>
    </Group>
  );
}

function FlagList({ flags }: { flags: Record<string, boolean> }) {
  return (
    <List spacing={4} size="sm" center>
      {Object.entries(flags).map(([name, ok]) => (
        <List.Item
          key={name}
          icon={
            <ThemeIcon color={ok ? 'green' : 'red'} size={18} radius="xl" variant="light">
              {ok ? <IconCheck size={12} /> : <IconX size={12} />}
            </ThemeIcon>
          }
        >
          {name}
        </List.Item>
      ))}
    </List>
  );
}
