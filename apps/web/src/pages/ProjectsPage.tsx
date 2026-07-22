import {
  ActionIcon,
  Badge,
  Button,
  Checkbox,
  Code,
  Group,
  Modal,
  NumberInput,
  Pagination,
  Paper,
  SegmentedControl,
  Select,
  Skeleton,
  Stack,
  Table,
  Text,
  TextInput,
  Title,
} from '@mantine/core';
import { useDebouncedValue, useDisclosure } from '@mantine/hooks';
import { useForm } from '@mantine/form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { IconPlus, IconSearch, IconTrash } from '@tabler/icons-react';
import { useEffect, useMemo, useState } from 'react';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { PanelBody } from '@/components/PanelBody';
import { EmptyState } from '@/components/EmptyState';
import { notifySuccess, notifyError, applyFormErrors } from '@/lib/feedback';
import { DeploymentStatusBadge, ProjectHealthBadge } from '@/components/StatusBadge';
import { ReleaseVersion } from '@/components/ReleaseVersion';
import { formatBytes, formatRelativeTime } from '@/lib/format';
import type { ApiEnvelope, Capabilities, Deployment, Project, SharedPath } from '@/lib/types';

const PER_PAGE = 20;

interface ProjectsListMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

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

/** Per-trigger auto-rollback options (docs/SPEC.md §21.2); mirrors App\Enums\RollbackTrigger. */
const ROLLBACK_TRIGGER_OPTIONS = [
  { value: 'health_check', label: 'Failed health check' },
  { value: 'post_activate_hook', label: 'Failed post-activate hook' },
];

const STATUS_FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'disabled', label: 'Disabled' },
  { value: 'deleting', label: 'Deleting' },
];

interface ProjectFormValues {
  name: string;
  base_dir: string;
  base_subpath: string;
  type: string;
  docroot_subpath: string;
  keep_releases: number;
  auto_rollback_on: string[];
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
  auto_rollback_on: [],
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
  const [page, setPage] = useState(1);
  const [debouncedSearch] = useDebouncedValue(search, 300);
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, statusFilter]);

  const projects = useQuery({
    queryKey: ['projects', 'list', { page, search: debouncedSearch, status: statusFilter }],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: PER_PAGE };
      if (debouncedSearch) params.search = debouncedSearch;
      if (statusFilter !== 'all') params.status = statusFilter;
      return (
        await api.get<{ data: Project[]; meta: ProjectsListMeta }>('/projects', { params })
      ).data;
    },
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

  const rows = projects.data?.data ?? [];
  const meta = projects.data?.meta;
  const isFiltering = search.trim().length > 0 || statusFilter !== 'all';

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
        auto_rollback_on: values.auto_rollback_on,
        health_check_url: values.health_check_url || undefined,
        shared_paths: values.shared_paths
          .map((entry) => ({ path: entry.path.trim(), type: entry.type }))
          .filter((entry) => entry.path.length > 0),
      };
      return (await api.post<ApiEnvelope<Project>>('/projects', payload)).data.data;
    },
    onSuccess: (project) => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      form.reset();
      close();
      notifySuccess('Project created', `${project.name} is ready to receive deployments.`);
    },
    onError: (error) => {
      // The server validates the composed `base_path`; surface that on the subpath input.
      if (applyFormErrors(error, form, (field) => (field === 'base_path' ? 'base_subpath' : field)))
        return;
      notifyError('Failed to create project', error);
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
  const showFilters = isFiltering || rows.length > 0;

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

      {showFilters && (
        <Group justify="space-between" wrap="wrap" gap="sm">
          <TextInput
            placeholder="Search name, slug or path"
            leftSection={<IconSearch size={16} />}
            value={search}
            onChange={(e) => setSearch(e.currentTarget.value)}
            w={{ base: '100%', sm: 320 }}
          />
          <SegmentedControl data={STATUS_FILTERS} value={statusFilter} onChange={setStatusFilter} size="sm" />
        </Group>
      )}

      <Paper withBorder radius="md">
        <PanelBody
          query={projects}
          errorTitle="Couldn't load projects"
          loader={
            <Table.ScrollContainer minWidth={900}>
              <Table verticalSpacing="sm">
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
                  {Array.from({ length: 6 }).map((_, i) => (
                    <Table.Tr key={i}>
                      {Array.from({ length: 7 }).map((__, j) => (
                        <Table.Td key={j}>
                          <Skeleton height={16} radius="sm" />
                        </Table.Td>
                      ))}
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </Table.ScrollContainer>
          }
        >
          {rows.length === 0 && !isFiltering ? (
            <EmptyState
              title="No projects yet"
              description="Create a project to point cPorter at a cPanel target, then deploy to it from CI."
              action={
                isAdmin ? (
                  <Button leftSection={<IconPlus size={16} />} onClick={open}>
                    New project
                  </Button>
                ) : undefined
              }
            />
          ) : (
            <Table.ScrollContainer minWidth={900}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Name</Table.Th>
                  <Table.Th>Type</Table.Th>
                  <Table.Th>Live size</Table.Th>
                  <Table.Th>Releases stored</Table.Th>
                  <Table.Th>Last deploy</Table.Th>
                  <Table.Th>Health</Table.Th>
                  <Table.Th>Status</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {rows.map((p) => {
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
                        <Text size="xs" c="dimmed">
                          {p.base_path}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        <Badge variant="light">{p.type}</Badge>
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
                              {last.release?.version ? (
                                <>
                                  <ReleaseVersion version={last.release.version} /> ·{' '}
                                </>
                              ) : (
                                ''
                              )}
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
                        <ProjectHealthBadge status={p.health_status} />
                      </Table.Td>
                      <Table.Td>
                        <Badge
                          color={
                            p.status === 'active' ? 'green' : p.status === 'deleting' ? 'orange' : 'gray'
                          }
                          variant="light"
                        >
                          {p.status}
                        </Badge>
                      </Table.Td>
                    </Table.Tr>
                  );
                })}
                {rows.length === 0 && (
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
        </PanelBody>
      </Paper>

      {meta && meta.last_page > 1 && (
        <Group justify="center">
          <Pagination total={meta.last_page} value={page} onChange={setPage} />
        </Group>
      )}

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
              description="Polled after each activation and continuously monitored on a schedule. Leave empty to skip."
              {...form.getInputProps('health_check_url')}
            />
            <Checkbox.Group
              label="Auto-rollback on"
              description="Which post-activation failures roll the release back to the previous one. None selected = disabled; a failure then just marks the deploy failed and the project unhealthy."
              {...form.getInputProps('auto_rollback_on')}
            >
              <Stack gap="xs" mt="xs">
                {ROLLBACK_TRIGGER_OPTIONS.map((t) => (
                  <Checkbox key={t.value} value={t.value} label={t.label} />
                ))}
              </Stack>
            </Checkbox.Group>
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
