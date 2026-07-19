import { useState } from 'react';
import {
  Alert,
  Card,
  Group,
  Loader,
  Paper,
  SimpleGrid,
  Stack,
  Table,
  Text,
  ThemeIcon,
  Title,
} from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { IconAlertTriangle, IconCircleCheck, IconPlayerPlay } from '@tabler/icons-react';
import { api } from '@/lib/api';
import { DeploymentDrawer } from '@/components/DeploymentDrawer';
import { DeploymentStatusBadge } from '@/components/StatusBadge';
import { formatRelativeTime } from '@/lib/format';
import type { ApiEnvelope, Deployment, DeploymentStatus, Project } from '@/lib/types';

const TERMINAL_STATUSES = new Set(['success', 'failed', 'rolled_back']);
const IN_FLIGHT_STATUSES = new Set<DeploymentStatus>(['queued', 'running', 'hooks_pending']);
const DAY_MS = 24 * 60 * 60 * 1000;
const STUCK_THRESHOLD_MS = 10 * 60 * 1000;

export function DashboardPage() {
  const [selectedDeployment, setSelectedDeployment] = useState<number | null>(null);

  const projects = useQuery({
    queryKey: ['projects'],
    queryFn: async () => (await api.get<ApiEnvelope<Project[]>>('/projects')).data.data,
  });

  const deployments = useQuery({
    queryKey: ['deployments'],
    queryFn: async () => (await api.get<ApiEnvelope<Deployment[]>>('/deployments')).data.data,
    refetchInterval: 15_000,
  });

  const allProjects = projects.data ?? [];
  const allDeployments = deployments.data ?? [];

  const now = Date.now();
  const last24h = allDeployments.filter((d) => now - new Date(d.created_at).getTime() <= DAY_MS);
  const terminalLast24h = last24h.filter((d) => TERMINAL_STATUSES.has(d.status));
  const successRate =
    terminalLast24h.length > 0
      ? `${Math.round(
          (terminalLast24h.filter((d) => d.status === 'success').length / terminalLast24h.length) * 100,
        )}%`
      : '—';

  const activeProjects = allProjects.filter((p) => p.status === 'active').length;
  const recentDeployments = allDeployments.slice(0, 8);

  const failedRecent = recentDeployments.filter(
    (d) => d.status === 'failed' || d.status === 'rolled_back',
  );
  const inFlight = recentDeployments.filter((d) => IN_FLIGHT_STATUSES.has(d.status));
  const stuck = inFlight.filter(
    (d) => now - new Date(d.started_at ?? d.created_at).getTime() > STUCK_THRESHOLD_MS,
  );
  const hasAlerts = failedRecent.length > 0 || inFlight.length > 0;

  return (
    <Stack gap="lg">
      <div>
        <Title order={2}>Overview</Title>
        <Text c="dimmed" size="sm">
          A snapshot of the cPorter system.
        </Text>
      </div>

      <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
        <Stat label="Projects" value={projects.isLoading ? '—' : String(allProjects.length)} />
        <Stat label="Active projects" value={projects.isLoading ? '—' : String(activeProjects)} />
        <Stat label="Deployments (24h)" value={deployments.isLoading ? '—' : String(last24h.length)} />
        <Stat
          label="Success rate (24h)"
          value={deployments.isLoading ? '—' : successRate}
          color={successRate !== '—' && parseInt(successRate, 10) < 90 ? 'orange' : undefined}
        />
      </SimpleGrid>

      <Card withBorder padding="md" radius="md">
        <Text size="xs" tt="uppercase" fw={700} c="dimmed" mb="xs">
          Alerts
        </Text>
        {deployments.isLoading ? (
          <Group justify="center" p="md">
            <Loader size="sm" />
          </Group>
        ) : !hasAlerts ? (
          <Group gap="xs">
            <ThemeIcon color="green" variant="light" size={22} radius="xl">
              <IconCircleCheck size={14} />
            </ThemeIcon>
            <Text size="sm" c="dimmed">
              All clear — no failures or stuck deployments among the recent runs.
            </Text>
          </Group>
        ) : (
          <Stack gap="xs">
            {failedRecent.length > 0 && (
              <Alert color="red" icon={<IconAlertTriangle size={16} />} variant="light" p="xs">
                {failedRecent.length} deployment{failedRecent.length === 1 ? '' : 's'} failed or rolled
                back recently.
              </Alert>
            )}
            {inFlight.length > 0 && (
              <Alert
                color={stuck.length > 0 ? 'orange' : 'blue'}
                icon={
                  stuck.length > 0 ? <IconAlertTriangle size={16} /> : <IconPlayerPlay size={16} />
                }
                variant="light"
                p="xs"
              >
                {inFlight.length} deployment{inFlight.length === 1 ? ' is' : 's are'} currently in
                flight
                {stuck.length > 0 && (
                  <> — {stuck.length} stuck for over 10 minutes.</>
                )}
              </Alert>
            )}
          </Stack>
        )}
      </Card>

      <Paper withBorder p="lg" radius="md">
        <Group justify="space-between" mb="md">
          <Title order={4}>Recent deployments</Title>
        </Group>

        {deployments.isLoading ? (
          <Group justify="center" p="xl">
            <Loader />
          </Group>
        ) : recentDeployments.length === 0 ? (
          <Text c="dimmed" size="sm">
            No deployments yet.
          </Text>
        ) : (
          <Table.ScrollContainer minWidth={600}>
            <Table verticalSpacing="sm" highlightOnHover>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Project</Table.Th>
                  <Table.Th>Status</Table.Th>
                  <Table.Th>Trigger</Table.Th>
                  <Table.Th>When</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {recentDeployments.map((d) => (
                  <Table.Tr
                    key={d.id}
                    onClick={() => setSelectedDeployment(d.id)}
                    style={{ cursor: 'pointer' }}
                  >
                    <Table.Td>{d.project?.name ?? `#${d.project_id}`}</Table.Td>
                    <Table.Td>
                      <DeploymentStatusBadge status={d.status} />
                    </Table.Td>
                    <Table.Td>{d.trigger}</Table.Td>
                    <Table.Td>{formatRelativeTime(d.created_at)}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>

      <DeploymentDrawer deploymentId={selectedDeployment} onClose={() => setSelectedDeployment(null)} />
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
