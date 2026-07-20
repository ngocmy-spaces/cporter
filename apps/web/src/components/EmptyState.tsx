import { Stack, Text } from '@mantine/core';
import type { ReactNode } from 'react';

/**
 * Consistent empty-state block for tables and panels: optional icon, a title,
 * an optional description, and an optional action (e.g. a "Create" button).
 */
export function EmptyState({
  icon,
  title,
  description,
  action,
}: {
  icon?: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <Stack align="center" gap="xs" py="xl" px="md">
      {icon}
      <Text fw={500}>{title}</Text>
      {description && (
        <Text size="sm" c="dimmed" ta="center" maw={420}>
          {description}
        </Text>
      )}
      {action}
    </Stack>
  );
}
