import { Alert, Anchor, Badge, Card, List, Stack, Table, Text, Timeline, Title } from '@mantine/core';
import { Link } from 'react-router-dom';
import { IconAlertTriangle, IconInfoCircle } from '@tabler/icons-react';
import { CodeBlock } from '@/components/docs/CodeBlock';

const BUILD = `# in the repo root — build with PHP 8.3 to match the host
cd apps/api && composer install --no-dev --optimize-autoloader && cd ../..
pnpm install
pnpm build:artifact        # builds web → copies into apps/api/public → zips apps/api
# → build/out/cporter-<version>.zip  (+ prints sha256)`;

const EXTRACT = `cd ~/cporter.domain
mkdir -p releases shared

# Upload cporter-<version>.zip here (File Manager or scp), then extract into a release:
REL=$(date +%Y%m%d_%H%M%S)
mkdir -p releases/$REL
unzip -q cporter-*.zip -d releases/$REL      # no unzip? extract via File Manager`;

const ENV = `APP_NAME=cPorter
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://cporter.domain

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=USER_cporter
DB_USERNAME=USER_cporter
DB_PASSWORD=your-db-password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

# Jail: absolute paths cPorter may deploy into. Your sites live under /home/USER.
CPORTER_ALLOWED_BASE_PATHS=/home/USER
CPORTER_COMMAND_DRIVER=cron-worker

# First admin (created by the seeder). Change the password after first login.
CPORTER_ADMIN_EMAIL=you@example.com
CPORTER_ADMIN_PASSWORD=change-me-now`;

const ACTIVATE = `cd ~/cporter.domain
PHP=/opt/cpanel/ea-php83/root/usr/bin/php

# Link shared files (persist across releases)
ln -sfn ../../shared/.env      releases/$REL/.env
rm -rf releases/$REL/storage && ln -sfn ../../shared/storage releases/$REL/storage

# Key, migrate + seed, cache
cd releases/$REL
$PHP artisan key:generate --force
$PHP artisan migrate --force --seed
$PHP artisan config:cache

# Activate this release (atomic symlink)
cd ~/cporter.domain
ln -sfn releases/$REL current`;

const CRON = `* * * * * cd /home/USER/cporter.domain/current && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1`;

interface FixRow {
  symptom: string;
  fix: string;
}

const TROUBLESHOOTING: FixRow[] = [
  { symptom: 'Domain 404 / blank', fix: 'Document Root must be …/current/public and the current symlink must exist.' },
  { symptom: '500 on every page', fix: 'shared/.env missing/misconfigured or APP_KEY empty. Check storage/logs.' },
  { symptom: 'Deploy stuck queued / hooks_pending', fix: "The cron isn't running — verify the cron line and the PHP CLI path." },
  { symptom: 'Hooks say "run manually"', fix: 'CLI proc_open is disabled even in cron → run the hook commands over Terminal, or ask the host to allow it.' },
  { symptom: 'base_path must be within an allowed base path', fix: "Add the site's parent to CPORTER_ALLOWED_BASE_PATHS." },
  { symptom: 'Artifact upload rejected', fix: 'Raise upload_max_filesize / post_max_size (MultiPHP INI Editor) or use chunked upload.' },
];

export function DocsCpanelSetupPage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>cPanel setup</Title>
        <Text c="dimmed" size="lg" mt={4}>
          Install cPorter itself on a cPanel account — the one-time bootstrap before it can deploy
          your other sites.
        </Text>
      </div>

      <Text size="sm">
        cPorter installs like a normal web app on one domain (<code>cporter.domain</code>) and then
        deploys your <em>other</em> domains under the same cPanel account. It needs <strong>no
        root</strong>: cPanel runs PHP as your account user, so it can manage sibling folders.
        Replace <code>USER</code> with your cPanel username and <code>cporter.domain</code> with your
        control-panel domain throughout.
      </Text>

      <Alert color="blue" variant="light" icon={<IconInfoCircle size={16} />}>
        This is a chicken-and-egg step: cPorter can't deploy itself before it exists, so the first
        install is manual. Once it's running, it can deploy (and upgrade) itself like any other project.
      </Alert>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          1. Prerequisites (in cPanel)
        </Title>
        <List size="sm" spacing="sm">
          <List.Item>
            <strong>Subdomain</strong> — create <code>cporter.domain</code> and set its Document Root to{' '}
            <code>cporter.domain/current/public</code>. It 404s until the first release exists — that's fine.
          </List.Item>
          <List.Item>
            <strong>PHP 8.3</strong> — MultiPHP Manager → set <code>cporter.domain</code> to{' '}
            <code>ea-php83</code>. Note the CLI path (usually{' '}
            <code>/opt/cpanel/ea-php83/root/usr/bin/php</code>).
          </List.Item>
          <List.Item>
            <strong>MySQL</strong> — create a database (<code>USER_cporter</code>) + user, and grant the
            user <strong>ALL PRIVILEGES</strong> on it.
          </List.Item>
          <List.Item>
            <strong>A shell</strong> for the one-time bootstrap — cPanel <em>Terminal</em> or SSH. No
            shell? See the no-shell note at the bottom.
          </List.Item>
        </List>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          2. Build the artifact
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          Locally or in CI. Build with PHP 8.3 to match the host (the shipped{' '}
          <code>.github/workflows/deploy.yml</code> already does).
        </Text>
        <CodeBlock label="bash" code={BUILD} />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          3. First install (bootstrap)
        </Title>
        <Text size="sm" mb="sm">
          Open Terminal / SSH. Create the release layout and extract the artifact:
        </Text>
        <CodeBlock label="bash · extract into a release" code={EXTRACT} />
        <Text size="sm" mt="md" mb="sm">
          Create <code>~/cporter.domain/shared/.env</code> (production — keep secrets here, never in the
          artifact):
        </Text>
        <CodeBlock label="dotenv · shared/.env" code={ENV} />
        <Text size="sm" mt="md" mb="sm">
          Link shared files, generate the key, migrate + seed, then activate the release:
        </Text>
        <CodeBlock label="bash · migrate & activate" code={ACTIVATE} />
        <Text size="sm" mt="md">
          Confirm the Document Root is <code>cporter.domain/current/public</code>, then open{' '}
          <strong>https://cporter.domain</strong> and log in with the admin from <code>.env</code>. ✅
        </Text>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          4. Cron (required)
        </Title>
        <Text size="sm" mb="sm">
          Web PHP on the target host can't run shell commands, so a single cron drives Laravel hooks,
          the queue worker, and housekeeping. In <em>cPanel ▸ Cron Jobs</em>, add — every minute:
        </Text>
        <CodeBlock label="cron" code={CRON} />
        <Text size="xs" c="dimmed" mt="sm">
          Fans out to <code>cporter:run-jobs</code> (finalize Laravel deploys), <code>queue:work</code>{' '}
          (artifact extraction), and <code>cporter:housekeep</code>.
        </Text>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="sm">
          5. Point your other domains at their release
        </Title>
        <Text size="sm" mb="md">
          For each site cPorter will deploy, set that domain's Document Root:
        </Text>
        <Timeline active={-1} bulletSize={20} lineWidth={2}>
          <Timeline.Item title="Laravel">
            <Text size="sm" c="dimmed">
              <code>&lt;site&gt;/current/public</code>
            </Text>
          </Timeline.Item>
          <Timeline.Item title="Static / WordPress / plain PHP">
            <Text size="sm" c="dimmed">
              <code>&lt;site&gt;/current</code>
            </Text>
          </Timeline.Item>
        </Timeline>
        <Text size="xs" c="dimmed" mt="sm">
          cPorter creates <code>releases/</code>, <code>shared/</code>, and <code>current</code> inside
          the site folder on the first deploy.
        </Text>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          6. Register a project and deploy
        </Title>
        <List size="sm" spacing="sm" type="ordered">
          <List.Item>
            In the cPorter UI → <Badge variant="light">Projects ▸ New project</Badge>: set{' '}
            <code>base_path=/home/USER/&lt;site&gt;.domain</code>, <code>type</code>, and (for Laravel){' '}
            <code>docroot_subpath=public</code>, <code>shared_paths=[".env","storage"]</code>,{' '}
            <code>php_binary</code>, and hooks.
          </List.Item>
          <List.Item>
            <Badge variant="light">API Keys ▸ New key</Badge> with the <code>deploy</code> +{' '}
            <code>read</code> scopes → copy the token.
          </List.Item>
          <List.Item>
            Deploy with the <Anchor component={Link} to="/docs/quickstart">CLI</Anchor>, the{' '}
            <Anchor component={Link} to="/docs/github-action">GitHub Action</Anchor>, or the raw{' '}
            <Anchor component={Link} to="/docs/api-reference">HTTP API</Anchor>.
          </List.Item>
        </List>
        <Text size="sm" c="dimmed" mt="sm">
          Laravel deploys return <code>hooks_pending</code> and the cron finalizes them within ~1
          minute; static deploys finish immediately.
        </Text>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="sm">
          Troubleshooting
        </Title>
        <Table.ScrollContainer minWidth={520}>
          <Table verticalSpacing="sm" withTableBorder striped>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Symptom</Table.Th>
                <Table.Th>Fix</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {TROUBLESHOOTING.map((row) => (
                <Table.Tr key={row.symptom}>
                  <Table.Td>{row.symptom}</Table.Td>
                  <Table.Td>{row.fix}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Card>

      <Alert color="orange" variant="light" icon={<IconAlertTriangle size={16} />}>
        <strong>No Terminal / SSH?</strong> Run the one-time <code>migrate --seed</code> via a temporary
        cron job, set a fixed <code>APP_KEY</code> in <code>shared/.env</code> yourself, and create the
        folders + symlinks via File Manager. Delete the temporary cron once it has run.
      </Alert>
    </Stack>
  );
}
