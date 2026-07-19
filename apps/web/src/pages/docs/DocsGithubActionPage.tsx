import { Alert, Anchor, Card, List, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router-dom';
import { IconShieldLock } from '@tabler/icons-react';
import { CodeBlock } from '@/components/docs/CodeBlock';

const WORKFLOW_STEP = `- uses: ngocmy-spaces/cporter/packages/github-action@v1
  with:
    host: \${{ secrets.CPORTER_HOST }}
    token: \${{ secrets.CPORTER_TOKEN }}
    project: my-site
    artifact: ./out.zip
    version: \${{ github.sha }}`;

const FULL_WORKFLOW = `name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: npm ci && npm run build
      - name: Package artifact
        run: cd dist && zip -r ../out.zip .
      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: \${{ secrets.CPORTER_HOST }}
          token: \${{ secrets.CPORTER_TOKEN }}
          project: my-site
          artifact: ./out.zip
          version: \${{ github.sha }}`;

export function DocsGithubActionPage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>GitHub Action</Title>
        <Text c="dimmed" size="lg" mt={4}>
          A reusable composite action that wraps the CLI for GitHub Actions workflows.
        </Text>
      </div>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Add the step
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          Drop this step in after your build step produces an artifact:
        </Text>
        <CodeBlock label="yaml" code={WORKFLOW_STEP} />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Full example workflow
        </Title>
        <CodeBlock label="yaml · .github/workflows/deploy.yml" code={FULL_WORKFLOW} />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Storing credentials
        </Title>
        <Text size="sm" mb="sm">
          Store your cPorter host and API key as{' '}
          <Anchor href="https://docs.github.com/en/actions/security-guides/using-secrets-in-github-actions" target="_blank" rel="noreferrer">
            GitHub repository (or organization) secrets
          </Anchor>{' '}
          — never commit them:
        </Text>
        <List size="sm" spacing={4} mb="sm">
          <List.Item>
            <code>CPORTER_HOST</code> — your cPorter instance URL, e.g.
            https://deploy.example.com
          </List.Item>
          <List.Item>
            <code>CPORTER_TOKEN</code> — an API key with the <code>deploy</code> scope, created
            on the API Keys page
          </List.Item>
        </List>
        <Alert color="orange" variant="light" icon={<IconShieldLock size={16} />}>
          Use a dedicated API key per repository/environment so you can revoke access
          independently if a workflow is compromised.
        </Alert>
      </Card>

      <Text size="sm" c="dimmed">
        Prefer to script it yourself? See the <Anchor component={Link} to="/docs/api-reference">API reference</Anchor>{' '}
        or the <Anchor component={Link} to="/docs/quickstart">CLI quickstart</Anchor>.
      </Text>
    </Stack>
  );
}
