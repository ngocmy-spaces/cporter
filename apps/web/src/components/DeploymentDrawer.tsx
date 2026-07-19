import { Divider, Drawer, Group, Loader, Stack, Text, Timeline } from '@mantine/core';
import { IconAlertTriangle, IconCheck, IconX } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { api } from '@/lib/api';
import { formatBytes, formatDateTime } from '@/lib/format';
import { DeploymentStatusBadge } from '@/components/StatusBadge';
import type { ApiEnvelope, Deployment, DeploymentStatus, DeploymentStep } from '@/lib/types';

const TERMINAL_STATUSES = new Set<DeploymentStatus>(['success', 'failed', 'rolled_back']);

const STEP_COLOR: Record<DeploymentStep['status'], string> = {
  success: 'green',
  failed: 'red',
  warning: 'yellow',
};

const STEP_BULLET: Record<DeploymentStep['status'], ReactNode> = {
  success: <IconCheck size={12} />,
  failed: <IconX size={12} />,
  warning: <IconAlertTriangle size={12} />,
};

/**
 * Drawer showing one deployment's steps as a Timeline. Polls while the deployment is
 * still in-flight (queued/running/hooks_pending) — cPanel has no websockets.
 */
export function DeploymentDrawer({
  deploymentId,
  onClose,
}: {
  deploymentId: number | null;
  onClose: () => void;
}) {
  const query = useQuery({
    queryKey: ['deployments', deploymentId],
    queryFn: async () =>
      (await api.get<ApiEnvelope<Deployment>>(`/deployments/${deploymentId}`)).data.data,
    enabled: deploymentId !== null,
    refetchInterval: (q) => (q.state.data && !TERMINAL_STATUSES.has(q.state.data.status) ? 2000 : false),
  });

  const deployment = query.data;

  return (
    <Drawer
      opened={deploymentId !== null}
      onClose={onClose}
      position="right"
      size="md"
      title={deployment ? `Deployment #${deployment.id}` : 'Deployment'}
    >
      {query.isLoading && (
        <Group justify="center" p="xl">
          <Loader />
        </Group>
      )}

      {deployment && (
        <Stack gap="md">
          <Group gap="xs">
            <DeploymentStatusBadge status={deployment.status} />
            <Text size="sm" c="dimmed">
              {deployment.project?.name ?? `project #${deployment.project_id}`} · release{' '}
              {deployment.release?.version ?? `#${deployment.release_id}`}
            </Text>
          </Group>

          <Stack gap={4}>
            <Meta label="Trigger" value={deployment.trigger} />
            <Meta label="Actor" value={deployment.actor ?? '—'} />
            <Meta label="Bundle size" value={formatBytes(deployment.release?.artifact?.size)} />
            <Meta label="Started" value={formatDateTime(deployment.started_at)} />
            <Meta label="Finished" value={formatDateTime(deployment.finished_at)} />
          </Stack>

          <Divider label="Steps" labelPosition="left" />

          {deployment.steps && deployment.steps.length > 0 ? (
            <Timeline active={deployment.steps.length} bulletSize={22} lineWidth={2}>
              {deployment.steps.map((step, index) => (
                <Timeline.Item
                  key={`${step.name}-${index}`}
                  title={step.name}
                  color={STEP_COLOR[step.status] ?? 'red'}
                  bullet={STEP_BULLET[step.status] ?? <IconX size={12} />}
                >
                  <Text size="xs" c="dimmed">
                    {step.duration_ms} ms
                  </Text>
                  {step.error && (
                    <Text size="xs" c="red" mt={4}>
                      {step.error}
                    </Text>
                  )}
                  {step.note && (
                    <Text size="xs" c="yellow.8" mt={4}>
                      {step.note}
                    </Text>
                  )}
                </Timeline.Item>
              ))}
            </Timeline>
          ) : (
            <Text size="sm" c="dimmed">
              {TERMINAL_STATUSES.has(deployment.status) ? 'No steps recorded.' : 'Waiting for steps…'}
            </Text>
          )}
        </Stack>
      )}
    </Drawer>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <Group justify="space-between" gap="xs">
      <Text size="xs" c="dimmed" tt="uppercase" fw={700}>
        {label}
      </Text>
      <Text size="sm">{value}</Text>
    </Group>
  );
}
