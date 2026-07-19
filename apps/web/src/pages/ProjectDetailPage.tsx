import { useEffect, useState, type ReactNode } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import {
  ActionIcon,
  Alert,
  Anchor,
  Badge,
  Breadcrumbs,
  Button,
  Card,
  Code,
  Drawer,
  Group,
  List,
  Loader,
  Modal,
  NumberInput,
  Paper,
  Radio,
  Select,
  SimpleGrid,
  Stack,
  Table,
  Tabs,
  Text,
  TextInput,
  ThemeIcon,
  Title,
  Tooltip,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useForm } from '@mantine/form';
import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import {
  IconAlertTriangle,
  IconChecklist,
  IconCheck,
  IconClock,
  IconCopy,
  IconExternalLink,
  IconFolders,
  IconInfoCircle,
  IconPencil,
  IconPlus,
  IconRefresh,
  IconTrash,
  IconX,
} from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { DeploymentDrawer } from '@/components/DeploymentDrawer';
import { DeploymentStatusBadge, ReleaseStateBadge } from '@/components/StatusBadge';
import { formatBytes, formatDateTime, formatRelativeTime } from '@/lib/format';
import type {
  ApiEnvelope,
  AuditLog,
  Capabilities,
  Deployment,
  PreflightCheck,
  PreflightReport,
  Project,
  ProjectStatus,
  Release,
  SharedPath,
} from '@/lib/types';

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

/** The two hook stages the deploy engine runs, in execution order. */
const HOOK_STAGES = [
  {
    key: 'pre_activate' as const,
    label: 'Pre-activate hooks',
    helper:
      'Run before the new release goes live (e.g. artisan migrate --force). If one fails, the deploy fails and nothing is swapped.',
  },
  {
    key: 'post_activate' as const,
    label: 'Post-activate hooks',
    helper:
      'Run after the release is live (e.g. artisan queue:restart). If one fails, cPorter auto-rolls back to the previous release.',
  },
];

/** Color + icon shown for each preflight check status. */
const PREFLIGHT_STATUS_META: Record<PreflightCheck['status'], { color: string; icon: ReactNode }> = {
  ok: { color: 'green', icon: <IconCheck size={14} /> },
  created: { color: 'teal', icon: <IconCheck size={14} /> },
  pending: { color: 'gray', icon: <IconClock size={14} /> },
  warning: { color: 'yellow', icon: <IconAlertTriangle size={14} /> },
  manual: { color: 'blue', icon: <IconInfoCircle size={14} /> },
  error: { color: 'red', icon: <IconX size={14} /> },
};

/** Human label + badge color for known audit-log actions; unknown actions fall back to the raw key. */
const ACTIVITY_ACTION_META: Record<string, { label: string; color: string }> = {
  'project.created': { label: 'Project created', color: 'green' },
  'project.updated': { label: 'Config updated', color: 'blue' },
  'project.preflight': { label: 'Host preflight', color: 'indigo' },
  'project.deleting': { label: 'Deleting…', color: 'orange' },
  'project.deleted': { label: 'Deleted', color: 'red' },
  'project.delete_failed': { label: 'Delete failed', color: 'red' },
};

function getActivityMeta(action: string) {
  return ACTIVITY_ACTION_META[action] ?? { label: action, color: 'gray' };
}

/** Short, human summary of an audit log's `meta` payload — empty string if there's nothing useful to show. */
function summarizeActivityMeta(action: string, meta: Record<string, unknown> | null): string {
  if (!meta) return '';
  if (action === 'project.updated' && Array.isArray(meta.changed)) {
    return `changed: ${(meta.changed as string[]).join(', ')}`;
  }
  if (action === 'project.preflight' && typeof meta.ok === 'boolean') {
    return meta.ok ? 'ready' : 'needs attention';
  }
  return '';
}

/**
 * Shared loading/error/content switch for a Tabs.Panel backed by a single query. Renders a
 * Loader while fetching, a retryable Alert on failure, and the panel's own table (with its
 * empty-state row) once the query succeeds — keeping that logic out of each panel.
 */
function PanelBody({
  query,
  errorTitle,
  children,
}: {
  query: UseQueryResult<unknown, unknown>;
  errorTitle: string;
  children: ReactNode;
}) {
  if (query.isLoading) {
    return (
      <Group justify="center" p="xl">
        <Loader />
      </Group>
    );
  }

  if (query.isError) {
    const message = axios.isAxiosError(query.error)
      ? (query.error.response?.data as { error?: string } | undefined)?.error
      : undefined;
    return (
      <Alert color="red" variant="light" icon={<IconAlertTriangle size={16} />} title={errorTitle} m="md">
        <Stack gap="xs" align="flex-start">
          <Text size="sm">{message ?? 'Something went wrong.'}</Text>
          <Button variant="light" size="xs" onClick={() => query.refetch()}>
            Retry
          </Button>
        </Stack>
      </Alert>
    );
  }

  return <>{children}</>;
}

/** Strip trailing slashes from the base directory prefix. */
const trimBaseDir = (dir: string) => dir.replace(/\/+$/, '');

/** Join the fixed base directory with the user-typed subpath into an absolute path. */
function composeBasePath(dir: string, subpath: string): string {
  const sub = subpath.trim().replace(/^\/+/, '');
  const base = trimBaseDir(dir);
  return base ? `${base}/${sub}` : sub;
}

interface CloneFormValues {
  name: string;
  base_dir: string;
  base_subpath: string;
}

const CLONE_INITIAL_VALUES: CloneFormValues = {
  name: '',
  base_dir: '',
  base_subpath: '',
};

interface ProjectEditFormValues {
  name: string;
  type: string;
  docroot_subpath: string;
  php_binary: string;
  keep_releases: number;
  health_check_url: string;
  shared_paths: SharedPath[];
  hooks: { pre_activate: string[]; post_activate: string[] };
}

const EDIT_INITIAL_VALUES: ProjectEditFormValues = {
  name: '',
  type: 'static',
  docroot_subpath: '',
  php_binary: '',
  keep_releases: 5,
  health_check_url: '',
  shared_paths: [],
  hooks: { pre_activate: [], post_activate: [] },
};

export function ProjectDetailPage() {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const [selectedDeployment, setSelectedDeployment] = useState<number | null>(null);
  const [sharedOpened, { open: openShared, close: closeShared }] = useDisclosure(false);
  const [editOpened, { open: openEditDisclosure, close: closeEditDisclosure }] = useDisclosure(false);
  const [cloneOpened, { open: openCloneDisclosure, close: closeCloneDisclosure }] = useDisclosure(false);
  const [deleteOpened, { open: openDeleteDisclosure, close: closeDeleteDisclosure }] = useDisclosure(false);
  const [purge, setPurge] = useState<'none' | 'releases' | 'all'>('none');
  const [preflightOpened, { open: openPreflightModal, close: closePreflightModal }] = useDisclosure(false);
  const [preflightReport, setPreflightReport] = useState<PreflightReport | null>(null);
  const [activeTab, setActiveTab] = useState<string | null>('deployments');
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  const editForm = useForm<ProjectEditFormValues>({
    initialValues: EDIT_INITIAL_VALUES,
    validate: {
      name: (value) => (value.trim().length > 0 ? null : 'Name is required'),
    },
  });

  const cloneForm = useForm<CloneFormValues>({
    initialValues: CLONE_INITIAL_VALUES,
    validate: {
      name: (value) => (value.trim().length > 0 ? null : 'Name is required'),
      base_subpath: (value) => (value.trim().length > 0 ? null : 'A project folder is required'),
    },
  });

  // Allowed base paths come from the server's capability probe (CPORTER_ALLOWED_BASE_PATHS) so the
  // clone form can pin the prefix instead of asking the user to retype the jail root.
  // NOTE: keep this query's shape identical to ProjectsPage/SettingsPage — all three share the
  // ['system','capabilities'] cache key, so the resolved envelope must match or another page reads
  // an unexpected shape and crashes on `.map`.
  const capabilities = useQuery({
    queryKey: ['system', 'capabilities'],
    queryFn: async () =>
      (await api.get<{ data: Capabilities; probed_at: string }>('/system/capabilities')).data,
  });
  const allowedBasePaths = capabilities.data?.data?.allowed_base_paths?.map((entry) => entry.path) ?? [];

  // Pin the base directory to the first allowed path once capabilities load (or on reopen).
  useEffect(() => {
    if (cloneOpened && !cloneForm.values.base_dir && allowedBasePaths.length > 0) {
      cloneForm.setFieldValue('base_dir', allowedBasePaths[0]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cloneOpened, allowedBasePaths.length]);

  const project = useQuery({
    queryKey: ['projects', slug],
    queryFn: async () => (await api.get<ApiEnvelope<Project>>(`/projects/${slug}`)).data.data,
    enabled: !!slug,
    // While a disk-usage recompute runs on the server, poll until it settles. This resumes
    // automatically on a fresh page load if a run is still in flight — no client-side handle
    // to lose — so a reload keeps tracking the same job instead of starting a new one.
    refetchInterval: (q) => (q.state.data?.disk_usage_status === 'running' ? 2500 : false),
  });

  // Each tab list loads lazily — only when its tab is the active one — and then auto-refreshes
  // on an interval while that tab stays open (react-query only polls while a query is enabled, so
  // switching away stops the polling and switching back resumes it). The Overview card no longer
  // depends on these lists; it reads the `active_release` / `last_deployment` summaries the
  // project `show` payload embeds, so entering the page fires only the single project request.
  const deployments = useQuery({
    queryKey: ['projects', slug, 'deployments'],
    queryFn: async () =>
      (await api.get<ApiEnvelope<Deployment[]>>(`/projects/${slug}/deployments`)).data.data,
    enabled: !!slug && activeTab === 'deployments',
    refetchInterval: activeTab === 'deployments' ? 5000 : false,
  });

  const releases = useQuery({
    queryKey: ['projects', slug, 'releases'],
    queryFn: async () => (await api.get<ApiEnvelope<Release[]>>(`/projects/${slug}/releases`)).data.data,
    enabled: !!slug && activeTab === 'releases',
    refetchInterval: activeTab === 'releases' ? 10000 : false,
  });

  const activity = useQuery({
    queryKey: ['projects', slug, 'activity'],
    queryFn: async () => (await api.get<ApiEnvelope<AuditLog[]>>(`/projects/${slug}/activity`)).data.data,
    enabled: !!slug && activeTab === 'activity',
    refetchInterval: activeTab === 'activity' ? 10000 : false,
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
      // Prefix invalidation refreshes the project payload (Overview's live-release/last-deploy
      // summaries) plus the releases/deployments/activity tab lists in one call.
      queryClient.invalidateQueries({ queryKey: ['projects', slug] });
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

  const preflight = useMutation({
    mutationFn: async () =>
      (await api.post<ApiEnvelope<PreflightReport>>(`/projects/${slug}/preflight`)).data.data,
    onSuccess: (report) => {
      setPreflightReport(report);
      openPreflightModal();
      notifications.show({
        color: report.ok ? 'green' : 'red',
        title: report.ok ? 'Host is ready' : 'Host needs attention',
        message: report.ok
          ? 'All preflight checks passed — see details.'
          : 'One or more checks reported an error — see details.',
        icon: report.ok ? <IconCheck size={16} /> : <IconAlertTriangle size={16} />,
      });
    },
    onError: (error) => {
      const message = axios.isAxiosError(error)
        ? (error.response?.data as { error?: string } | undefined)?.error
        : undefined;
      notifications.show({
        color: 'red',
        title: 'Preflight failed',
        message: message ?? 'Could not run the host preflight check.',
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

  const closeEditModal = () => {
    closeEditDisclosure();
    editForm.reset();
  };

  const editProject = useMutation({
    mutationFn: async (values: ProjectEditFormValues) => {
      const payload = {
        name: values.name,
        type: values.type,
        docroot_subpath: values.docroot_subpath || null,
        php_binary: values.php_binary || null,
        keep_releases: values.keep_releases,
        health_check_url: values.health_check_url || null,
        shared_paths: values.shared_paths
          .map((entry) => ({ path: entry.path.trim(), type: entry.type }))
          .filter((entry) => entry.path.length > 0),
        hooks: {
          pre_activate: values.hooks.pre_activate.map((c) => c.trim()).filter((c) => c.length > 0),
          post_activate: values.hooks.post_activate.map((c) => c.trim()).filter((c) => c.length > 0),
        },
      };
      return (await api.patch<ApiEnvelope<Project>>(`/projects/${slug}`, payload)).data.data;
    },
    onSuccess: () => {
      notifications.show({
        color: 'green',
        title: 'Project updated',
        message: 'Changes were saved.',
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      queryClient.invalidateQueries({ queryKey: ['projects', slug] });
      closeEditModal();
    },
    onError: (error) => {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const errors = error.response.data?.errors as Record<string, string[]> | undefined;
        if (errors) {
          editForm.setErrors(
            Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]])),
          );
        }
      }
    },
  });

  const closeCloneModal = () => {
    closeCloneDisclosure();
    cloneForm.reset();
  };

  const cloneProject = useMutation({
    mutationFn: async (values: CloneFormValues) => {
      const payload = {
        name: values.name,
        base_path: composeBasePath(values.base_dir, values.base_subpath),
      };
      return (await api.post<ApiEnvelope<Project>>(`/projects/${slug}/clone`, payload)).data.data;
    },
    onSuccess: (cloned) => {
      notifications.show({
        color: 'green',
        title: 'Project cloned',
        message: `${cloned.name} was created from this project's configuration.`,
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      closeCloneModal();
      navigate(`/projects/${cloned.slug}`);
    },
    onError: (error) => {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const errors = error.response.data?.errors as Record<string, string[]> | undefined;
        if (errors) {
          cloneForm.setErrors(
            Object.fromEntries(
              // The server validates the composed `base_path`; surface that on the subpath input
              // (also used as the fallback absolute-path field when no base paths are configured).
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

  const toggleStatus = useMutation({
    mutationFn: async (status: ProjectStatus) =>
      (await api.patch<ApiEnvelope<Project>>(`/projects/${slug}`, { status })).data.data,
    onSuccess: (updated) => {
      notifications.show({
        color: 'green',
        title: updated.status === 'active' ? 'Project enabled' : 'Project disabled',
        message:
          updated.status === 'active'
            ? 'Deploys are re-enabled for this project.'
            : 'Deploys are now blocked for this project.',
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      queryClient.invalidateQueries({ queryKey: ['projects', slug] });
    },
    onError: (error) => {
      const message = axios.isAxiosError(error)
        ? (error.response?.data as { error?: string } | undefined)?.error
        : undefined;
      notifications.show({
        color: 'red',
        title: 'Status change failed',
        message: message ?? 'Something went wrong.',
        icon: <IconX size={16} />,
      });
    },
  });

  const closeDeleteModal = () => {
    closeDeleteDisclosure();
    setPurge('none');
  };

  const deleteProject = useMutation({
    mutationFn: async () => {
      const response = await api.delete<ApiEnvelope<Project | null>>(`/projects/${slug}`, {
        data: { purge },
      });
      return response;
    },
    onSuccess: (response) => {
      const deleting = response.status === 202 || response.data.data?.status === 'deleting';
      notifications.show({
        color: 'green',
        title: deleting ? 'Deletion started' : 'Project deleted',
        message: deleting
          ? 'Project is being deleted — reclaiming disk in the background.'
          : 'Project deleted.',
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      navigate('/projects');
    },
    onError: (error) => {
      const message = axios.isAxiosError(error)
        ? (error.response?.data as { error?: string } | undefined)?.error
        : undefined;
      notifications.show({
        color: 'red',
        title: 'Delete failed',
        message: message ?? 'Something went wrong.',
        icon: <IconX size={16} />,
      });
    },
  });

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
  // Overview reads the summaries embedded in the project payload (not the lazy tab lists) so the
  // Deployments/Releases requests only fire when their tab is opened.
  const activeRelease = p.active_release ?? null;
  const lastDeployment = p.last_deployment ?? null;
  const hasReleases = (p.release_count ?? 0) > 0;
  const diskBusy = p.disk_usage_status === 'running' || recomputeDisk.isPending;

  const noBasePaths = !capabilities.isLoading && allowedBasePaths.length === 0;
  const cloneBasePrefix = cloneForm.values.base_dir ? `${trimBaseDir(cloneForm.values.base_dir)}/` : '';
  const cloneComposedBasePath = composeBasePath(cloneForm.values.base_dir, cloneForm.values.base_subpath);
  const clonePrefixWidth = cloneBasePrefix ? Math.min(cloneBasePrefix.length * 8 + 16, 240) : undefined;

  const openEditModal = () => {
    editForm.reset();
    editForm.setValues({
      name: p.name,
      type: p.type,
      docroot_subpath: p.docroot_subpath ?? '',
      php_binary: p.php_binary ?? '',
      keep_releases: p.keep_releases,
      health_check_url: p.health_check_url ?? '',
      shared_paths: p.shared_paths ?? [],
      hooks: {
        pre_activate: p.hooks?.pre_activate ?? [],
        post_activate: p.hooks?.post_activate ?? [],
      },
    });
    openEditDisclosure();
  };

  const openCloneModal = () => {
    cloneForm.reset();
    cloneForm.setFieldValue('name', `${p.name} (copy)`);
    openCloneDisclosure();
  };

  const confirmToggleStatus = () => {
    const nextStatus: ProjectStatus = p.status === 'active' ? 'disabled' : 'active';
    modals.openConfirmModal({
      title: nextStatus === 'disabled' ? 'Disable project' : 'Enable project',
      children: (
        <Text size="sm">
          {nextStatus === 'disabled'
            ? 'Disabling stops cPorter from accepting new deploys for this project until re-enabled.'
            : 'Re-enable deploys for this project?'}
        </Text>
      ),
      labels: { confirm: nextStatus === 'disabled' ? 'Disable' : 'Enable', cancel: 'Cancel' },
      confirmProps: { color: nextStatus === 'disabled' ? 'red' : 'green' },
      onConfirm: () => toggleStatus.mutate(nextStatus),
    });
  };

  return (
    <Stack gap="lg">
      <Breadcrumbs>
        <Anchor component={Link} to="/projects" size="sm">
          Projects
        </Anchor>
        <Text size="sm">{p.name}</Text>
      </Breadcrumbs>

      <Group justify="space-between" align="flex-start">
        <Stack gap={4}>
          <Group gap="sm" align="center" wrap="nowrap">
            <Title order={2}>{p.name}</Title>
            {p.health_check_url && (
              <Anchor href={p.health_check_url} target="_blank" rel="noreferrer" size="sm">
                <Group gap={4} wrap="nowrap">
                  <IconExternalLink size={14} />
                  Health URL
                </Group>
              </Anchor>
            )}
          </Group>
          <Group gap="xs" align="center">
            <Badge color={p.status === 'active' ? 'green' : 'gray'} variant="light">
              {p.status}
            </Badge>
            <Badge variant="light">{p.type}</Badge>
            <Text c="dimmed" size="sm">
              {p.base_path}
            </Text>
          </Group>
        </Stack>
        <Group gap="xs">
          {isAdmin && (
            <Button
              variant="light"
              size="xs"
              leftSection={<IconPencil size={14} />}
              onClick={openEditModal}
            >
              Edit
            </Button>
          )}
          {isAdmin && (
            <Button
              variant="light"
              size="xs"
              leftSection={<IconCopy size={14} />}
              onClick={openCloneModal}
            >
              Clone
            </Button>
          )}
          {isAdmin && (
            <Button
              variant="light"
              size="xs"
              color={p.status === 'active' ? 'red' : 'green'}
              onClick={confirmToggleStatus}
            >
              {p.status === 'active' ? 'Disable' : 'Enable'}
            </Button>
          )}
          {isAdmin && (
            <Button
              variant="light"
              size="xs"
              color="red"
              leftSection={<IconTrash size={14} />}
              onClick={openDeleteDisclosure}
            >
              Delete
            </Button>
          )}
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
              <Group gap="xs">
                <Button
                  size="compact-xs"
                  variant="light"
                  leftSection={<IconRefresh size={14} />}
                  loading={diskBusy}
                  onClick={() => recomputeDisk.mutate()}
                >
                  {diskBusy ? 'Recalculating…' : 'Recalculate'}
                </Button>
                <Button
                  size="compact-xs"
                  variant="light"
                  leftSection={<IconChecklist size={14} />}
                  loading={preflight.isPending}
                  disabled={preflight.isPending}
                  onClick={() => preflight.mutate()}
                >
                  {preflight.isPending ? 'Checking…' : 'Check host setup'}
                </Button>
              </Group>
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

      <Tabs value={activeTab} onChange={setActiveTab}>
        <Tabs.List>
          <Tabs.Tab value="deployments">Deployments</Tabs.Tab>
          <Tabs.Tab value="releases">Releases</Tabs.Tab>
          <Tabs.Tab value="activity">Activity</Tabs.Tab>
        </Tabs.List>

        <Tabs.Panel value="deployments" pt="md">
          <Paper withBorder radius="md">
            <PanelBody query={deployments} errorTitle="Couldn't load deployments">
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
            </PanelBody>
          </Paper>
        </Tabs.Panel>

        <Tabs.Panel value="releases" pt="md">
          <Paper withBorder radius="md">
            <PanelBody query={releases} errorTitle="Couldn't load releases">
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
            </PanelBody>
          </Paper>
        </Tabs.Panel>

        <Tabs.Panel value="activity" pt="md">
          <Paper withBorder radius="md">
            <PanelBody query={activity} errorTitle="Couldn't load activity">
              <Table.ScrollContainer minWidth={600}>
                <Table highlightOnHover verticalSpacing="sm">
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Action</Table.Th>
                      <Table.Th>Actor</Table.Th>
                      <Table.Th>When</Table.Th>
                      <Table.Th>Details</Table.Th>
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {(activity.data ?? []).map((log) => {
                      const meta = getActivityMeta(log.action);
                      const details = summarizeActivityMeta(log.action, log.meta);
                      return (
                        <Table.Tr key={log.id}>
                          <Table.Td>
                            <Badge color={meta.color} variant="light">
                              {meta.label}
                            </Badge>
                          </Table.Td>
                          <Table.Td>{log.actor ?? '—'}</Table.Td>
                          <Table.Td>
                            <Tooltip label={formatDateTime(log.created_at)}>
                              <Text size="sm" span>
                                {formatRelativeTime(log.created_at)}
                              </Text>
                            </Tooltip>
                          </Table.Td>
                          <Table.Td>
                            <Text size="sm" c="dimmed">
                              {details}
                            </Text>
                          </Table.Td>
                        </Table.Tr>
                      );
                    })}
                    {activity.data?.length === 0 && (
                      <Table.Tr>
                        <Table.Td colSpan={4}>
                          <Text c="dimmed" size="sm">
                            No activity yet.
                          </Text>
                        </Table.Td>
                      </Table.Tr>
                    )}
                  </Table.Tbody>
                </Table>
              </Table.ScrollContainer>
            </PanelBody>
          </Paper>
        </Tabs.Panel>
      </Tabs>

      <DeploymentDrawer deploymentId={selectedDeployment} onClose={() => setSelectedDeployment(null)} />

      <Drawer opened={sharedOpened} onClose={closeShared} position="right" size="md" title="Shared paths">
        {p.shared_paths && p.shared_paths.length > 0 ? (
          <Stack gap="xs">
            <Text size="xs" c="dimmed">
              Sizes as of{' '}
              {p.disk_usage_calculated_at ? formatRelativeTime(p.disk_usage_calculated_at) : 'never'} —
              use Recalculate to refresh.
            </Text>
            {p.shared_paths.map((sp, index) => (
              <Group key={`${sp.path}-${index}`} justify="space-between" wrap="nowrap">
                <Code>{sp.path}</Code>
                <Group gap="sm" wrap="nowrap">
                  <Text size="sm" c="dimmed">
                    {formatBytes(p.shared_disk_usage?.[sp.path])}
                  </Text>
                  <Badge color={sp.type === 'dir' ? 'blue' : 'grape'} variant="light">
                    {sp.type}
                  </Badge>
                </Group>
              </Group>
            ))}
          </Stack>
        ) : (
          <Text c="dimmed" size="sm">
            No shared paths configured.
          </Text>
        )}
      </Drawer>

      <Modal opened={editOpened} onClose={closeEditModal} title="Edit project" size="lg">
        <form onSubmit={editForm.onSubmit((values) => editProject.mutate(values))}>
          <Stack gap="sm">
            <TextInput label="Name" placeholder="My App" required {...editForm.getInputProps('name')} />
            <Select
              label="Type"
              description={
                hasReleases
                  ? 'Frozen once the project has releases.'
                  : 'Static, PHP & WordPress deploy fully in web PHP (no shell). Laravel & Node also run shell steps (migrate, build, restart) via the cron worker.'
              }
              data={PROJECT_TYPES}
              required
              disabled={hasReleases}
              {...editForm.getInputProps('type')}
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
              {...editForm.getInputProps('docroot_subpath')}
            />
            <TextInput
              label="PHP binary"
              placeholder="/usr/bin/php8.2"
              description="Override the PHP binary used for shell steps (migrate, artisan, npm build). Leave empty to use the server default."
              {...editForm.getInputProps('php_binary')}
            />
            <NumberInput
              label="Keep releases"
              description="How many past releases to retain for rollback before older ones are pruned."
              min={1}
              max={50}
              {...editForm.getInputProps('keep_releases')}
            />
            <TextInput
              label="Health check URL"
              placeholder="https://example.com/health"
              description="Polled after each activation; if it fails, cPorter auto-rolls back to the previous release. Leave empty to skip."
              {...editForm.getInputProps('health_check_url')}
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
              {editForm.values.shared_paths.map((_, index) => (
                <Group key={index} gap="xs" align="flex-start">
                  <TextInput
                    placeholder="storage or .env"
                    style={{ flex: 1 }}
                    {...editForm.getInputProps(`shared_paths.${index}.path`)}
                  />
                  <Select
                    data={SHARED_PATH_TYPES}
                    w={130}
                    {...editForm.getInputProps(`shared_paths.${index}.type`)}
                  />
                  <ActionIcon
                    color="red"
                    variant="subtle"
                    onClick={() => editForm.removeListItem('shared_paths', index)}
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
                onClick={() => editForm.insertListItem('shared_paths', { path: '', type: 'dir' })}
              >
                Add shared path
              </Button>
            </Stack>
            <Stack gap="sm">
              <Text size="xs" c="dimmed">
                Commands run on the cron worker — mainly for Laravel/Node projects.{' '}
                <Code>artisan …</Code> commands use the project&apos;s PHP binary; anything else runs as
                a raw shell command in the release directory.
              </Text>
              {HOOK_STAGES.map((stage) => (
                <Stack gap="xs" key={stage.key}>
                  <Text size="sm" fw={500}>
                    {stage.label}
                  </Text>
                  <Text size="xs" c="dimmed">
                    {stage.helper}
                  </Text>
                  {editForm.values.hooks[stage.key].map((_, index) => (
                    <Group key={index} gap="xs" align="flex-start">
                      <TextInput
                        placeholder="artisan migrate --force"
                        style={{ flex: 1 }}
                        {...editForm.getInputProps(`hooks.${stage.key}.${index}`)}
                      />
                      <ActionIcon
                        color="red"
                        variant="subtle"
                        onClick={() => editForm.removeListItem(`hooks.${stage.key}`, index)}
                        aria-label="Remove command"
                      >
                        <IconTrash size={16} />
                      </ActionIcon>
                    </Group>
                  ))}
                  <Button
                    variant="subtle"
                    size="xs"
                    leftSection={<IconPlus size={14} />}
                    onClick={() => editForm.insertListItem(`hooks.${stage.key}`, '')}
                  >
                    Add command
                  </Button>
                </Stack>
              ))}
            </Stack>
            <Group justify="flex-end" mt="md">
              <Button variant="default" onClick={closeEditModal}>
                Cancel
              </Button>
              <Button type="submit" loading={editProject.isPending}>
                Save
              </Button>
            </Group>
          </Stack>
        </form>
      </Modal>

      <Modal opened={deleteOpened} onClose={closeDeleteModal} title="Delete project" size="md">
        <Stack gap="sm">
          <Text size="sm">
            This removes <b>{p.name}</b> from cPorter. Choose what happens to the files on disk.
          </Text>
          <Radio.Group
            label="Disk cleanup"
            value={purge}
            onChange={(value) => setPurge(value as 'none' | 'releases' | 'all')}
          >
            <Stack gap="xs" mt="xs">
              <Radio value="none" label="Keep all files on disk (just stop managing it)" />
              <Radio value="releases" label="Delete past releases, keep shared data (.env, uploads)" />
              <Radio value="all" label="Delete everything in the project folder" />
            </Stack>
          </Radio.Group>
          {purge === 'all' && (
            <Alert color="red" variant="light" icon={<IconAlertTriangle size={16} />}>
              This permanently deletes the entire folder <Code>{p.base_path}</Code>. This cannot be
              undone.
            </Alert>
          )}
          <Group justify="flex-end" mt="md">
            <Button variant="default" onClick={closeDeleteModal}>
              Cancel
            </Button>
            <Button color="red" loading={deleteProject.isPending} onClick={() => deleteProject.mutate()}>
              Delete
            </Button>
          </Group>
        </Stack>
      </Modal>

      <Modal opened={cloneOpened} onClose={closeCloneModal} title="Clone project" size="lg">
        <form onSubmit={cloneForm.onSubmit((values) => cloneProject.mutate(values))}>
          <Stack gap="sm">
            <Text size="xs" c="dimmed">
              Copies configuration only — releases aren&apos;t cloned, and any shared files (e.g.{' '}
              <Code>.env</Code>) must be copied into the new folder&apos;s <Code>shared/</Code>{' '}
              manually.
            </Text>
            <TextInput
              label="Name"
              placeholder="My App (copy)"
              required
              {...cloneForm.getInputProps('name')}
            />
            {noBasePaths ? (
              <TextInput
                label="Base path"
                placeholder="/home/user/my-app-copy"
                description="No allowed base paths are configured (CPORTER_ALLOWED_BASE_PATHS is empty) — enter a full absolute path."
                required
                {...cloneForm.getInputProps('base_subpath')}
              />
            ) : (
              <Stack gap="xs">
                {allowedBasePaths.length > 1 && (
                  <Select
                    label="Base directory"
                    description="Deploy jail root — the project folder is created inside it."
                    data={allowedBasePaths}
                    allowDeselect={false}
                    {...cloneForm.getInputProps('base_dir')}
                  />
                )}
                <TextInput
                  label="Project folder"
                  placeholder="my-app-copy"
                  required
                  description={
                    cloneForm.values.base_subpath.trim() ? (
                      <>
                        Full path: <Code>{cloneComposedBasePath}</Code>
                      </>
                    ) : (
                      'Only the folder inside the base directory — the prefix above is fixed for you.'
                    )
                  }
                  leftSection={
                    <Text ff="monospace" size="sm" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
                      {cloneBasePrefix}
                    </Text>
                  }
                  leftSectionPointerEvents="none"
                  leftSectionWidth={clonePrefixWidth}
                  {...cloneForm.getInputProps('base_subpath')}
                />
              </Stack>
            )}
            <Group justify="flex-end" mt="md">
              <Button variant="default" onClick={closeCloneModal}>
                Cancel
              </Button>
              <Button type="submit" loading={cloneProject.isPending}>
                Clone
              </Button>
            </Group>
          </Stack>
        </form>
      </Modal>

      <Modal opened={preflightOpened} onClose={closePreflightModal} title="Host preflight check" size="lg">
        {preflightReport && (
          <Stack gap="md">
            <Alert
              color={preflightReport.ok ? 'green' : 'red'}
              variant="light"
              icon={preflightReport.ok ? <IconCheck size={16} /> : <IconAlertTriangle size={16} />}
            >
              <Text fw={600} size="sm">
                {preflightReport.ok ? 'Host is ready to deploy' : 'Host needs attention before deploying'}
              </Text>
              <Text size="xs" c="dimmed" mt={4}>
                <b>Manual</b> (e.g. Document Root) and <b>warning</b> (e.g. shared files) checks are
                steps you complete yourself in cPanel — they don&apos;t block this result. A{' '}
                <b>pending</b> <Code>current</Code> symlink is normal before the first deploy.
              </Text>
            </Alert>
            <Text size="xs" c="dimmed">
              Base path: <Code>{preflightReport.base_path}</Code>
            </Text>
            <List spacing="md" size="sm">
              {preflightReport.checks.map((check) => {
                const meta = PREFLIGHT_STATUS_META[check.status];
                return (
                  <List.Item
                    key={check.key}
                    icon={
                      <ThemeIcon color={meta.color} variant="light" size={24} radius="xl">
                        {meta.icon}
                      </ThemeIcon>
                    }
                  >
                    <Group gap="xs" wrap="wrap">
                      <Text size="sm" fw={600}>
                        {check.label}
                      </Text>
                      <Badge size="xs" color={meta.color} variant="light">
                        {check.status}
                      </Badge>
                    </Group>
                    <Text size="xs" c="dimmed">
                      {check.detail}
                    </Text>
                  </List.Item>
                );
              })}
            </List>
            <Group justify="flex-end">
              <Button variant="default" onClick={closePreflightModal}>
                Close
              </Button>
            </Group>
          </Stack>
        )}
      </Modal>
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
