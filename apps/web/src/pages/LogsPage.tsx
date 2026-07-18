import { Anchor, Paper, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router-dom';

export function LogsPage() {
  return (
    <Stack gap="md">
      <div>
        <Title order={2}>Logs</Title>
        <Text c="dimmed" size="sm">
          cPorter has no separate log viewer — per-deployment output lives with the deployment
          itself.
        </Text>
      </div>

      <Paper withBorder p="lg" radius="md">
        <Text size="sm">
          Open the{' '}
          <Anchor component={Link} to="/deployments">
            Deployments
          </Anchor>{' '}
          page and click any row to see that run&apos;s step-by-step timeline (including any
          error output) in the drawer.
        </Text>
      </Paper>
    </Stack>
  );
}
