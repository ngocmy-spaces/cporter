import { useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  Anchor,
  Badge,
  Breadcrumbs,
  Button,
  Card,
  Code,
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
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import { IconCheck, IconExternalLink, IconX } from '@tabler/icons-react';
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
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  const project = useQuery({
    queryKey: ['projects', slug],
    queryFn: async () => (await api.get<ApiEnvelope<Project>>(`/projects/${slug}`)).data.data,
    enabled: !!slug,
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

      <SimpleGrid cols={{ base: 1, md: 2 }}>
        <Card withBorder radius="md" p="md">
          <Text fw={600} mb="sm">
            Overview
          </Text>
          <Stack gap="xs">
            <Info label="Live release">
              {activeRelease ? (
                <Group gap="xs" wrap="nowrap">
                  <Text size="sm" fw={500}>
                    {activeRelease.version}
                  </Text>
                  <Text size="xs" c="dimmed">
                    {formatRelativeTime(activeRelease.activated_at)}
                  </Text>
                </Group>
              ) : (
                <Text size="sm" c="dimmed">
                  None active
                </Text>
              )}
            </Info>
            <Info label="Last deploy">
              {lastDeployment ? (
                <Group gap="xs" wrap="nowrap">
                  <DeploymentStatusBadge status={lastDeployment.status} />
                  <Text size="xs" c="dimmed">
                    {formatRelativeTime(lastDeployment.created_at)}
                  </Text>
                </Group>
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
            <Info label="Disk usage">
              <Text size="sm">{formatBytes(p.disk_usage)}</Text>
            </Info>
            <Info label="PHP binary">
              <Text size="sm">{p.php_binary || '—'}</Text>
            </Info>
            <Info label="Created">
              <Text size="sm">{formatDateTime(p.created_at)}</Text>
            </Info>
          </Stack>
        </Card>

        <Card withBorder radius="md" p="md">
          <Text fw={600} mb="sm">
            Shared paths
          </Text>
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
        </Card>
      </SimpleGrid>

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
    </Stack>
  );
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Group justify="space-between" gap="xs" wrap="nowrap">
      <Text size="xs" c="dimmed" tt="uppercase" fw={700}>
        {label}
      </Text>
      {children}
    </Group>
  );
}
