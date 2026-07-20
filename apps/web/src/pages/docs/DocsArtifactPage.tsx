import { Alert, Anchor, Badge, Card, Code, List, Stack, Table, Text, Title } from '@mantine/core';
import { Link } from 'react-router-dom';
import { IconAlertTriangle, IconBulb, IconPackage } from '@tabler/icons-react';
import { CodeBlock } from '@/components/docs/CodeBlock';

// ── Per-type packaging examples ──────────────────────────────────────────────

const STATIC_WORKFLOW = `name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }
      - run: npm ci && npm run build      # → produces ./dist

      # index.html must sit at the ZIP ROOT (docroot_subpath is empty for static).
      - name: Package artifact
        run: cd dist && zip -r ../out.zip .

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: \${{ secrets.CPORTER_HOST }}
          token: \${{ secrets.CPORTER_TOKEN }}
          project: my-site
          artifact: ./out.zip
          version: \${{ github.sha }}`;

const LARAVEL_WORKFLOW = `name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2

      # Build the frontend into Laravel's public/ (adjust to your setup).
      - run: npm ci && npm run build

      # Ship vendor/ pre-built — the safest default on locked-down cPanel.
      - run: composer install --no-dev --optimize-autoloader --no-interaction

      # public/ must be at the ZIP ROOT + set the project's docroot_subpath = public.
      # Exclude .env (managed by cPorter), .git, tests, and local storage state.
      - name: Package artifact
        run: |
          zip -r ../out.zip . \\
            -x '.git/*' '.env' 'tests/*' 'node_modules/*' \\
               'storage/logs/*' 'storage/framework/cache/*' \\
               'storage/framework/sessions/*' 'storage/framework/views/*'
          mv ../out.zip ./out.zip

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: \${{ secrets.CPORTER_HOST }}
          token: \${{ secrets.CPORTER_TOKEN }}
          project: my-api
          artifact: ./out.zip
          version: \${{ github.sha }}`;

const NODE_WORKFLOW = `name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }

      - run: npm ci && npm run build       # → compiled output (e.g. ./dist)

      # Production deps only. Bundle node_modules when the host has no shell to
      # run \`npm ci\` itself (see "Dependencies" below).
      - run: npm ci --omit=dev

      # cPanel's Node (Passenger) expects the app entrypoint at the docroot.
      # Ship the built app + node_modules; the app root is the ZIP ROOT.
      - name: Package artifact
        run: zip -r out.zip . -x '.git/*' '.env' 'src/*'

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: \${{ secrets.CPORTER_HOST }}
          token: \${{ secrets.CPORTER_TOKEN }}
          project: my-node-app
          artifact: ./out.zip
          version: \${{ github.sha }}`;

const WORDPRESS_WORKFLOW = `name: Deploy
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }

      # If you use Composer for plugins/mu-plugins:
      - run: composer install --no-dev --optimize-autoloader --no-interaction

      # WordPress serves from the site root: index.php + wp-content at the ZIP ROOT
      # (docroot_subpath empty). Keep wp-content/uploads and wp-config.php as SHARED
      # paths so media + secrets survive releases — don't ship them here.
      - name: Package artifact
        run: zip -r out.zip . -x '.git/*' 'wp-content/uploads/*' 'wp-config.php'

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: \${{ secrets.CPORTER_HOST }}
          token: \${{ secrets.CPORTER_TOKEN }}
          project: my-blog
          artifact: ./out.zip
          version: \${{ github.sha }}`;

const HOOK_EXAMPLE = `# Project → hooks (configured in the cPorter UI, run verbatim in the release dir)
pre_activate:
  - composer install --no-dev --optimize-autoloader
post_activate:
  - php artisan migrate --force
  - php artisan config:cache`;

export function DocsArtifactPage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>Artifact &amp; packaging</Title>
        <Text c="dimmed" size="lg" mt={4}>
          How to build the <Code>.zip</Code> you hand to cPorter — the contract, then a copy-paste
          recipe per project type.
        </Text>
      </div>

      {/* ── The contract ─────────────────────────────────────────────── */}
      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          <IconPackage size={18} style={{ verticalAlign: '-3px', marginRight: 6 }} />
          The contract — what the zip must look like
        </Title>
        <List size="sm" spacing="sm">
          <List.Item>
            <b>The zip root becomes the release root.</b> cPorter extracts your zip{' '}
            <em>verbatim</em> into <Code>releases/&lt;id&gt;/</Code> — there is no top-level folder
            stripping. Do <b>not</b> wrap everything in a single parent folder (e.g. <Code>myapp/…</Code>){' '}
            unless you also set that folder as the docroot.
          </List.Item>
          <List.Item>
            <b>The webserver serves <Code>current/&lt;docroot_subpath&gt;</Code>.</b> Whatever must be
            web-served has to sit at <Code>docroot_subpath</Code> inside the zip — <Code>public/</Code>{' '}
            for Laravel, the zip root itself for a static site. Set <Code>docroot_subpath</Code> on the
            project to match.
          </List.Item>
          <List.Item>
            <b>Size &amp; count limits:</b> ≤ 256&nbsp;MB per single upload (larger uploads chunk
            automatically), ≤ 50,000 files, ≤ 1&nbsp;GB uncompressed. Don&apos;t ship{' '}
            <Code>.git/</Code>, editor junk, or test fixtures.
          </List.Item>
          <List.Item>
            <b>cPorter never touches your vhost.</b> You point cPanel&apos;s Document Root at{' '}
            <Code>…/current/&lt;docroot_subpath&gt;</Code> once — see{' '}
            <Anchor component={Link} to="/docs/cpanel-setup">cPanel setup</Anchor>.
          </List.Item>
        </List>
      </Card>

      {/* ── Dependencies ─────────────────────────────────────────────── */}
      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Dependencies: <Code>vendor/</Code> and <Code>node_modules/</Code>
        </Title>
        <Text size="sm" mb="sm">
          Two ways to get server-side dependencies in place. Pick based on whether your host allows
          shell execution.
        </Text>

        <Text size="sm" fw={600} mt="sm">
          Option A — run a hook on the host (preferred when shell is available)
        </Text>
        <Text size="sm" c="dimmed" mb="xs">
          A project can run shell commands at two phases — <Code>pre_activate</Code> (before the release
          goes live) and <Code>post_activate</Code> (after). They run verbatim in the release directory,
          so <Code>composer install</Code>, <Code>npm ci</Code>, and <Code>php artisan migrate</Code>{' '}
          all work. Configure them on the project in the cPorter UI:
        </Text>
        <CodeBlock label="project hooks" code={HOOK_EXAMPLE} />
        <Text size="sm" c="dimmed" mt="xs">
          Hooks only run if the host exposes a shell and the binary is detected. cPorter probes{' '}
          <Code>php</Code>, <Code>composer</Code>, <Code>node</Code>, <Code>npm</Code>, and{' '}
          <Code>python3</Code> in the cron shell — check{' '}
          <b>Admin → System</b> to see what&apos;s available on your host.
        </Text>

        <Text size="sm" fw={600} mt="md">
          Option B — bundle them into the zip (the reliable fallback)
        </Text>
        <Text size="sm" c="dimmed">
          Many cPanel plans block shell execution entirely (<Code>proc_open</Code>/<Code>exec</Code>{' '}
          disabled). There, hooks fail and the deploy is aborted. The robust default is to install
          dependencies <b>in CI</b> and ship them inside the artifact — no hooks needed. That&apos;s
          what every recipe below does with <Code>composer install --no-dev</Code> /{' '}
          <Code>npm ci --omit=dev</Code> before zipping.
        </Text>
        <Alert color="orange" variant="light" icon={<IconAlertTriangle size={16} />} mt="sm">
          Watch the inode limit on shared hosting. <Code>node_modules/</Code> can be tens of thousands
          of files — only bundle it for a <b>running Node app</b>. Static/SPA sites ship the{' '}
          <em>built output</em> (e.g. <Code>dist/</Code>), never <Code>node_modules/</Code>.
        </Alert>
      </Card>

      {/* ── Env ──────────────────────────────────────────────────────── */}
      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Configuration &amp; secrets (<Code>.env</Code>)
        </Title>
        <Text size="sm">
          Don&apos;t bake secrets into the artifact. cPorter can manage your environment for you and
          render it into a shared <Code>.env</Code> that persists across releases — or you can ship a{' '}
          <Code>.env</Code> to seed it once. Both paths, and how they interact, are covered in{' '}
          <Anchor component={Link} to="/docs/env">Environment variables</Anchor>.
        </Text>
      </Card>

      {/* ── Per-type layout table ────────────────────────────────────── */}
      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Layout by project type
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          There are no automatic per-type defaults — set <Code>docroot_subpath</Code> and{' '}
          <Code>shared_paths</Code> on the project to match how you zip. Recommended conventions:
        </Text>
        <Table.ScrollContainer minWidth={560}>
          <Table withTableBorder striped verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Type</Table.Th>
                <Table.Th>docroot_subpath</Table.Th>
                <Table.Th>At the zip root</Table.Th>
                <Table.Th>Recommended shared_paths</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              <Table.Tr>
                <Table.Td><Badge variant="light">static</Badge></Table.Td>
                <Table.Td><Text c="dimmed" size="sm">(empty)</Text></Table.Td>
                <Table.Td><Code>index.html</Code> + assets</Table.Td>
                <Table.Td><Text c="dimmed" size="sm">—</Text></Table.Td>
              </Table.Tr>
              <Table.Tr>
                <Table.Td><Badge variant="light">laravel</Badge></Table.Td>
                <Table.Td><Code>public</Code></Table.Td>
                <Table.Td>app root (<Code>public/</Code>, <Code>vendor/</Code>, <Code>artisan</Code>…)</Table.Td>
                <Table.Td><Code>.env</Code> (file), <Code>storage</Code> (dir)</Table.Td>
              </Table.Tr>
              <Table.Tr>
                <Table.Td><Badge variant="light">php</Badge></Table.Td>
                <Table.Td><Code>public</Code> or (empty)</Table.Td>
                <Table.Td>your front-controller / index</Table.Td>
                <Table.Td><Code>.env</Code> (file), uploads dir</Table.Td>
              </Table.Tr>
              <Table.Tr>
                <Table.Td><Badge variant="light">node</Badge></Table.Td>
                <Table.Td>per Passenger config</Table.Td>
                <Table.Td>built app + <Code>node_modules/</Code> + entrypoint</Table.Td>
                <Table.Td><Code>.env</Code> (file)</Table.Td>
              </Table.Tr>
              <Table.Tr>
                <Table.Td><Badge variant="light">wordpress</Badge></Table.Td>
                <Table.Td><Text c="dimmed" size="sm">(empty)</Text></Table.Td>
                <Table.Td><Code>index.php</Code> + <Code>wp-content/</Code></Table.Td>
                <Table.Td><Code>wp-config.php</Code> (file), <Code>wp-content/uploads</Code> (dir)</Table.Td>
              </Table.Tr>
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
        <Alert color="blue" variant="light" icon={<IconBulb size={16} />} mt="sm">
          Anything listed in <Code>shared_paths</Code> is symlinked from <Code>shared/</Code> into every
          release, so it survives deploys and rollbacks. On the first deploy a copy shipped in the zip{' '}
          <em>seeds</em> the shared location; after that the shared copy always wins and the zipped one
          is discarded. A <Code>file</Code>-type shared path that doesn&apos;t exist yet must be created
          on the server first (cPorter won&apos;t fabricate a secret).
        </Alert>
      </Card>

      {/* ── Full recipes ─────────────────────────────────────────────── */}
      <div>
        <Title order={3} mb="xs">Full recipes</Title>
        <Text size="sm" c="dimmed">
          Drop-in <Code>.github/workflows/deploy.yml</Code> per type. Store <Code>CPORTER_HOST</Code>{' '}
          and <Code>CPORTER_TOKEN</Code> as repository secrets first (see{' '}
          <Anchor component={Link} to="/docs/github-action">GitHub Action</Anchor>).
        </Text>
      </div>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">Static site / SPA</Title>
        <Text size="sm" c="dimmed" mb="sm">
          Ship the build output only. <Code>index.html</Code> lands at the release root; leave{' '}
          <Code>docroot_subpath</Code> empty.
        </Text>
        <CodeBlock label="yaml · .github/workflows/deploy.yml" code={STATIC_WORKFLOW} />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">Laravel / PHP</Title>
        <Text size="sm" c="dimmed" mb="sm">
          Build assets, install <Code>vendor/</Code> in CI, zip the app root with <Code>public/</Code>{' '}
          at the top. Set <Code>docroot_subpath = public</Code>. Manage <Code>.env</Code> via cPorter
          (excluded here). Migrations belong in a <Code>post_activate</Code> hook if your host has a
          shell.
        </Text>
        <CodeBlock label="yaml · .github/workflows/deploy.yml" code={LARAVEL_WORKFLOW} />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">Node app</Title>
        <Text size="sm" c="dimmed" mb="sm">
          Requires Node (Passenger) on the host. Bundle <Code>node_modules/</Code> (prod-only) unless a
          hook can run <Code>npm ci</Code> server-side. Point <Code>docroot_subpath</Code> at your
          Passenger entrypoint.
        </Text>
        <CodeBlock label="yaml · .github/workflows/deploy.yml" code={NODE_WORKFLOW} />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">WordPress</Title>
        <Text size="sm" c="dimmed" mb="sm">
          Serve from the site root. Keep <Code>wp-config.php</Code> and <Code>wp-content/uploads</Code>{' '}
          as shared paths so secrets and media persist across releases — don&apos;t ship them in the zip.
        </Text>
        <CodeBlock label="yaml · .github/workflows/deploy.yml" code={WORDPRESS_WORKFLOW} />
      </Card>

      <Text size="sm" c="dimmed">
        Next: wire it into CI with the{' '}
        <Anchor component={Link} to="/docs/github-action">GitHub Action</Anchor>, or deploy the same
        zip by hand with the <Anchor component={Link} to="/docs/quickstart">CLI</Anchor>.
      </Text>
    </Stack>
  );
}
