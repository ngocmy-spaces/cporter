import { Alert, Button, Group, Loader, Stack, Text } from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';
import type { UseQueryResult } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { extractApiError } from '@/lib/feedback';

/**
 * Shared loading / error wrapper for any query-backed surface. Renders a spinner
 * (or a caller-supplied `loader`, e.g. a Skeleton) while loading, a retryable Alert
 * on error, and the children once data is ready. Keeps error handling consistent so
 * a failed fetch never masquerades as an empty state.
 */
export function PanelBody({
  query,
  errorTitle,
  children,
  loader,
}: {
  query: UseQueryResult<unknown, unknown>;
  errorTitle: string;
  children: ReactNode;
  loader?: ReactNode;
}) {
  if (query.isLoading) {
    return (
      loader ?? (
        <Group justify="center" p="xl">
          <Loader />
        </Group>
      )
    );
  }

  if (query.isError) {
    return (
      <Alert
        color="red"
        variant="light"
        icon={<IconAlertTriangle size={16} />}
        title={errorTitle}
        m="md"
      >
        <Stack gap="xs" align="flex-start">
          <Text size="sm">{extractApiError(query.error) ?? 'Something went wrong.'}</Text>
          <Button variant="light" size="xs" onClick={() => query.refetch()}>
            Retry
          </Button>
        </Stack>
      </Alert>
    );
  }

  return <>{children}</>;
}
