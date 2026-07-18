import { useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Code,
  CopyButton,
  Group,
  Loader,
  Modal,
  MultiSelect,
  Paper,
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
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import { IconCheck, IconCopy, IconPlus, IconTrash, IconX } from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import type { ApiEnvelope, ApiKey, Project } from '@/lib/types';

const SCOPES = ['read', 'deploy', 'rollback', 'admin'];

interface ApiKeyFormValues {
  name: string;
  scopes: string[];
  project_id: string | null;
  expires_at: string;
}

const INITIAL_VALUES: ApiKeyFormValues = { name: '', scopes: [], project_id: null, expires_at: '' };

export function ApiKeysPage() {
  const [opened, { open, close }] = useDisclosure(false);
  const [newToken, setNewToken] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const apiKeys = useQuery({
    queryKey: ['api-keys'],
    queryFn: async () => (await api.get<ApiEnvelope<ApiKey[]>>('/api-keys')).data.data,
  });

  const projects = useQuery({
    queryKey: ['projects'],
    queryFn: async () => (await api.get<ApiEnvelope<Project[]>>('/projects')).data.data,
  });

  const form = useForm<ApiKeyFormValues>({
    initialValues: INITIAL_VALUES,
    validate: {
      name: (value) => (value.trim().length > 0 ? null : 'Name is required'),
    },
  });

  const createKey = useMutation({
    mutationFn: async (values: ApiKeyFormValues) => {
      const payload = {
        name: values.name,
        scopes: values.scopes,
        project_id: values.project_id ? Number(values.project_id) : undefined,
        expires_at: values.expires_at || undefined,
      };
      return (await api.post<ApiEnvelope<ApiKey> & { token: string }>('/api-keys', payload)).data;
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['api-keys'] });
      setNewToken(data.token);
      form.reset();
    },
    onError: (error) => {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const errors = error.response.data?.errors as Record<string, string[]> | undefined;
        if (errors) {
          form.setErrors(Object.fromEntries(Object.entries(errors).map(([f, m]) => [f, m[0]])));
        }
      }
    },
  });

  const revokeKey = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/api-keys/${id}`)).data,
    onSuccess: () => {
      notifications.show({
        color: 'green',
        title: 'Key revoked',
        message: 'The API key has been revoked.',
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['api-keys'] });
    },
    onError: () => {
      notifications.show({
        color: 'red',
        title: 'Failed to revoke key',
        message: 'Please try again.',
        icon: <IconX size={16} />,
      });
    },
  });

  const confirmRevoke = (key: ApiKey) => {
    modals.openConfirmModal({
      title: 'Revoke API key',
      children: (
        <Text size="sm">
          Revoke &quot;{key.name}&quot;? Any CI using this key will lose access immediately.
        </Text>
      ),
      labels: { confirm: 'Revoke', cancel: 'Cancel' },
      confirmProps: { color: 'red' },
      onConfirm: () => revokeKey.mutate(key.id),
    });
  };

  const closeModal = () => {
    close();
    setNewToken(null);
    form.reset();
  };

  const projectName = (id: number | null) => projects.data?.find((p) => p.id === id)?.name ?? '—';

  return (
    <Stack gap="lg">
      <Group justify="space-between">
        <div>
          <Title order={2}>API Keys</Title>
          <Text c="dimmed" size="sm">
            Tokens used by CI to deploy and manage projects.
          </Text>
        </div>
        <Button leftSection={<IconPlus size={16} />} onClick={open}>
          New key
        </Button>
      </Group>

      <Paper withBorder radius="md">
        {apiKeys.isLoading ? (
          <Group justify="center" p="xl">
            <Loader />
          </Group>
        ) : (
          <Table.ScrollContainer minWidth={700}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Name</Table.Th>
                  <Table.Th>Prefix</Table.Th>
                  <Table.Th>Scopes</Table.Th>
                  <Table.Th>Project</Table.Th>
                  <Table.Th>Last used</Table.Th>
                  <Table.Th>Status</Table.Th>
                  <Table.Th />
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {(apiKeys.data ?? []).map((k) => {
                  const revoked = !!k.revoked_at;
                  const expired = !!k.expires_at && new Date(k.expires_at) < new Date();
                  return (
                    <Table.Tr key={k.id}>
                      <Table.Td>{k.name}</Table.Td>
                      <Table.Td>
                        <Code>{k.prefix}</Code>
                      </Table.Td>
                      <Table.Td>
                        <Group gap={4}>
                          {k.scopes.map((s) => (
                            <Badge key={s} size="sm" variant="light">
                              {s}
                            </Badge>
                          ))}
                        </Group>
                      </Table.Td>
                      <Table.Td>{projectName(k.project_id)}</Table.Td>
                      <Table.Td>{formatDateTime(k.last_used_at)}</Table.Td>
                      <Table.Td>
                        <Badge color={revoked ? 'gray' : expired ? 'orange' : 'green'} variant="light">
                          {revoked ? 'revoked' : expired ? 'expired' : 'active'}
                        </Badge>
                      </Table.Td>
                      <Table.Td>
                        {!revoked && (
                          <Button
                            size="xs"
                            color="red"
                            variant="subtle"
                            leftSection={<IconTrash size={14} />}
                            onClick={() => confirmRevoke(k)}
                          >
                            Revoke
                          </Button>
                        )}
                      </Table.Td>
                    </Table.Tr>
                  );
                })}
                {apiKeys.data?.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={7}>
                      <Text c="dimmed" size="sm">
                        No API keys yet.
                      </Text>
                    </Table.Td>
                  </Table.Tr>
                )}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>

      <Modal opened={opened} onClose={closeModal} title="New API key" size="lg">
        {newToken ? (
          <Stack gap="sm">
            <Alert color="yellow" title="Copy this token now" icon={<IconCheck size={16} />}>
              This is the only time the full token will be shown. Store it securely.
            </Alert>
            <Group gap="xs" wrap="nowrap" align="flex-start">
              <Code block style={{ flex: 1, wordBreak: 'break-all' }}>
                {newToken}
              </Code>
              <CopyButton value={newToken}>
                {({ copied, copy }) => (
                  <Button
                    size="xs"
                    color={copied ? 'teal' : 'indigo'}
                    onClick={copy}
                    leftSection={<IconCopy size={14} />}
                  >
                    {copied ? 'Copied' : 'Copy'}
                  </Button>
                )}
              </CopyButton>
            </Group>
            <Group justify="flex-end">
              <Button onClick={closeModal}>Done</Button>
            </Group>
          </Stack>
        ) : (
          <form onSubmit={form.onSubmit((values) => createKey.mutate(values))}>
            <Stack gap="sm">
              <TextInput label="Name" placeholder="CI deploy key" required {...form.getInputProps('name')} />
              <MultiSelect label="Scopes" data={SCOPES} {...form.getInputProps('scopes')} />
              <Select
                label="Project"
                placeholder="All projects"
                clearable
                data={(projects.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
                {...form.getInputProps('project_id')}
              />
              <TextInput
                label="Expires at"
                placeholder="YYYY-MM-DD"
                {...form.getInputProps('expires_at')}
              />
              <Group justify="flex-end" mt="md">
                <Button variant="default" onClick={closeModal}>
                  Cancel
                </Button>
                <Button type="submit" loading={createKey.isPending}>
                  Create
                </Button>
              </Group>
            </Stack>
          </form>
        )}
      </Modal>
    </Stack>
  );
}
