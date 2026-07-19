import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  Anchor,
  Badge,
  Breadcrumbs,
  Button,
  Code,
  Group,
  Loader,
  Paper,
  Stack,
  Table,
  Tabs,
  Text,
  Title,
} from '@mantine/core';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import { IconCheck, IconX } from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { DeploymentDrawer } from '@/components/DeploymentDrawer';
import { DeploymentStatusBadge, ReleaseStateBadge } from '@/components/StatusBadge';
import { formatDateTime, formatRelativeTime } from '@/lib/format';
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

  return (
    <Stack gap="lg">
      <Breadcrumbs>
        <Anchor component={Link} to="/projects" size="sm">
          Projects
        </Anchor>
        <Text size="sm">{p.name}</Text>
      </Breadcrumbs>

      <Group justify="space-between">
        <div>
          <Title order={2}>{p.name}</Title>
          <Text c="dimmed" size="sm">
            {p.base_path}
          </Text>
        </div>
        <Group gap="xs">
          <Badge variant="light">{p.type}</Badge>
          <Badge color={p.status === 'active' ? 'green' : 'gray'} variant="light">
            {p.status}
          </Badge>
        </Group>
      </Group>

      <Paper withBorder radius="md" p="md">
        <Title order={4} mb="xs">
          Shared paths
        </Title>
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
      </Paper>

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
                      <Table.Th>Activated</Table.Th>
                      <Table.Th />
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {(releases.data ?? []).map((r) => (
                      <Table.Tr key={r.id}>
                        <Table.Td>{r.version}</Table.Td>
                        <Table.Td>
                          <ReleaseStateBadge state={r.state} />
                        </Table.Td>
                        <Table.Td>{formatDateTime(r.activated_at)}</Table.Td>
                        <Table.Td>
                          {r.state !== 'active' && isAdmin && (
                            <Button size="xs" variant="light" onClick={() => confirmActivate(r)}>
                              Activate
                            </Button>
                          )}
                        </Table.Td>
                      </Table.Tr>
                    ))}
                    {releases.data?.length === 0 && (
                      <Table.Tr>
                        <Table.Td colSpan={4}>
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
