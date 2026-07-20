import { useEffect, useState } from 'react';
import {
  ActionIcon,
  Alert,
  Button,
  FileInput,
  Group,
  Loader,
  Modal,
  Paper,
  PasswordInput,
  Stack,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useForm } from '@mantine/form';
import { modals } from '@mantine/modals';
import { notifications } from '@mantine/notifications';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  IconAlertTriangle,
  IconCheck,
  IconFileImport,
  IconPlus,
  IconTrash,
  IconX,
} from '@tabler/icons-react';
import axios from 'axios';
import { api } from '@/lib/api';
import { parseEnvText } from '@/lib/parseEnvText';
import type { ApiEnvelope, EnvVar, ProjectEnv } from '@/lib/types';

interface EnvFormValues {
  env_vars: EnvVar[];
}

/**
 * Environment-variable manager for one project (admin-only). Values are stored encrypted server-side
 * and rendered into shared/.env on the next deploy (docs/API.md — /projects/{slug}/env). Includes the
 * take-over flow for a hand-created shared/.env and a paste/upload importer.
 */
export function ProjectEnvPanel({ slug, active }: { slug: string; active: boolean }) {
  const queryClient = useQueryClient();
  const form = useForm<EnvFormValues>({
    initialValues: { env_vars: [] },
    validateInputOnBlur: true,
    // Per-key syntax + duplicate detection so users get instant feedback instead of a server 422.
    validate: (values) => {
      const errors: Record<string, string> = {};
      const counts = new Map<string, number>();
      for (const v of values.env_vars) {
        const k = v.key.trim();
        if (k) counts.set(k, (counts.get(k) ?? 0) + 1);
      }
      values.env_vars.forEach((v, index) => {
        const k = v.key.trim();
        if (!k) return; // blank rows are dropped on save, not invalid
        if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(k)) {
          errors[`env_vars.${index}.key`] =
            'Must start with a letter or underscore (letters, digits, underscores only)';
        } else if ((counts.get(k) ?? 0) > 1) {
          errors[`env_vars.${index}.key`] = 'Duplicate key';
        }
      });
      return errors;
    },
  });

  const [importOpened, importModal] = useDisclosure(false);
  const [importText, setImportText] = useState('');

  const query = useQuery({
    queryKey: ['projects', slug, 'env'],
    queryFn: async () => (await api.get<ApiEnvelope<ProjectEnv>>(`/projects/${slug}/env`)).data.data,
    enabled: !!slug && active,
  });

  // Repopulate the editor whenever the server payload changes (initial load + after a save/adopt).
  const vars = query.data?.vars;
  useEffect(() => {
    if (vars) {
      form.setValues({ env_vars: vars });
      form.resetDirty({ env_vars: vars });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vars]);

  const save = useMutation({
    mutationFn: async (values: EnvFormValues) => {
      const payload = {
        env_vars: values.env_vars
          .map((v) => ({ key: v.key.trim(), value: v.value }))
          .filter((v) => v.key.length > 0),
      };
      return (await api.put<ApiEnvelope<ProjectEnv>>(`/projects/${slug}/env`, payload)).data.data;
    },
    onSuccess: (data) => {
      notifications.show({
        color: 'green',
        title: 'Environment saved',
        message: 'These values overwrite shared/.env on the next deploy.',
        icon: <IconCheck size={16} />,
      });
      queryClient.setQueryData(['projects', slug, 'env'], data);
      queryClient.invalidateQueries({ queryKey: ['projects', slug, 'env'] });
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
        title: 'Save failed',
        message: 'Could not save the environment variables.',
        icon: <IconX size={16} />,
      });
    },
  });

  const adopt = useMutation({
    mutationFn: async () =>
      (await api.post<{ data: ProjectEnv; message?: string }>(`/projects/${slug}/env/adopt`)).data,
    onSuccess: (res) => {
      queryClient.setQueryData(['projects', slug, 'env'], res.data);
      notifications.show({
        color: 'green',
        title: 'cPorter now manages shared/.env',
        message: res.message ?? 'Trigger a deploy to apply it to the live release.',
        icon: <IconCheck size={16} />,
        autoClose: 8000,
      });
    },
    onError: () => {
      notifications.show({
        color: 'red',
        title: 'Take-over failed',
        message: 'Could not write shared/.env.',
        icon: <IconX size={16} />,
      });
    },
  });

  const confirmAdopt = () => {
    const count = form.values.env_vars.filter((v) => v.key.trim().length > 0).length;
    modals.openConfirmModal({
      title: 'Let cPorter manage shared/.env',
      children: (
        <Text size="sm">
          This overwrites the existing <code>shared/.env</code> on the server with the {count} variable
          {count === 1 ? '' : 's'} below and marks the file cPorter-managed. Trigger a deploy afterwards
          to apply it to the live release.
        </Text>
      ),
      labels: { confirm: 'Overwrite & take over', cancel: 'Cancel' },
      confirmProps: { color: 'yellow' },
      onConfirm: () => adopt.mutate(),
    });
  };

  const pickImportFile = async (file: File | null) => {
    if (file) {
      setImportText(await file.text());
    }
  };

  const runImport = () => {
    const { vars: parsed, skipped } = parseEnvText(importText);
    const next = [...form.values.env_vars];
    for (const v of parsed) {
      const idx = next.findIndex((e) => e.key !== '' && e.key === v.key);
      if (idx >= 0) {
        next[idx] = v;
      } else {
        next.push(v);
      }
    }
    form.setFieldValue('env_vars', next);
    notifications.show({
      color: parsed.length > 0 ? 'green' : 'yellow',
      title: parsed.length > 0 ? 'Imported' : 'Nothing imported',
      message: `${parsed.length} variable${parsed.length === 1 ? '' : 's'} imported${
        skipped > 0 ? `, ${skipped} line${skipped === 1 ? '' : 's'} skipped` : ''
      }.`,
      icon: <IconCheck size={16} />,
    });
    importModal.close();
    setImportText('');
  };

  if (query.isLoading) {
    return (
      <Group justify="center" p="xl">
        <Loader />
      </Group>
    );
  }

  if (query.isError) {
    return (
      <Alert color="red" variant="light" icon={<IconAlertTriangle size={16} />} title="Couldn't load environment" m="md">
        <Stack gap="xs" align="flex-start">
          <Text size="sm">The environment variables could not be loaded.</Text>
          <Button variant="light" size="xs" onClick={() => query.refetch()}>
            Retry
          </Button>
        </Stack>
      </Alert>
    );
  }

  const fileState = query.data?.file;
  const conflict = fileState?.exists && !fileState.managed;

  return (
    <Paper withBorder radius="md" p="md">
      <Stack gap="md">
        <Group justify="space-between" align="flex-start">
          <Text size="sm" c="dimmed" maw={520}>
            Stored encrypted and rendered into <code>shared/.env</code> on each deploy. Keys must start
            with a letter or underscore (e.g. <code>APP_KEY</code>).
          </Text>
          <Button
            variant="light"
            size="xs"
            leftSection={<IconFileImport size={14} />}
            onClick={importModal.open}
          >
            Import
          </Button>
        </Group>

        {conflict && (
          <Alert color="yellow" variant="light" icon={<IconAlertTriangle size={16} />} title="Unmanaged .env on the server">
            <Stack gap="xs" align="flex-start">
              <Text size="sm">
                A <code>shared/.env</code> already exists and isn&apos;t managed by cPorter. The variables
                below are <b>not</b> written on deploy until you take the file over.
              </Text>
              <Button
                variant="light"
                color="yellow"
                size="xs"
                loading={adopt.isPending}
                onClick={confirmAdopt}
              >
                Let cPorter manage this file
              </Button>
            </Stack>
          </Alert>
        )}

        <form onSubmit={form.onSubmit((values) => save.mutate(values))}>
          <Stack gap="xs">
            {form.values.env_vars.map((_, index) => (
              <Group key={form.key(`env_vars.${index}`)} gap="xs" align="flex-start" wrap="nowrap">
                <TextInput
                  placeholder="APP_KEY"
                  aria-label="Variable key"
                  style={{ flex: '0 0 38%' }}
                  {...form.getInputProps(`env_vars.${index}.key`)}
                />
                <PasswordInput
                  placeholder="value"
                  aria-label="Variable value"
                  style={{ flex: 1 }}
                  {...form.getInputProps(`env_vars.${index}.value`)}
                />
                <ActionIcon
                  color="red"
                  variant="subtle"
                  mt={4}
                  onClick={() => form.removeListItem('env_vars', index)}
                  aria-label="Remove variable"
                >
                  <IconTrash size={16} />
                </ActionIcon>
              </Group>
            ))}

            {form.values.env_vars.length === 0 && (
              <Text size="sm" c="dimmed">
                No environment variables yet.
              </Text>
            )}

            <Group justify="space-between" mt="xs">
              <Button
                variant="subtle"
                size="xs"
                leftSection={<IconPlus size={14} />}
                onClick={() => form.insertListItem('env_vars', { key: '', value: '' })}
              >
                Add variable
              </Button>
              <Group gap="sm">
                {form.isDirty() && (
                  <Text size="xs" c="dimmed">
                    Unsaved changes
                  </Text>
                )}
                <Button type="submit" loading={save.isPending} disabled={!form.isDirty()}>
                  Save
                </Button>
              </Group>
            </Group>

            <Text size="xs" c="dimmed">
              These values overwrite <code>shared/.env</code> on each deploy.
            </Text>
          </Stack>
        </form>
      </Stack>

      <ModalImport
        opened={importOpened}
        onClose={importModal.close}
        text={importText}
        setText={setImportText}
        onPickFile={pickImportFile}
        onImport={runImport}
      />
    </Paper>
  );
}

/** Paste-or-upload importer for an existing .env file. Kept inline — only used here. */
function ModalImport({
  opened,
  onClose,
  text,
  setText,
  onPickFile,
  onImport,
}: {
  opened: boolean;
  onClose: () => void;
  text: string;
  setText: (v: string) => void;
  onPickFile: (file: File | null) => void;
  onImport: () => void;
}) {
  return (
    <Modal opened={opened} onClose={onClose} title="Import from .env" size="lg">
      <Stack gap="md">
        <Text size="sm" c="dimmed">
          Paste the contents of a <code>.env</code> file, or pick one. Comments and blank lines are
          ignored; existing keys are overwritten, new keys appended. Review before saving.
        </Text>
        <FileInput
          placeholder="Choose a .env file"
          accept=".env,text/plain"
          clearable
          leftSection={<IconFileImport size={14} />}
          onChange={onPickFile}
        />
        <Textarea
          placeholder={'APP_ENV=production\nAPP_DEBUG=false\n# a comment\nMAIL_FROM="Acme Inc"'}
          autosize
          minRows={6}
          maxRows={16}
          value={text}
          onChange={(e) => setText(e.currentTarget.value)}
          styles={{ input: { fontFamily: 'var(--mantine-font-family-monospace)' } }}
        />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={onImport} disabled={text.trim().length === 0}>
            Import
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
