import { useState } from 'react';
import { Button, Group, Loader, Paper, Select, Stack, Table, Text, Title } from '@mantine/core';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import { IconCheck, IconX } from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { ReleaseStateBadge } from '@/components/StatusBadge';
import { formatDateTime } from '@/lib/format';
import type { ApiEnvelope, Project, Release } from '@/lib/types';

export function ReleasesPage() {
  const [selectedSlug, setSelectedSlug] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  const projects = useQuery({
    queryKey: ['projects'],
    queryFn: async () => (await api.get<ApiEnvelope<Project[]>>('/projects')).data.data,
  });

  const activeSlug = selectedSlug ?? projects.data?.[0]?.slug ?? null;

  const releases = useQuery({
    queryKey: ['projects', activeSlug, 'releases'],
    queryFn: async () =>
      (await api.get<ApiEnvelope<Release[]>>(`/projects/${activeSlug}/releases`)).data.data,
    enabled: !!activeSlug,
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
      queryClient.invalidateQueries({ queryKey: ['projects', activeSlug, 'releases'] });
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

  return (
    <Stack gap="lg">
      <div>
        <Title order={2}>Releases</Title>
        <Text c="dimmed" size="sm">
          Browse and activate releases per project.
        </Text>
      </div>

      <Select
        label="Project"
        placeholder="Select a project"
        data={(projects.data ?? []).map((p) => ({ value: p.slug, label: p.name }))}
        value={activeSlug}
        onChange={setSelectedSlug}
        searchable
        w={320}
      />

      <Paper withBorder radius="md">
        {!activeSlug ? (
          <Text c="dimmed" size="sm" p="lg">
            No projects yet — create one first.
          </Text>
        ) : releases.isLoading ? (
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
    </Stack>
  );
}
