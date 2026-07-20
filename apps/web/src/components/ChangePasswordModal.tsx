import { Button, Checkbox, Group, Modal, PasswordInput, Stack } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useMutation } from '@tanstack/react-query';
import { notifications } from '@mantine/notifications';
import { IconCheck, IconX } from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';

interface ChangePasswordValues {
  current_password: string;
  password: string;
  password_confirmation: string;
  logout_other_devices: boolean;
}

const INITIAL_VALUES: ChangePasswordValues = {
  current_password: '',
  password: '',
  password_confirmation: '',
  logout_other_devices: true,
};

export function ChangePasswordModal({ opened, onClose }: { opened: boolean; onClose: () => void }) {
  const form = useForm<ChangePasswordValues>({
    initialValues: INITIAL_VALUES,
    validate: {
      current_password: (value) => (value.length > 0 ? null : 'Enter your current password'),
      password: (value, values) => {
        if (value.length < 8) return 'Password must be at least 8 characters';
        if (value === values.current_password) return 'Choose a password different from the current one';
        return null;
      },
      password_confirmation: (value, values) =>
        value === values.password ? null : 'Passwords do not match',
    },
  });

  const changePassword = useMutation({
    mutationFn: async (values: ChangePasswordValues) => (await api.put('/auth/password', values)).data,
    onSuccess: () => {
      notifications.show({
        color: 'green',
        title: 'Password changed',
        message: 'Your password has been updated.',
        icon: <IconCheck size={16} />,
      });
      handleClose();
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
        title: 'Failed to change password',
        message: 'Please try again.',
        icon: <IconX size={16} />,
      });
    },
  });

  const handleClose = () => {
    form.reset();
    onClose();
  };

  return (
    <Modal opened={opened} onClose={handleClose} title="Change password" size="md">
      <form onSubmit={form.onSubmit((values) => changePassword.mutate(values))}>
        <Stack gap="sm">
          <PasswordInput
            label="Current password"
            autoComplete="current-password"
            required
            {...form.getInputProps('current_password')}
          />
          <PasswordInput
            label="New password"
            placeholder="At least 8 characters"
            autoComplete="new-password"
            required
            {...form.getInputProps('password')}
          />
          <PasswordInput
            label="Confirm new password"
            autoComplete="new-password"
            required
            {...form.getInputProps('password_confirmation')}
          />
          <Checkbox
            mt="xs"
            label="Log out my other devices"
            description="Sign out every other active session for this account. This device stays signed in."
            {...form.getInputProps('logout_other_devices', { type: 'checkbox' })}
          />
          <Group justify="flex-end" mt="md">
            <Button variant="default" onClick={handleClose}>
              Cancel
            </Button>
            <Button type="submit" loading={changePassword.isPending}>
              Change password
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
