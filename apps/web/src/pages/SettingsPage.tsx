import { Alert, Button, Card, Group, List, Loader, SimpleGrid, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { IconCheck, IconRefresh, IconX } from '@tabler/icons-react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatBytes, formatDateTime } from '@/lib/format';
import type { Capabilities } from '@/lib/types';

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

  const refresh = useMutation({
    mutationFn: async () => (await api.post<CapabilitiesResponse>('/system/capabilities/refresh')).data,
    onSuccess: (data) => {
      queryClient.setQueryData(['system', 'capabilities'], data);
    },
  });

  if (capabilities.isLoading) {
    return (
      <Group justify="center" p="xl">
        <Loader />
      </Group>
    );
  }

  const result = capabilities.data?.data;
  if (!result) {
    return <Alert color="red">Unable to load system capabilities.</Alert>;
  }

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
