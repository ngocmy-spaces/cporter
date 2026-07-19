import {
  ActionIcon,
  Badge,
  Button,
  Code,
  Group,
  Loader,
  Modal,
  NumberInput,
  Paper,
  SegmentedControl,
  Select,
  Stack,
  Table,
  Text,
  TextInput,
  Title,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useForm } from '@mantine/form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { IconPlus, IconSearch, IconTrash } from '@tabler/icons-react';
import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { DeploymentStatusBadge } from '@/components/StatusBadge';
import { formatBytes, formatRelativeTime } from '@/lib/format';
import type { ApiEnvelope, Capabilities, Deployment, Project, SharedPath } from '@/lib/types';

const PROJECT_TYPES = [
  { value: 'static', label: 'Static' },
  { value: 'laravel', label: 'Laravel' },
  { value: 'php', label: 'PHP' },
  { value: 'node', label: 'Node' },
  { value: 'wordpress', label: 'WordPress' },
];

const SHARED_PATH_TYPES = [
  { value: 'dir', label: 'Directory' },
  { value: 'file', label: 'File' },
];

const STATUS_FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'disabled', label: 'Disabled' },
];

interface ProjectFormValues {
  name: string;
  base_dir: string;
  base_subpath: string;
  type: string;
  docroot_subpath: string;
  keep_releases: number;
  health_check_url: string;
  shared_paths: SharedPath[];
}

const INITIAL_VALUES: ProjectFormValues = {
  name: '',
  base_dir: '',
  base_subpath: '',
  type: 'static',
  docroot_subpath: '',
  keep_releases: 5,
  health_check_url: '',
  shared_paths: [],
};

/** Strip trailing slashes from the base directory prefix. */
const trimBaseDir = (dir: string) => dir.replace(/\/+$/, '');

/** Join the fixed base directory with the user-typed subpath into an absolute path. */
function composeBasePath(dir: string, subpath: string): string {
  const sub = subpath.trim().replace(/^\/+/, '');
  const base = trimBaseDir(dir);
  return base ? `${base}/${sub}` : sub;
}

export function ProjectsPage() {
  const [opened, { open, close }] = useDisclosure(false);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  const projects = useQuery({
    queryKey: ['projects'],
    queryFn: async () => (await api.get<ApiEnvelope<Project[]>>('/projects')).data.data,
  });

  // Recent cross-project feed, joined per project to surface each project's last deploy
  // without an N+1 fan-out. The feed is latest-first, so the first hit per project is newest.
  const deployments = useQuery({
    queryKey: ['deployments'],
    queryFn: async () => (await api.get<ApiEnvelope<Deployment[]>>('/deployments')).data.data,
    refetchInterval: 15_000,
  });

  const latestByProject = useMemo(() => {
    const map = new Map<number, Deployment>();
    for (const d of deployments.data ?? []) {
      if (!map.has(d.project_id)) map.set(d.project_id, d);
    }
    return map;
  }, [deployments.data]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return (projects.data ?? []).filter((p) => {
      if (statusFilter !== 'all' && p.status !== statusFilter) return false;
      if (!q) return true;
      return (
        p.name.toLowerCase().includes(q) ||
        p.slug.toLowerCase().includes(q) ||
        p.base_path.toLowerCase().includes(q)
      );
    });
  }, [projects.data, search, statusFilter]);

  // Allowed base paths come from the server's capability probe (CPORTER_ALLOWED_BASE_PATHS)
  // so the form can pin the prefix instead of asking the user to retype the jail root.
  // NOTE: keep this query's shape identical to SettingsPage — both share the
  // ['system','capabilities'] cache key, so the resolved envelope must match or the
  // other page reads an unexpected shape and crashes on `.map`.
  const capabilities = useQuery({
    queryKey: ['system', 'capabilities'],
    queryFn: async () =>
      (await api.get<{ data: Capabilities; probed_at: string }>('/system/capabilities')).data,
  });
  const allowedBasePaths =
    capabilities.data?.data?.allowed_base_paths?.map((entry) => entry.path) ?? [];

  const form = useForm<ProjectFormValues>({
    initialValues: INITIAL_VALUES,
    validate: {
      name: (value) => (value.trim().length > 0 ? null : 'Name is required'),
      base_subpath: (value) => (value.trim().length > 0 ? null : 'A project folder is required'),
    },
  });

  // Pin the base directory to the first allowed path once capabilities load (or on reopen).
  useEffect(() => {
    if (opened && !form.values.base_dir && allowedBasePaths.length > 0) {
      form.setFieldValue('base_dir', allowedBasePaths[0]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [opened, allowedBasePaths.length]);

  const createProject = useMutation({
    mutationFn: async (values: ProjectFormValues) => {
      const payload = {
        name: values.name,
        base_path: composeBasePath(values.base_dir, values.base_subpath),
        type: values.type,
        docroot_subpath: values.docroot_subpath || undefined,
        keep_releases: values.keep_releases,
        health_check_url: values.health_check_url || undefined,
        shared_paths: values.shared_paths
          .map((entry) => ({ path: entry.path.trim(), type: entry.type }))
          .filter((entry) => entry.path.length > 0),
      };
      return (await api.post<ApiEnvelope<Project>>('/projects', payload)).data.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      form.reset();
      close();
    },
    onError: (error) => {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const errors = error.response.data?.errors as Record<string, string[]> | undefined;
        if (errors) {
          form.setErrors(
            Object.fromEntries(
              // The server validates the composed `base_path`; surface that on the subpath input.
              Object.entries(errors).map(([field, messages]) => [
                field === 'base_path' ? 'base_subpath' : field,
                messages[0],
              ]),
            ),
          );
        }
      }
    },
  });

  const closeModal = () => {
    close();
    form.reset();
  };

  const basePrefix = form.values.base_dir ? `${trimBaseDir(form.values.base_dir)}/` : '';
  const composedBasePath = composeBasePath(form.values.base_dir, form.values.base_subpath);
  const noBasePaths = !capabilities.isLoading && allowedBasePaths.length === 0;
  const prefixWidth = basePrefix ? Math.min(basePrefix.length * 8 + 16, 240) : undefined;
  const hasProjects = (projects.data?.length ?? 0) > 0;

  return (
    <Stack gap="lg">
      <Group justify="space-between">
        <div>
          <Title order={2}>Projects</Title>
          <Text c="dimmed" size="sm">
            Managed cPanel targets that cPorter deploys to.
          </Text>
        </div>
        {isAdmin && (
          <Button leftSection={<IconPlus size={16} />} onClick={open}>
            New project
          </Button>
        )}
      </Group>

      {hasProjects && (
        <Group justify="space-between" wrap="wrap" gap="sm">
          <TextInput
            placeholder="Search name, slug or path"
            leftSection={<IconSearch size={16} />}
            value={search}
            onChange={(e) => setSearch(e.currentTarget.value)}
            w={320}
          />
          <SegmentedControl data={STATUS_FILTERS} value={statusFilter} onChange={setStatusFilter} size="sm" />
        </Group>
      )}

      <Paper withBorder radius="md">
        {projects.isLoading ? (
          <Group justify="center" p="xl">
            <Loader />
          </Group>
        ) : !hasProjects ? (
          <Stack align="center" gap="xs" p="xl">
            <Text fw={500}>No projects yet</Text>
            <Text c="dimmed" size="sm" ta="center">
              Create a project to point cPorter at a cPanel target, then deploy to it from CI.
            </Text>
            {isAdmin && (
              <Button mt="xs" leftSection={<IconPlus size={16} />} onClick={open}>
                New project
              </Button>
            )}
          </Stack>
        ) : (
          <Table.ScrollContainer minWidth={900}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Name</Table.Th>
                  <Table.Th>Type</Table.Th>
                  <Table.Th>Base path</Table.Th>
                  <Table.Th>Live size</Table.Th>
                  <Table.Th>Releases</Table.Th>
                  <Table.Th>Last deploy</Table.Th>
                  <Table.Th>Status</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {filtered.map((p) => {
                  const last = latestByProject.get(p.id);
                  return (
                    <Table.Tr key={p.id}>
                      <Table.Td>
                        <Text component={Link} to={`/projects/${p.slug}`} fw={500} c="indigo">
                          {p.name}
                        </Text>
                        <Text size="xs" c="dimmed">
                          {p.slug}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        <Badge variant="light">{p.type}</Badge>
                      </Table.Td>
                      <Table.Td>
                        <Text size="sm" c="dimmed">
                          {p.base_path}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        <Text size="sm">{formatBytes(p.disk_usage)}</Text>
                      </Table.Td>
                      <Table.Td>
                        <Text size="sm">{formatBytes(p.releases_disk_usage)}</Text>
                      </Table.Td>
                      <Table.Td>
                        {last ? (
                          <Stack gap={2}>
                            <DeploymentStatusBadge status={last.status} />
                            <Text size="xs" c="dimmed">
                              {last.release?.version ? `${last.release.version} · ` : ''}
                              {formatRelativeTime(last.created_at)}
                            </Text>
                          </Stack>
                        ) : (
                          <Text size="sm" c="dimmed">
                            —
                          </Text>
                        )}
                      </Table.Td>
                      <Table.Td>
                        <Badge color={p.status === 'active' ? 'green' : 'gray'} variant="light">
                          {p.status}
                        </Badge>
                      </Table.Td>
                    </Table.Tr>
                  );
                })}
                {filtered.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={7}>
                      <Text c="dimmed" size="sm" ta="center" py="sm">
                        No projects match the current filters.
                      </Text>
                    </Table.Td>
                  </Table.Tr>
                )}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>

      <Modal opened={opened} onClose={closeModal} title="New project" size="lg">
        <form onSubmit={form.onSubmit((values) => createProject.mutate(values))}>
          <Stack gap="sm">
            <TextInput label="Name" placeholder="My App" required {...form.getInputProps('name')} />
            {noBasePaths ? (
              <TextInput
                label="Base path"
                placeholder="/home/user/my-app"
                description="No allowed base paths are configured (CPORTER_ALLOWED_BASE_PATHS is empty) — enter a full absolute path."
                required
                {...form.getInputProps('base_subpath')}
              />
            ) : (
              <Stack gap="xs">
                {allowedBasePaths.length > 1 && (
                  <Select
                    label="Base directory"
                    description="Deploy jail root — the project folder is created inside it."
                    data={allowedBasePaths}
                    allowDeselect={false}
                    {...form.getInputProps('base_dir')}
                  />
                )}
                <TextInput
                  label="Project folder"
                  placeholder="my-app"
                  required
                  description={
                    form.values.base_subpath.trim() ? (
                      <>
                        Full path: <Code>{composedBasePath}</Code>
                      </>
                    ) : (
                      'Only the folder inside the base directory — the prefix above is fixed for you.'
                    )
                  }
                  leftSection={
                    <Text ff="monospace" size="sm" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
                      {basePrefix}
                    </Text>
                  }
                  leftSectionPointerEvents="none"
                  leftSectionWidth={prefixWidth}
                  {...form.getInputProps('base_subpath')}
                />
              </Stack>
            )}
            <Select
              label="Type"
              description="Static, PHP & WordPress deploy fully in web PHP (no shell). Laravel & Node also run shell steps (migrate, build, restart) via the cron worker."
              data={PROJECT_TYPES}
              required
              {...form.getInputProps('type')}
            />
            <TextInput
              label="Docroot subpath"
              placeholder="public"
              description={
                <>
                  Subfolder inside the release that becomes the web root. Leave empty to serve the
                  release root; Laravel typically uses <Code>public</Code>.
                </>
              }
              {...form.getInputProps('docroot_subpath')}
            />
            <NumberInput
              label="Keep releases"
              description="How many past releases to retain for rollback before older ones are pruned."
              min={1}
              max={50}
              {...form.getInputProps('keep_releases')}
            />
            <TextInput
              label="Health check URL"
              placeholder="https://example.com/health"
              description="Polled after each activation; if it fails, cPorter auto-rolls back to the previous release. Leave empty to skip."
              {...form.getInputProps('health_check_url')}
            />
            <Stack gap="xs">
              <Text size="sm" fw={500}>
                Shared paths
              </Text>
              <Text size="xs" c="dimmed">
                Persisted across releases via symlink. <b>Directory</b> is created automatically if
                missing (e.g. <code>storage</code>); <b>File</b> must already exist under{' '}
                <code>shared/</code> on the server (e.g. a secret <code>.env</code>).
              </Text>
              {form.values.shared_paths.map((_, index) => (
                <Group key={index} gap="xs" align="flex-start">
                  <TextInput
                    placeholder="storage or .env"
                    style={{ flex: 1 }}
                    {...form.getInputProps(`shared_paths.${index}.path`)}
                  />
                  <Select
                    data={SHARED_PATH_TYPES}
                    w={130}
                    {...form.getInputProps(`shared_paths.${index}.type`)}
                  />
                  <ActionIcon
                    color="red"
                    variant="subtle"
                    onClick={() => form.removeListItem('shared_paths', index)}
                    aria-label="Remove shared path"
                  >
                    <IconTrash size={16} />
                  </ActionIcon>
                </Group>
              ))}
              <Button
                variant="subtle"
                size="xs"
                leftSection={<IconPlus size={14} />}
                onClick={() => form.insertListItem('shared_paths', { path: '', type: 'dir' })}
              >
                Add shared path
              </Button>
            </Stack>
            <Group justify="flex-end" mt="md">
              <Button variant="default" onClick={closeModal}>
                Cancel
              </Button>
              <Button type="submit" loading={createProject.isPending}>
                Create
              </Button>
            </Group>
          </Stack>
        </form>
      </Modal>
    </Stack>
  );
}
