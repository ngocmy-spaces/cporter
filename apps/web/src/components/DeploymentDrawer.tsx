import { Alert, Button, Divider, Drawer, Group, Loader, Stack, Text, Timeline } from '@mantine/core';
import { IconAlertTriangle, IconCheck, IconX } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { api } from '@/lib/api';
import { formatBytes, formatDateTime, formatDuration } from '@/lib/format';
import { DeploymentStatusBadge } from '@/components/StatusBadge';
import { ReleaseVersion } from '@/components/ReleaseVersion';
import type { ApiEnvelope, Deployment, DeploymentStatus, DeploymentStep, Project } from '@/lib/types';

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
 * The deploy pipeline in execution order (matches DeployEngine on the API). The backend
 * only records COMPLETED steps, so to show the stage that is *currently* running we infer
 * it: the first applicable stage after the furthest step already completed.
 *
 * `matches` maps a recorded step name back to its stage (hook steps carry a dynamic
 * `hook:<phase>:<command>` name). `applies` hides stages that won't run for this project
 * (hooks/health-check are conditional). `write_env` is conditional too but can't be known
 * from the client — the "furthest completed" anchor skips it automatically once a later
 * step lands, so a brief mislabel self-corrects within one poll.
 */
interface PipelineStage {
  key: string;
  label: string;
  matches: (name: string) => boolean;
  applies: (project?: Project) => boolean;
}

const PIPELINE: PipelineStage[] = [
  { key: 'lock', label: 'Acquire lock', matches: (n) => n === 'lock', applies: () => true },
  { key: 'extract', label: 'Extract bundle', matches: (n) => n === 'extract', applies: () => true },
  { key: 'write_env', label: 'Write environment', matches: (n) => n === 'write_env', applies: () => true },
  { key: 'link_shared', label: 'Link shared paths', matches: (n) => n === 'link_shared', applies: () => true },
  { key: 'validate', label: 'Validate release', matches: (n) => n === 'validate', applies: () => true },
  {
    key: 'pre_activate',
    label: 'Pre-activate hooks',
    matches: (n) => n.startsWith('hook:pre_activate'),
    applies: (p) => !!p?.hooks?.pre_activate?.length,
  },
  { key: 'activate', label: 'Activate release', matches: (n) => n === 'activate', applies: () => true },
  {
    key: 'post_activate',
    label: 'Post-activate hooks',
    matches: (n) => n.startsWith('hook:post_activate'),
    applies: (p) => !!p?.hooks?.post_activate?.length,
  },
  {
    key: 'health_check',
    label: 'Health check',
    matches: (n) => n === 'health_check',
    applies: (p) => !!p?.health_check_url,
  },
  { key: 'prune', label: 'Prune old releases', matches: (n) => n === 'prune', applies: () => true },
];

const STAGE_LABELS: Record<string, string> = Object.fromEntries(
  PIPELINE.map((s) => [s.key, s.label]),
);

/** Humanize a recorded step name for display (handles the dynamic `hook:phase:cmd` shape). */
function stepTitle(name: string): string {
  if (name.startsWith('hook:pre_activate')) return 'Pre-activate hook';
  if (name.startsWith('hook:post_activate')) return 'Post-activate hook';
  if (name === 'auto_rollback') return 'Auto rollback';
  return STAGE_LABELS[name] ?? name;
}

/** The raw command carried by a `hook:<phase>:<command>` step name, if any. */
function hookCommand(name: string): string | null {
  if (!name.startsWith('hook:')) return null;
  const cmd = name.split(':').slice(2).join(':');
  return cmd || null;
}

/**
 * Infer the stage that is running now and the stages still to come, from the completed
 * steps + the project's config. Returns `current: null` when everything expected is done
 * (deployment is wrapping up).
 */
function inferProgress(deployment: Deployment): {
  current: PipelineStage | null;
  pending: PipelineStage[];
} {
  const stages = PIPELINE.filter((s) => s.applies(deployment.project));
  let lastIdx = -1;
  for (const step of deployment.steps ?? []) {
    const idx = stages.findIndex((s) => s.matches(step.name));
    if (idx > lastIdx) lastIdx = idx;
  }
  const currentIdx = lastIdx + 1;
  return { current: stages[currentIdx] ?? null, pending: stages.slice(currentIdx + 1) };
}

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

      {query.isError && (
        <Alert color="red" variant="light" icon={<IconAlertTriangle size={16} />} title="Couldn't load deployment">
          <Stack gap="xs" align="flex-start">
            <Text size="sm">The deployment details could not be loaded.</Text>
            <Button variant="light" size="xs" onClick={() => query.refetch()}>
              Retry
            </Button>
          </Stack>
        </Alert>
      )}

      {deployment && (
        <Stack gap="md">
          <Group gap="xs">
            <DeploymentStatusBadge status={deployment.status} />
            <Text size="sm" c="dimmed">
              {deployment.project?.name ?? `project #${deployment.project_id}`} · release{' '}
              <ReleaseVersion
                version={deployment.release?.version}
                fallback={`#${deployment.release_id}`}
              />
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

          {(() => {
            const steps = deployment.steps ?? [];
            const running = !TERMINAL_STATUSES.has(deployment.status);
            if (steps.length === 0 && !running) {
              return (
                <Text size="sm" c="dimmed">
                  No steps recorded.
                </Text>
              );
            }

            const { current, pending } = running
              ? inferProgress(deployment)
              : { current: null, pending: [] };

            return (
              <Timeline active={steps.length} bulletSize={22} lineWidth={2}>
                {steps.map((step, index) => {
                  const cmd = hookCommand(step.name);
                  return (
                    <Timeline.Item
                      key={`${step.name}-${index}`}
                      title={stepTitle(step.name)}
                      color={STEP_COLOR[step.status] ?? 'red'}
                      bullet={STEP_BULLET[step.status] ?? <IconX size={12} />}
                    >
                      <Text size="xs" c="dimmed">
                        {formatDuration(step.duration_ms)}
                        {cmd ? ` · ${cmd}` : ''}
                      </Text>
                      {step.error && (
                        <Text size="xs" c="red" mt={4}>
                          {step.error}
                        </Text>
                      )}
                      {step.note && (
                        <Text size="xs" c="dimmed" mt={4}>
                          {step.note}
                        </Text>
                      )}
                    </Timeline.Item>
                  );
                })}

                {running && (
                  <Timeline.Item
                    key="in-progress"
                    lineVariant="dashed"
                    title={current ? `${current.label}…` : 'Finishing up…'}
                    bullet={<Loader size={12} />}
                  >
                    <Text size="xs" c="dimmed">
                      {deployment.status === 'hooks_pending'
                        ? 'Waiting for the worker to run the activation phase.'
                        : 'In progress…'}
                    </Text>
                  </Timeline.Item>
                )}

                {running &&
                  pending.map((stage) => (
                    <Timeline.Item
                      key={`pending-${stage.key}`}
                      lineVariant="dashed"
                      title={
                        <Text size="sm" c="dimmed">
                          {stage.label}
                        </Text>
                      }
                    >
                      <Text size="xs" c="dimmed">
                        Pending
                      </Text>
                    </Timeline.Item>
                  ))}
              </Timeline>
            );
          })()}
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
