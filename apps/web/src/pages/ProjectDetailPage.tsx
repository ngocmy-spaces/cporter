import { useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  Anchor,
  Badge,
  Breadcrumbs,
  Button,
  Card,
  Code,
  Drawer,
  Group,
  Loader,
  Paper,
  SimpleGrid,
  Stack,
  Table,
  Tabs,
  Text,
  Title,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import { IconCheck, IconExternalLink, IconFolders, IconRefresh, IconX } from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { DeploymentDrawer } from '@/components/DeploymentDrawer';
import { DeploymentStatusBadge, ReleaseStateBadge } from '@/components/StatusBadge';
import { formatBytes, formatDateTime, formatRelativeTime } from '@/lib/format';
import type { ApiEnvelope, Deployment, Project, Release } from '@/lib/types';

export function ProjectDetailPage() {
  const { slug } = useParams<{ slug: string }>();
  const [selectedDeployment, setSelectedDeployment] = useState<number | null>(null);
  const [sharedOpened, { open: openShared, close: closeShared }] = useDisclosure(false);
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  const project = useQuery({
    queryKey: ['projects', slug],
    queryFn: async () => (await api.get<ApiEnvelope<Project>>(`/projects/${slug}`)).data.data,
    enabled: !!slug,
    // While a disk-usage recompute runs on the server, poll until it settles. This resumes
    // automatically on a fresh page load if a run is still in flight — no client-side handle
    // to lose — so a reload keeps tracking the same job instead of starting a new one.
    refetchInterval: (q) => (q.state.data?.disk_usage_status === 'running' ? 2500 : false),
  });

  const deployments = useQuery({
    queryKey: ['projects', slug, 'deployments'],
    queryFn: async () =>
      (await api.get<ApiEnvelope<Deployment[]>>(`/projects/${slug}/deployments`)).data.data,
    enabled: !!slug,
  });

  const releases = useQuery({
    queryKey: ['projects', slug, 'releases'],
    queryFn: async () => (await api.get<ApiEnvelope<Release[]>>(`/projects/${slug}/releases`)).data.data,
    enabled: !!slug,
  });

  const activate = useMutation({
    mutationFn: async (releaseId: number) => (await api.post(`/releases/${releaseId}/activate`)).data,
    onSuccess: () => {
      notifications.show({
        color: 'green',
        title: 'Release activated',
        message: 'The release is now active.',
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['projects', slug, 'releases'] });
      queryClient.invalidateQueries({ queryKey: ['projects', slug, 'deployments'] });
      queryClient.invalidateQueries({ queryKey: ['deployments'] });
    },
    onError: (error) => {
      const message = axios.isAxiosError(error)
        ? (error.response?.data as { error?: string } | undefined)?.error
        : undefined;
      notifications.show({
        color: 'red',
        title: 'Activation failed',
        message: message ?? 'Something went wrong.',
        icon: <IconX size={16} />,
      });
    },
  });

  const recomputeDisk = useMutation({
    mutationFn: async () =>
      (await api.post<ApiEnvelope<Project>>(`/projects/${slug}/disk-usage/recompute`)).data.data,
    onSuccess: (updated) => {
      // Seed the returned 'running' status so the poller (refetchInterval) starts immediately
      // and the button shows loading without waiting for the next scheduled refetch.
      queryClient.setQueryData(['projects', slug], updated);
    },
    onError: (error) => {
      const message = axios.isAxiosError(error)
        ? (error.response?.data as { error?: string } | undefined)?.error
        : undefined;
      notifications.show({
        color: 'red',
        title: 'Recalculation failed',
        message: message ?? 'Could not start disk usage recalculation.',
        icon: <IconX size={16} />,
      });
    },
  });

  const confirmActivate = (release: Release) => {
    modals.openConfirmModal({
      title: 'Activate release',
      children: (
        <Text size="sm">
          Activate version {release.version}? This will roll the live site to this release.
        </Text>
      ),
      labels: { confirm: 'Activate', cancel: 'Cancel' },
      confirmProps: { color: 'indigo' },
      onConfirm: () => activate.mutate(release.id),
    });
  };

  if (project.isLoading) {
    return (
      <Group justify="center" p="xl">
        <Loader />
      </Group>
    );
  }

  if (!project.data) {
    return (
      <Text c="dimmed" size="sm">
        Project not found.
      </Text>
    );
  }

  const p = project.data;
  const releaseList = releases.data ?? [];
  const activeRelease = releaseList.find((r) => r.state === 'active') ?? null;
  const lastDeployment = (deployments.data ?? [])[0] ?? null;
  const diskBusy = p.disk_usage_status === 'running' || recomputeDisk.isPending;

  return (
    <Stack gap="lg">
      <Breadcrumbs>
        <Anchor component={Link} to="/projects" size="sm">
          Projects
        </Anchor>
        <Text size="sm">{p.name}</Text>
      </Breadcrumbs>

      <Group justify="space-between" align="flex-start">
        <div>
          <Title order={2}>{p.name}</Title>
          <Text c="dimmed" size="sm">
            {p.base_path}
          </Text>
        </div>
        <Group gap="xs">
          {p.health_check_url && (
            <Anchor href={p.health_check_url} target="_blank" rel="noreferrer" size="sm">
              <Group gap={4} wrap="nowrap">
                <IconExternalLink size={14} />
                Health URL
              </Group>
            </Anchor>
          )}
          <Badge variant="light">{p.type}</Badge>
          <Badge color={p.status === 'active' ? 'green' : 'gray'} variant="light">
            {p.status}
          </Badge>
        </Group>
      </Group>

      <Card withBorder radius="md" p="md">
        <Group justify="space-between" mb="sm">
          <Text fw={600}>Overview</Text>
          <Button
            variant="light"
            size="xs"
            leftSection={<IconFolders size={14} />}
            onClick={openShared}
          >
            Shared paths{p.shared_paths?.length ? ` (${p.shared_paths.length})` : ''}
          </Button>
        </Group>
        <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="md" verticalSpacing="sm">
            <Info label="Live release">
              {activeRelease ? (
                <>
                  <Text size="sm" fw={500}>
                    {activeRelease.version}
                  </Text>
                  <Text size="xs" c="dimmed">
                    {formatRelativeTime(activeRelease.activated_at)}
                  </Text>
                </>
              ) : (
                <Text size="sm" c="dimmed">
                  None active
                </Text>
              )}
            </Info>
            <Info label="Last deploy">
              {lastDeployment ? (
                <>
                  <DeploymentStatusBadge status={lastDeployment.status} />
                  <Text size="xs" c="dimmed">
                    {formatRelativeTime(lastDeployment.created_at)}
                  </Text>
                </>
              ) : (
                <Text size="sm" c="dimmed">
                  —
                </Text>
              )}
            </Info>
            <Info label="Docroot subpath">
              <Text size="sm">{p.docroot_subpath || '—'}</Text>
            </Info>
            <Info label="Keep releases">
              <Text size="sm">{p.keep_releases}</Text>
            </Info>
            <Info label="Live size">
              <Text size="sm">
                {formatBytes(p.disk_usage)}{' '}
                <Text span size="xs" c="dimmed">
                  (current + shared)
                </Text>
              </Text>
            </Info>
            <Info label="Releases stored">
              <Text size="sm">{formatBytes(p.releases_disk_usage)}</Text>
            </Info>
            <Info label="Disk stats">
              <Button
                size="compact-xs"
                variant="light"
                leftSection={<IconRefresh size={14} />}
                loading={diskBusy}
                onClick={() => recomputeDisk.mutate()}
              >
                {diskBusy ? 'Recalculating…' : 'Recalculate'}
              </Button>
              <Text size="xs" c="dimmed">
                {p.disk_usage_calculated_at
                  ? `updated ${formatRelativeTime(p.disk_usage_calculated_at)}`
                  : 'not calculated yet'}
              </Text>
            </Info>
            <Info label="PHP binary">
              <Text size="sm">{p.php_binary || '—'}</Text>
            </Info>
            <Info label="Created">
              <Text size="sm">{formatDateTime(p.created_at)}</Text>
            </Info>
        </SimpleGrid>
      </Card>

      <Tabs defaultValue="deployments">
        <Tabs.List>
          <Tabs.Tab value="deployments">Deployments</Tabs.Tab>
          <Tabs.Tab value="releases">Releases</Tabs.Tab>
        </Tabs.List>

        <Tabs.Panel value="deployments" pt="md">
          <Paper withBorder radius="md">
            {deployments.isLoading ? (
              <Group justify="center" p="xl">
                <Loader />
              </Group>
            ) : (
              <Table.ScrollContainer minWidth={600}>
                <Table highlightOnHover verticalSpacing="sm">
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Release</Table.Th>
                      <Table.Th>Status</Table.Th>
                      <Table.Th>Trigger</Table.Th>
                      <Table.Th>When</Table.Th>
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {(deployments.data ?? []).map((d) => (
                      <Table.Tr
                        key={d.id}
                        onClick={() => setSelectedDeployment(d.id)}
                        style={{ cursor: 'pointer' }}
                      >
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
                        <Table.Td colSpan={4}>
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
        </Tabs.Panel>

        <Tabs.Panel value="releases" pt="md">
          <Paper withBorder radius="md">
            {releases.isLoading ? (
              <Group justify="center" p="xl">
                <Loader />
              </Group>
            ) : (
              <Table.ScrollContainer minWidth={600}>
                <Table highlightOnHover verticalSpacing="sm">
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Version</Table.Th>
                      <Table.Th>State</Table.Th>
                      <Table.Th>Size</Table.Th>
                      <Table.Th>Activated</Table.Th>
                      <Table.Th />
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {releaseList.map((r) => (
                      <Table.Tr
                        key={r.id}
                        bg={r.state === 'active' ? 'var(--mantine-color-green-light)' : undefined}
                      >
                        <Table.Td>
                          <Group gap="xs" wrap="nowrap">
                            <Text size="sm" fw={r.state === 'active' ? 600 : 400}>
                              {r.version}
                            </Text>
                            {r.state === 'active' && (
                              <Badge size="xs" color="green" variant="filled">
                                live
                              </Badge>
                            )}
                          </Group>
                        </Table.Td>
                        <Table.Td>
                          <ReleaseStateBadge state={r.state} />
                        </Table.Td>
                        <Table.Td>{formatBytes(r.artifact?.size)}</Table.Td>
                        <Table.Td>{formatDateTime(r.activated_at)}</Table.Td>
                        <Table.Td>
                          {r.state === 'active' ? (
                            <Text size="xs" c="dimmed">
                              current
                            </Text>
                          ) : (
                            isAdmin && (
                              <Button size="xs" variant="light" onClick={() => confirmActivate(r)}>
                                Activate
                              </Button>
                            )
                          )}
                        </Table.Td>
                      </Table.Tr>
                    ))}
                    {releases.data?.length === 0 && (
                      <Table.Tr>
                        <Table.Td colSpan={5}>
                          <Text c="dimmed" size="sm">
                            No releases yet.
                          </Text>
                        </Table.Td>
                      </Table.Tr>
                    )}
                  </Table.Tbody>
                </Table>
              </Table.ScrollContainer>
            )}
          </Paper>
        </Tabs.Panel>
      </Tabs>

      <DeploymentDrawer deploymentId={selectedDeployment} onClose={() => setSelectedDeployment(null)} />

      <Drawer opened={sharedOpened} onClose={closeShared} position="right" size="md" title="Shared paths">
        {p.shared_paths && p.shared_paths.length > 0 ? (
          <Stack gap="xs">
            {p.shared_paths.map((sp, index) => (
              <Group key={`${sp.path}-${index}`} justify="space-between" wrap="nowrap">
                <Code>{sp.path}</Code>
                <Badge color={sp.type === 'dir' ? 'blue' : 'grape'} variant="light">
                  {sp.type}
                </Badge>
              </Group>
            ))}
          </Stack>
        ) : (
          <Text c="dimmed" size="sm">
            No shared paths configured.
          </Text>
        )}
      </Drawer>
    </Stack>
  );
}

/** A labelled overview cell: uppercase label on top, value (and any relative-time hint) stacked below. */
function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Stack gap={2} align="flex-start">
      <Text size="xs" c="dimmed" tt="uppercase" fw={700}>
        {label}
      </Text>
      {children}
    </Stack>
  );
}
