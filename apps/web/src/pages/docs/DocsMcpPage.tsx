import { Anchor, Card, List, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import { Link } from 'react-router-dom';
import { IconCircleCheck } from '@tabler/icons-react';
import { CodeBlock } from '@/components/docs/CodeBlock';

const MCP_CONFIG = `{
  "mcpServers": {
    "cporter": {
      "command": "npx",
      "args": ["-y", "@cporter/mcp"],
      "env": {
        "CPORTER_HOST": "https://deploy.example.com",
        "CPORTER_TOKEN": "cpk_…"
      }
    }
  }
}`;

const TOOLS = [
  { name: 'cporter_deploy', description: 'Upload an artifact and create a new release for a project.' },
  { name: 'cporter_status', description: 'Poll a deployment until it reaches a terminal status.' },
  { name: 'cporter_rollback', description: 'Roll a project back to the previous (or a specific) release.' },
  { name: 'cporter_whoami', description: 'Verify the configured API key and inspect its scopes.' },
];

export function DocsMcpPage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>AI agent (MCP)</Title>
        <Text c="dimmed" size="lg" mt={4}>
          cPorter ships a Model Context Protocol server so AI coding agents can deploy directly.
        </Text>
      </div>

      <Text size="sm">
        <code>@cporter/mcp</code> exposes the same deploy/status/rollback capabilities as the CLI
        as MCP tools, so an agent (Claude, Cursor, or any MCP-compatible client) can ship and
        verify a release without leaving the conversation.
      </Text>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Tools exposed
        </Title>
        <List
          spacing="sm"
          size="sm"
          icon={
            <ThemeIcon color="indigo" variant="light" size={20} radius="xl">
              <IconCircleCheck size={13} />
            </ThemeIcon>
          }
        >
          {TOOLS.map((tool) => (
            <List.Item key={tool.name}>
              <code>{tool.name}</code> — {tool.description}
            </List.Item>
          ))}
        </List>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Client configuration
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          Add this to your MCP client's config (e.g. Claude Desktop, Claude Code, or another
          MCP-compatible tool):
        </Text>
        <CodeBlock label="json" code={MCP_CONFIG} />
      </Card>

      <Text size="sm" c="dimmed">
        Same auth model as everywhere else — the token is a normal cPorter{' '}
        <Anchor component={Link} to="/docs/quickstart">
          API key
        </Anchor>
        , scoped to <code>deploy</code>, <code>read</code>, and/or <code>rollback</code>.
      </Text>
    </Stack>
  );
}
