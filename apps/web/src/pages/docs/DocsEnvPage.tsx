import { Alert, Anchor, Card, Code, List, Stack, Table, Text, Title } from '@mantine/core';
import { Link } from 'react-router-dom';
import { IconAlertTriangle, IconLock, IconShieldCheck } from '@tabler/icons-react';
import { CodeBlock } from '@/components/docs/CodeBlock';

const MANAGED_ENV_HTTP = `# Read the current vars (admin session or admin-scoped key)
curl -H "Authorization: Bearer $CPORTER_TOKEN" \\
  https://deploy.example.com/api/projects/my-api/env

# Replace them (applied on the NEXT deploy)
curl -X PUT https://deploy.example.com/api/projects/my-api/env \\
  -H "Authorization: Bearer $CPORTER_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"env_vars":[{"key":"APP_ENV","value":"production"},{"key":"DB_HOST","value":"localhost"}]}'`;

const RENDERED_ENV = `# Managed by cPorter — do not edit; overwritten on each deploy.
APP_ENV="production"
DB_HOST="localhost"`;

export function DocsEnvPage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>Environment variables</Title>
        <Text c="dimmed" size="lg" mt={4}>
          How cPorter provisions your app&apos;s <Code>.env</Code> — managed for you, or seeded from the
          artifact.
        </Text>
      </div>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">Where <Code>.env</Code> lives</Title>
        <Text size="sm">
          Your <Code>.env</Code> is a <b>shared file</b>: it lives once at{' '}
          <Code>&lt;base_path&gt;/shared/.env</Code> and is symlinked into every release as{' '}
          <Code>.env</Code> at the release root. That&apos;s why it survives deploys and rollbacks — the
          artifact never contains the real secrets. You have two ways to populate it.
        </Text>
      </Card>

      {/* ── Option A: managed ────────────────────────────────────────── */}
      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          <IconShieldCheck size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />
          Option A — let cPorter manage it (recommended)
        </Title>
        <Text size="sm" mb="sm">
          Set your variables on the project (<b>Project → Environment</b> in the UI, or the API below).
          They are stored <b>encrypted at rest</b> and rendered into <Code>shared/.env</Code> on the
          next deploy, before the release is linked:
        </Text>
        <CodeBlock label="rendered shared/.env" code={RENDERED_ENV} />
        <List size="sm" spacing={4} mt="sm">
          <List.Item>Values are encrypted with the app key; they are never returned by the project
            list/detail endpoints and audit logs record key <em>names</em> only, never values.</List.Item>
          <List.Item>The first line is an ownership marker. cPorter only ever overwrites a file it
            owns — a <Code>shared/.env</Code> you created by hand is left untouched (see below).</List.Item>
          <List.Item>Changes apply on the <b>next deploy</b>, not immediately.</List.Item>
        </List>
        <CodeBlock label="bash · manage via API (admin-only)" code={MANAGED_ENV_HTTP} />
        <Alert color="blue" variant="light" icon={<IconLock size={16} />} mt="sm">
          The env endpoints are <b>admin-only</b> — a deploy-scoped API key (the one CI uses) cannot read
          or write them. This keeps secrets out of your CI token&apos;s blast radius.
        </Alert>
      </Card>

      {/* ── Option B: bundled ────────────────────────────────────────── */}
      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">Option B — seed it from the artifact</Title>
        <Text size="sm" mb="sm">
          If you&apos;d rather manage <Code>.env</Code> yourself, include one in the zip and list{' '}
          <Code>.env</Code> as a <Code>file</Code>-type entry in the project&apos;s{' '}
          <Code>shared_paths</Code>. On the <b>first</b> deploy the bundled <Code>.env</Code> is moved
          into <Code>shared/</Code> to seed it. On every deploy after that, the shared copy wins and the
          zipped one is discarded — so editing it means editing <Code>shared/.env</Code> on the server.
        </Text>
        <Alert color="orange" variant="light" icon={<IconAlertTriangle size={16} />}>
          Don&apos;t mix both approaches. If cPorter is managing env vars, it writes{' '}
          <Code>shared/.env</Code> <em>before</em> the artifact is linked, so a <Code>.env</Code> you
          also ship in the zip is ignored. Pick one source of truth per project.
        </Alert>
      </Card>

      {/* ── Ownership / takeover ─────────────────────────────────────── */}
      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">Ownership &amp; taking over an existing file</Title>
        <Table withTableBorder striped verticalSpacing="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>State of <Code>shared/.env</Code></Table.Th>
              <Table.Th>What a managed deploy does</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            <Table.Tr>
              <Table.Td>Absent</Table.Td>
              <Table.Td>Written from your managed vars (marker added).</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>Present, has the cPorter marker</Table.Td>
              <Table.Td>Overwritten from your managed vars.</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>Present, <b>no</b> marker (hand-created / bundled)</Table.Td>
              <Table.Td>Left untouched; the deploy records a warning. Use <b>Adopt</b> to take it over.</Table.Td>
            </Table.Tr>
          </Table.Tbody>
        </Table>
        <Text size="sm" c="dimmed" mt="sm">
          To hand cPorter control of an unmanaged file, use <b>Adopt</b> in the Environment panel (or{' '}
          <Code>POST /projects/&#123;slug&#125;/env/adopt</Code>): it force-writes <Code>shared/.env</Code>{' '}
          from your current managed vars and stamps the marker, so subsequent deploys keep it in sync.
        </Text>
      </Card>

      <Text size="sm" c="dimmed">
        See also: <Anchor component={Link} to="/docs/artifact">Artifact &amp; packaging</Anchor> for what
        to (not) ship in the zip, and the{' '}
        <Anchor component={Link} to="/docs/api-reference">API reference</Anchor> for the full env
        contract.
      </Text>
    </Stack>
  );
}
