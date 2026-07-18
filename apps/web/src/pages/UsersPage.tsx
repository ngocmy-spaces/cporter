import {
  Alert,
  Badge,
  Button,
  Group,
  Loader,
  Modal,
  Paper,
  PasswordInput,
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
import { IconCheck, IconLock, IconPlus, IconTrash, IconX } from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';
import type { ApiEnvelope, User, UserRole } from '@/lib/types';

const ROLES: { value: UserRole; label: string }[] = [
  { value: 'admin', label: 'Admin' },
  { value: 'viewer', label: 'Viewer' },
];

interface UserFormValues {
  name: string;
  email: string;
  password: string;
  role: UserRole;
}

const INITIAL_VALUES: UserFormValues = { name: '', email: '', password: '', role: 'viewer' };

export function UsersPage() {
  const { user: currentUser } = useAuth();
  const isAdmin = currentUser?.role === 'admin';
  const [opened, { open, close }] = useDisclosure(false);
  const queryClient = useQueryClient();

  const users = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await api.get<ApiEnvelope<User[]>>('/users')).data.data,
    enabled: isAdmin,
  });

  const form = useForm<UserFormValues>({
    initialValues: INITIAL_VALUES,
    validate: {
      name: (value) => (value.trim().length > 0 ? null : 'Name is required'),
      email: (value) => (/^\S+@\S+\.\S+$/.test(value) ? null : 'Enter a valid email'),
      password: (value) => (value.length >= 8 ? null : 'Password must be at least 8 characters'),
    },
  });

  const createUser = useMutation({
    mutationFn: async (values: UserFormValues) =>
      (await api.post<ApiEnvelope<User>>('/users', values)).data.data,
    onSuccess: () => {
      notifications.show({
        color: 'green',
        title: 'User created',
        message: 'The new user can now sign in.',
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['users'] });
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
          return;
        }
      }
      notifications.show({
        color: 'red',
        title: 'Failed to create user',
        message: 'Please try again.',
        icon: <IconX size={16} />,
      });
    },
  });

  const deleteUser = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/users/${id}`)).data,
    onSuccess: () => {
      notifications.show({
        color: 'green',
        title: 'User deleted',
        message: 'The user has been removed.',
        icon: <IconCheck size={16} />,
      });
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
    onError: (error) => {
      const message = axios.isAxiosError(error)
        ? (error.response?.data as { message?: string } | undefined)?.message
        : undefined;
      notifications.show({
        color: 'red',
        title: 'Failed to delete user',
        message: message ?? 'Please try again.',
        icon: <IconX size={16} />,
      });
    },
  });

  const confirmDelete = (u: User) => {
    modals.openConfirmModal({
      title: 'Delete user',
      children: (
        <Text size="sm">
          Delete &quot;{u.name}&quot; ({u.email})? They will immediately lose access.
        </Text>
      ),
      labels: { confirm: 'Delete', cancel: 'Cancel' },
      confirmProps: { color: 'red' },
      onConfirm: () => deleteUser.mutate(u.id),
    });
  };

  const closeModal = () => {
    close();
    form.reset();
  };

  if (!isAdmin) {
    return (
      <Stack gap="md">
        <Title order={2}>Users</Title>
        <Alert color="yellow" title="Admins only" icon={<IconLock size={16} />}>
          You need an admin role to manage users.
        </Alert>
      </Stack>
    );
  }

  return (
    <Stack gap="lg">
      <Group justify="space-between">
        <div>
          <Title order={2}>Users</Title>
          <Text c="dimmed" size="sm">
            Admin accounts with access to this dashboard.
          </Text>
        </div>
        <Button leftSection={<IconPlus size={16} />} onClick={open}>
          New user
        </Button>
      </Group>

      <Paper withBorder radius="md">
        {users.isLoading ? (
          <Group justify="center" p="xl">
            <Loader />
          </Group>
        ) : (
          <Table.ScrollContainer minWidth={600}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Name</Table.Th>
                  <Table.Th>Email</Table.Th>
                  <Table.Th>Role</Table.Th>
                  <Table.Th>Created</Table.Th>
                  <Table.Th />
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {(users.data ?? []).map((u) => (
                  <Table.Tr key={u.id}>
                    <Table.Td>{u.name}</Table.Td>
                    <Table.Td>
                      <Text size="sm" c="dimmed">
                        {u.email}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Badge color={u.role === 'admin' ? 'indigo' : 'gray'} variant="light">
                        {u.role}
                      </Badge>
                    </Table.Td>
                    <Table.Td>{formatDateTime(u.created_at)}</Table.Td>
                    <Table.Td>
                      {u.id !== currentUser?.id && (
                        <Button
                          size="xs"
                          color="red"
                          variant="subtle"
                          leftSection={<IconTrash size={14} />}
                          onClick={() => confirmDelete(u)}
                          loading={deleteUser.isPending && deleteUser.variables === u.id}
                        >
                          Delete
                        </Button>
                      )}
                    </Table.Td>
                  </Table.Tr>
                ))}
                {users.data?.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={5}>
                      <Text c="dimmed" size="sm">
                        No users yet.
                      </Text>
                    </Table.Td>
                  </Table.Tr>
                )}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>

      <Modal opened={opened} onClose={closeModal} title="New user" size="md">
        <form onSubmit={form.onSubmit((values) => createUser.mutate(values))}>
          <Stack gap="sm">
            <TextInput label="Name" placeholder="Jane Doe" required {...form.getInputProps('name')} />
            <TextInput
              label="Email"
              placeholder="jane@example.com"
              required
              {...form.getInputProps('email')}
            />
            <PasswordInput
              label="Password"
              placeholder="At least 8 characters"
              required
              {...form.getInputProps('password')}
            />
            <Select
              label="Role"
              data={ROLES}
              required
              allowDeselect={false}
              {...form.getInputProps('role')}
            />
            <Group justify="flex-end" mt="md">
              <Button variant="default" onClick={closeModal}>
                Cancel
              </Button>
              <Button type="submit" loading={createUser.isPending}>
                Create
              </Button>
            </Group>
          </Stack>
        </form>
      </Modal>
    </Stack>
  );
}
