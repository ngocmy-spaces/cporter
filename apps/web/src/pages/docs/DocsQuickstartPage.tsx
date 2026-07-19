import { Alert, Anchor, Badge, Card, List, Stack, Table, Text, Title } from '@mantine/core';
import { Link } from 'react-router-dom';
import { IconBulb } from '@tabler/icons-react';
import { CodeBlock } from '@/components/docs/CodeBlock';

export function DocsQuickstartPage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>Quickstart (CLI)</Title>
        <Text c="dimmed" size="lg" mt={4}>
          The primary way to deploy to cPorter — one command, from your machine or CI.
        </Text>
      </div>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          1. Install
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          No install needed — run it directly with npx, or install it globally.
        </Text>
        <Stack gap="sm">
          <CodeBlock label="bash · no install" code="npx @cporter/cli deploy ./out.zip --project my-site --version v1.2.3" />
          <CodeBlock label="bash · global install" code="pnpm add -g @cporter/cli" />
        </Stack>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          2. Get an API key
        </Title>
        <Text size="sm" mb="sm">
          An admin creates a key on the <Badge variant="light">API Keys</Badge> page inside
          cPorter. Each key has one or more scopes:
        </Text>
        <List size="sm" spacing={4} mb="sm">
          <List.Item>
            <code>deploy</code> — create deployments and rollbacks
          </List.Item>
          <List.Item>
            <code>read</code> — read project, deployment, and release status
          </List.Item>
          <List.Item>
            <code>rollback</code> — trigger a rollback to a previous release
          </List.Item>
        </List>
        <Alert color="orange" variant="light" icon={<IconBulb size={16} />}>
          The plaintext key (<code>cpk_…</code>) is shown once, at creation time. Store it
          securely — cPorter only keeps a hash of it afterwards.
        </Alert>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          3. Configure via environment
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          The CLI reads these environment variables (or equivalent flags):
        </Text>
        <Table withTableBorder striped>
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Variable</Table.Th>
              <Table.Th>Purpose</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            <Table.Tr>
              <Table.Td>
                <code>CPORTER_HOST</code>
              </Table.Td>
              <Table.Td>Base URL of your cPorter instance, e.g. https://deploy.example.com</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>
                <code>CPORTER_TOKEN</code>
              </Table.Td>
              <Table.Td>
                The API key (<code>cpk_…</code>) from step 2
              </Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>
                <code>CPORTER_PROJECT</code>
              </Table.Td>
              <Table.Td>Default project slug, so you can omit --project on each call</Table.Td>
            </Table.Tr>
          </Table.Tbody>
        </Table>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          4. Deploy
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          By default the CLI waits for the deployment to finish and exits non-zero on failure —
          safe to use as a CI gate.
        </Text>
        <CodeBlock
          label="bash"
          code="cporter deploy ./out.zip --project my-site --version v1.2.3"
        />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          5. Other commands
        </Title>
        <Stack gap="sm">
          <CodeBlock label="check status of a deployment" code="cporter status <id> --wait" />
          <CodeBlock label="roll back to a specific release" code="cporter rollback --release-id <n>" />
          <CodeBlock label="verify your token and scopes" code="cporter whoami" />
        </Stack>
      </Card>

      <Text size="sm" c="dimmed">
        Not using the CLI? See the <Anchor component={Link} to="/docs/api-reference">API reference</Anchor>{' '}
        for the raw HTTP contract, or the{' '}
        <Anchor component={Link} to="/docs/github-action">GitHub Action</Anchor> for CI workflows.
      </Text>
    </Stack>
  );
}
