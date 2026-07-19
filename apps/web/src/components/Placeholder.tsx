import type { ReactNode } from 'react';
import { Paper, Stack, Text, Title } from '@mantine/core';

export function Placeholder({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <Stack gap="md">
      <Title order={2}>{title}</Title>
      <Paper withBorder p="lg" radius="md">
        <Text c="dimmed" size="sm">
          {children ?? 'Not implemented yet — see TASKS.md for the corresponding task.'}
        </Text>
      </Paper>
    </Stack>
  );
}
