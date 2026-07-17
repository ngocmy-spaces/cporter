import { Card, Paper, SimpleGrid, Stack, Text, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export function DashboardPage() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: async () => (await api.get<{ status: string }>('/health')).data,
  });

  const apiStatus = health.isLoading
    ? 'checking…'
    : health.isError
      ? 'unreachable'
      : (health.data?.status ?? 'unknown');

  const apiColor = health.isSuccess ? 'teal' : health.isError ? 'red' : undefined;

  return (
    <Stack gap="lg">
      <div>
        <Title order={2}>Dashboard</Title>
        <Text c="dimmed" size="sm">
          Tổng quan hệ thống cPorter.
        </Text>
      </div>

      <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
        <Stat label="API" value={apiStatus} color={apiColor} />
        <Stat label="Projects" value="—" />
        <Stat label="Deployments (24h)" value="—" />
        <Stat label="Releases" value="—" />
      </SimpleGrid>

      <Paper withBorder p="lg" radius="md">
        <Text c="dimmed" size="sm">
          Widgets (deploy gần đây, success rate, cảnh báo) sẽ thêm ở Phase 3 — xem TASKS.md.
        </Text>
      </Paper>
    </Stack>
  );
}

function Stat({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <Card withBorder padding="md" radius="md">
      <Text size="xs" tt="uppercase" fw={700} c="dimmed">
        {label}
      </Text>
      <Text size="xl" fw={700} c={color} mt={4}>
        {value}
      </Text>
    </Card>
  );
}
