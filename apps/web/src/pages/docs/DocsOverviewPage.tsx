import { Card, Group, SimpleGrid, Stack, Text, ThemeIcon, Timeline, Title } from '@mantine/core';
import { Link } from 'react-router-dom';
import {
  IconApi,
  IconBrandGithub,
  IconCircleCheck,
  IconFileZip,
  IconHash,
  IconRobot,
  IconRocket,
  IconTerminal2,
} from '@tabler/icons-react';

const INTEGRATIONS = [
  {
    to: '/docs/quickstart',
    icon: IconTerminal2,
    title: 'CLI',
    description: 'The primary path — deploy from any shell or CI with a single command.',
  },
  {
    to: '/docs/github-action',
    icon: IconBrandGithub,
    title: 'GitHub Action',
    description: 'A reusable composite action that wraps the CLI for GitHub workflows.',
  },
  {
    to: '/docs/mcp',
    icon: IconRobot,
    title: 'AI agent (MCP)',
    description: 'An MCP server so AI coding agents can deploy and check status directly.',
  },
  {
    to: '/docs/api-reference',
    icon: IconApi,
    title: 'Raw HTTP API',
    description: 'The underlying contract used by every integration above.',
  },
];

export function DocsOverviewPage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>cPorter</Title>
        <Text c="dimmed" size="lg" mt={4}>
          A self-hosted deploy orchestrator for cPanel shared hosting.
        </Text>
      </div>

      <Text size="sm">
        cPorter installs as a normal web app on your cPanel account and manages atomic releases
        for your other domains and applications. You upload a built artifact (a <code>.zip</code>{' '}
        file), cPorter verifies its SHA-256 checksum, creates a release, and activates it — with
        instant rollback to any previous release if something goes wrong.
      </Text>

      <Card withBorder radius="md" p="lg">
        <Text fw={600} mb="md">
          How a deployment works
        </Text>
        <Timeline active={4} bulletSize={26} lineWidth={2}>
          <Timeline.Item bullet={<IconFileZip size={14} />} title="Upload artifact">
            <Text c="dimmed" size="sm">
              Send a built <code>.zip</code> (via CLI, GitHub Action, MCP, or a raw API call).
            </Text>
          </Timeline.Item>
          <Timeline.Item bullet={<IconHash size={14} />} title="Verify SHA-256">
            <Text c="dimmed" size="sm">
              cPorter checks the artifact's checksum against what you declared before accepting it.
            </Text>
          </Timeline.Item>
          <Timeline.Item bullet={<IconRocket size={14} />} title="Create release">
            <Text c="dimmed" size="sm">
              The artifact is extracted into a new, isolated release directory.
            </Text>
          </Timeline.Item>
          <Timeline.Item bullet={<IconCircleCheck size={14} />} title="Activate">
            <Text c="dimmed" size="sm">
              The project's <code>current</code> symlink is swapped atomically to the new release.
            </Text>
          </Timeline.Item>
          <Timeline.Item title="Rollback anytime">
            <Text c="dimmed" size="sm">
              Point <code>current</code> back at any previous release in one call.
            </Text>
          </Timeline.Item>
        </Timeline>
      </Card>

      <div>
        <Title order={3} mb="sm">
          Ways to integrate
        </Title>
        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          {INTEGRATIONS.map((item) => {
            const Icon = item.icon;
            return (
              <Card
                key={item.to}
                component={Link}
                to={item.to}
                withBorder
                radius="md"
                p="lg"
                style={{ textDecoration: 'none', color: 'inherit' }}
              >
                <Group gap="sm" mb="xs">
                  <ThemeIcon variant="light" size={36} radius="md">
                    <Icon size={20} stroke={1.5} />
                  </ThemeIcon>
                  <Text fw={600}>{item.title}</Text>
                </Group>
                <Text c="dimmed" size="sm">
                  {item.description}
                </Text>
              </Card>
            );
          })}
        </SimpleGrid>
      </div>
    </Stack>
  );
}
