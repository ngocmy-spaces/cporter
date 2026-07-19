import { useMemo, useState } from 'react';
import {
  Card,
  Group,
  Loader,
  Paper,
  SegmentedControl,
  Select,
  SimpleGrid,
  Stack,
  Table,
  Text,
  Title,
} from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { DeploymentDrawer } from '@/components/DeploymentDrawer';
import { DeploymentStatusBadge } from '@/components/StatusBadge';
import { formatRelativeTime } from '@/lib/format';
import type { ApiEnvelope, Deployment, DeploymentStatus } from '@/lib/types';

const IN_FLIGHT = new Set<DeploymentStatus>(['queued', 'running', 'hooks_pending']);
const FAILED = new Set<DeploymentStatus>(['failed', 'rolled_back']);

const STATUS_FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'in_flight', label: 'In-flight' },
  { value: 'success', label: 'Success' },
  { value: 'failed', label: 'Failed' },
];

function matchesStatus(status: DeploymentStatus, filter: string): boolean {
  if (filter === 'all') return true;
  if (filter === 'in_flight') return IN_FLIGHT.has(status);
  if (filter === 'failed') return FAILED.has(status);
  return status === filter;
}

export function DeploymentsPage() {
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [projectFilter, setProjectFilter] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('all');

  const deployments = useQuery({
    queryKey: ['deployments'],
    queryFn: async () => (await api.get<ApiEnvelope<Deployment[]>>('/deployments')).data.data,
    refetchInterval: 10_000,
  });

  const all = useMemo(() => deployments.data ?? [], [deployments.data]);

  const stats = useMemo(
    () => ({
      total: all.length,
      inFlight: all.filter((d) => IN_FLIGHT.has(d.status)).length,
      success: all.filter((d) => d.status === 'success').length,
      failed: all.filter((d) => FAILED.has(d.status)).length,
    }),
    [all],
  );

  // Distinct projects present in the feed — populates the project filter without a second fetch.
  const projectOptions = useMemo(() => {
    const seen = new Map<string, string>();
    for (const d of all) {
      const id = String(d.project_id);
      if (!seen.has(id)) seen.set(id, d.project?.name ?? `#${d.project_id}`);
    }
    return Array.from(seen, ([value, label]) => ({ value, label }));
  }, [all]);

  const filtered = useMemo(
    () =>
      all.filter(
        (d) =>
          (!projectFilter || String(d.project_id) === projectFilter) &&
          matchesStatus(d.status, statusFilter),
      ),
    [all, projectFilter, statusFilter],
  );

  return (
    <Stack gap="lg">
      <div>
        <Title order={2}>Deployments</Title>
        <Text c="dimmed" size="sm">
          The 50 most recent deployments across all projects. Click a row to view its step timeline.
        </Text>
      </div>

      <SimpleGrid cols={{ base: 2, sm: 4 }}>
        <Stat label="Recent" value={deployments.isLoading ? '—' : String(stats.total)} />
        <Stat
          label="In-flight"
          value={deployments.isLoading ? '—' : String(stats.inFlight)}
          color={stats.inFlight > 0 ? 'blue' : undefined}
        />
        <Stat label="Succeeded" value={deployments.isLoading ? '—' : String(stats.success)} />
        <Stat
          label="Failed"
          value={deployments.isLoading ? '—' : String(stats.failed)}
          color={stats.failed > 0 ? 'red' : undefined}
        />
      </SimpleGrid>

      <Group justify="space-between" wrap="wrap" gap="sm">
        <Select
          placeholder="All projects"
          data={projectOptions}
          value={projectFilter}
          onChange={setProjectFilter}
          clearable
          searchable
          w={260}
        />
        <SegmentedControl data={STATUS_FILTERS} value={statusFilter} onChange={setStatusFilter} size="sm" />
      </Group>

      <Paper withBorder radius="md">
        {deployments.isLoading ? (
          <Group justify="center" p="xl">
            <Loader />
          </Group>
        ) : (
          <Table.ScrollContainer minWidth={700}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Project</Table.Th>
                  <Table.Th>Release</Table.Th>
                  <Table.Th>Status</Table.Th>
                  <Table.Th>Trigger</Table.Th>
                  <Table.Th>When</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {filtered.map((d) => (
                  <Table.Tr key={d.id} onClick={() => setSelectedId(d.id)} style={{ cursor: 'pointer' }}>
                    <Table.Td>{d.project?.name ?? `#${d.project_id}`}</Table.Td>
                    <Table.Td>{d.release?.version ?? `#${d.release_id}`}</Table.Td>
                    <Table.Td>
                      <DeploymentStatusBadge status={d.status} />
                    </Table.Td>
                    <Table.Td>{d.trigger}</Table.Td>
                    <Table.Td>{formatRelativeTime(d.created_at)}</Table.Td>
                  </Table.Tr>
                ))}
                {filtered.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={5}>
                      <Text c="dimmed" size="sm" ta="center" py="sm">
                        {all.length === 0
                          ? 'No deployments yet.'
                          : 'No deployments match the current filters.'}
                      </Text>
                    </Table.Td>
                  </Table.Tr>
                )}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>

      <DeploymentDrawer deploymentId={selectedId} onClose={() => setSelectedId(null)} />
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
