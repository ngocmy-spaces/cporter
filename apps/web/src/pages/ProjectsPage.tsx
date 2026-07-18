import {
  Badge,
  Button,
  Group,
  Loader,
  Modal,
  NumberInput,
  Paper,
  Select,
  Stack,
  Table,
  TagsInput,
  Text,
  TextInput,
  Title,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useForm } from '@mantine/form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { IconPlus } from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { ApiEnvelope, Project } from '@/lib/types';

const PROJECT_TYPES = [
  { value: 'static', label: 'Static' },
  { value: 'laravel', label: 'Laravel' },
  { value: 'php', label: 'PHP' },
  { value: 'node', label: 'Node' },
  { value: 'wordpress', label: 'WordPress' },
];

interface ProjectFormValues {
  name: string;
  base_path: string;
  type: string;
  docroot_subpath: string;
  keep_releases: number;
  health_check_url: string;
  shared_paths: string[];
}

const INITIAL_VALUES: ProjectFormValues = {
  name: '',
  base_path: '',
  type: 'static',
  docroot_subpath: '',
  keep_releases: 5,
  health_check_url: '',
  shared_paths: [],
};

export function ProjectsPage() {
  const [opened, { open, close }] = useDisclosure(false);
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';

  const projects = useQuery({
    queryKey: ['projects'],
    queryFn: async () => (await api.get<ApiEnvelope<Project[]>>('/projects')).data.data,
  });

  const form = useForm<ProjectFormValues>({
    initialValues: INITIAL_VALUES,
    validate: {
      name: (value) => (value.trim().length > 0 ? null : 'Name is required'),
      base_path: (value) => (value.trim().length > 0 ? null : 'Base path is required'),
    },
  });

  const createProject = useMutation({
    mutationFn: async (values: ProjectFormValues) => {
      const payload = {
        name: values.name,
        base_path: values.base_path,
        type: values.type,
        docroot_subpath: values.docroot_subpath || undefined,
        keep_releases: values.keep_releases,
        health_check_url: values.health_check_url || undefined,
        shared_paths: values.shared_paths,
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
            Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]])),
          );
        }
      }
    },
  });

  const closeModal = () => {
    close();
    form.reset();
  };

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

      <Paper withBorder radius="md">
        {projects.isLoading ? (
          <Group justify="center" p="xl">
            <Loader />
          </Group>
        ) : (
          <Table.ScrollContainer minWidth={700}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Name</Table.Th>
                  <Table.Th>Slug</Table.Th>
                  <Table.Th>Type</Table.Th>
                  <Table.Th>Base path</Table.Th>
                  <Table.Th>Status</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {(projects.data ?? []).map((p) => (
                  <Table.Tr key={p.id}>
                    <Table.Td>
                      <Text component={Link} to={`/projects/${p.slug}`} fw={500} c="indigo">
                        {p.name}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Text size="sm" c="dimmed">
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
                      <Badge color={p.status === 'active' ? 'green' : 'gray'} variant="light">
                        {p.status}
                      </Badge>
                    </Table.Td>
                  </Table.Tr>
                ))}
                {projects.data?.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={5}>
                      <Text c="dimmed" size="sm">
                        No projects yet — create one to get started.
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
            <TextInput
              label="Base path"
              placeholder="/home/user/my-app"
              description="Must be within an allowed base path (CPORTER_ALLOWED_BASE_PATHS)."
              required
              {...form.getInputProps('base_path')}
            />
            <Select label="Type" data={PROJECT_TYPES} required {...form.getInputProps('type')} />
            <TextInput
              label="Docroot subpath"
              placeholder="public"
              {...form.getInputProps('docroot_subpath')}
            />
            <NumberInput
              label="Keep releases"
              min={1}
              max={50}
              {...form.getInputProps('keep_releases')}
            />
            <TextInput
              label="Health check URL"
              placeholder="https://example.com/health"
              {...form.getInputProps('health_check_url')}
            />
            <TagsInput
              label="Shared paths"
              description="Press enter to add — e.g. storage, .env"
              placeholder="storage"
              {...form.getInputProps('shared_paths')}
            />
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
