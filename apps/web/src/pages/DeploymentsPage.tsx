import { useState } from 'react';
import { Group, Loader, Paper, Stack, Table, Text, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { DeploymentDrawer } from '@/components/DeploymentDrawer';
import { DeploymentStatusBadge } from '@/components/StatusBadge';
import { formatRelativeTime } from '@/lib/format';
import type { ApiEnvelope, Deployment } from '@/lib/types';

export function DeploymentsPage() {
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const deployments = useQuery({
    queryKey: ['deployments'],
    queryFn: async () => (await api.get<ApiEnvelope<Deployment[]>>('/deployments')).data.data,
    refetchInterval: 10_000,
  });

  return (
    <Stack gap="lg">
      <div>
        <Title order={2}>Deployments</Title>
        <Text c="dimmed" size="sm">
          Recent deployments across all projects. Click a row to view its step timeline.
        </Text>
      </div>

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
                {(deployments.data ?? []).map((d) => (
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
                {deployments.data?.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={5}>
                      <Text c="dimmed" size="sm">
                        No deployments yet.
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
