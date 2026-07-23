import { Alert, Badge, Card, Group, Stack, Table, Text, Timeline, Title } from '@mantine/core';
import { IconAlertCircle } from '@tabler/icons-react';
import { CodeBlock } from '@/components/docs/CodeBlock';

const CURL_EXAMPLE = `SHA256=$(sha256sum out.zip | awk '{print $1}')

curl -X POST "https://deploy.example.com/api/v1/projects/my-site/deployments" \\
  -H "Authorization: Bearer cpk_…" \\
  -H "Idempotency-Key: $(uuidgen)" \\
  -F "artifact=@out.zip" \\
  -F "sha256=$SHA256" \\
  -F "version=v1.2.3"`;

const CHUNK_CREATE = `POST /projects/{slug}/artifacts/uploads
→ 201 { "data": { "upload_id": "..." } }`;

const CHUNK_PUT = `PUT /projects/{slug}/artifacts/uploads/{uploadId}/chunks/{index}
Content-Type: application/octet-stream
<raw chunk bytes>`;

const CHUNK_COMPLETE = `POST /projects/{slug}/artifacts/uploads/{uploadId}/complete
{ "sha256": "<64-hex>", "version": "v1.2.3" }`;

function MethodBadge({ method }: { method: 'GET' | 'POST' | 'PUT' }) {
  const color = method === 'GET' ? 'blue' : method === 'POST' ? 'teal' : 'grape';
  return (
    <Badge color={color} variant="light" radius="sm">
      {method}
    </Badge>
  );
}

interface EndpointRow {
  method: 'GET' | 'POST' | 'PUT';
  path: string;
  purpose: string;
}

const ENDPOINTS: EndpointRow[] = [
  { method: 'GET', path: '/whoami', purpose: 'Verify the key; see its scopes and project.' },
  {
    method: 'POST',
    path: '/projects/{slug}/deployments',
    purpose: 'Create a deployment (multipart upload).',
  },
  {
    method: 'GET',
    path: '/projects/{slug}/deployments/{id}',
    purpose: 'Poll deployment status.',
  },
  {
    method: 'GET',
    path: '/projects/{slug}/releases',
    purpose: 'List re-activatable releases (read scope).',
  },
  {
    method: 'POST',
    path: '/projects/{slug}/rollback',
    purpose: 'Roll back to a previous release.',
  },
];

export function DocsApiReferencePage() {
  return (
    <Stack gap="xl">
      <div>
        <Title order={1}>API reference</Title>
        <Text c="dimmed" size="lg" mt={4}>
          The raw HTTP contract — useful if you're not using the CLI, GitHub Action, or MCP
          server.
        </Text>
      </div>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Base URL &amp; auth
        </Title>
        <Text size="sm" mb="xs">
          All endpoints are namespaced under:
        </Text>
        <CodeBlock code="<host>/api/v1" />
        <Text size="sm" mt="sm" mb="xs">
          Authenticate every request with your API key as a bearer token:
        </Text>
        <CodeBlock code="Authorization: Bearer cpk_…" />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="sm">
          Endpoints
        </Title>
        <Table.ScrollContainer minWidth={560}>
          <Table verticalSpacing="sm" withTableBorder striped>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Method</Table.Th>
                <Table.Th>Path</Table.Th>
                <Table.Th>Purpose</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {ENDPOINTS.map((row) => (
                <Table.Tr key={row.method + row.path}>
                  <Table.Td>
                    <MethodBadge method={row.method} />
                  </Table.Td>
                  <Table.Td>
                    <code>{row.path}</code>
                  </Table.Td>
                  <Table.Td>{row.purpose}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Create a deployment
        </Title>
        <Text size="sm" mb="sm">
          <code>POST /projects/{'{slug}'}/deployments</code> — multipart/form-data with:
        </Text>
        <Table withTableBorder striped mb="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Field</Table.Th>
              <Table.Th>Required</Table.Th>
              <Table.Th>Notes</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            <Table.Tr>
              <Table.Td>
                <code>artifact</code>
              </Table.Td>
              <Table.Td>
                <Badge color="red" variant="light">
                  required
                </Badge>
              </Table.Td>
              <Table.Td>The built <code>.zip</code> file.</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>
                <code>sha256</code>
              </Table.Td>
              <Table.Td>
                <Badge color="red" variant="light">
                  required
                </Badge>
              </Table.Td>
              <Table.Td>64-hex checksum of the artifact.</Table.Td>
            </Table.Tr>
            <Table.Tr>
              <Table.Td>
                <code>version</code>
              </Table.Td>
              <Table.Td>
                <Badge color="gray" variant="light">
                  optional
                </Badge>
              </Table.Td>
              <Table.Td>A human-readable release version, e.g. a tag or SHA.</Table.Td>
            </Table.Tr>
          </Table.Tbody>
        </Table>
        <Text size="sm" mb="sm">
          Optionally send an <code>Idempotency-Key</code> header — replaying the same key
          returns the existing deployment instead of creating a duplicate. On success, returns{' '}
          <code>202 Accepted</code> with <code>{'{ data: <deployment> }'}</code>.
        </Text>
        <CodeBlock label="curl · single-request deploy" code={CURL_EXAMPLE} />
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Chunked upload
        </Title>
        <Text size="sm" mb="sm">
          For artifacts larger than <code>post_max_size</code> (typically 256MB on shared
          hosting), upload in chunks instead of a single request:
        </Text>
        <Stack gap="sm">
          <CodeBlock label="1 · start an upload" code={CHUNK_CREATE} />
          <CodeBlock label="2 · PUT each chunk (raw bytes)" code={CHUNK_PUT} />
          <CodeBlock label="3 · complete the upload" code={CHUNK_COMPLETE} />
        </Stack>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="sm">
          Deployment status lifecycle
        </Title>
        <Text size="sm" c="dimmed" mb="md">
          Poll <code>GET /projects/{'{slug}'}/deployments/{'{id}'}</code> until the status is
          terminal.
        </Text>
        <Timeline active={2} bulletSize={22} lineWidth={2}>
          <Timeline.Item title="queued">
            <Text size="sm" c="dimmed">
              Deployment accepted. It starts right away, or waits its turn if another deploy is
              already running for this project (per-project FIFO).
            </Text>
          </Timeline.Item>
          <Timeline.Item title="running">
            <Text size="sm" c="dimmed">
              Artifact is being verified and extracted.
            </Text>
          </Timeline.Item>
          <Timeline.Item title="hooks_pending">
            <Text size="sm" c="dimmed">
              Release created; post-deploy hooks are running.
            </Text>
          </Timeline.Item>
          <Timeline.Item title="success / failed / rolled_back">
            <Group gap="xs">
              <Badge color="green" variant="light">
                success
              </Badge>
              <Badge color="red" variant="light">
                failed
              </Badge>
              <Badge color="orange" variant="light">
                rolled_back
              </Badge>
            </Group>
          </Timeline.Item>
        </Timeline>
      </Card>

      <Card withBorder radius="md" p="lg">
        <Title order={4} mb="xs">
          Rollback
        </Title>
        <Text size="sm" mb="sm">
          <code>POST /projects/{'{slug}'}/rollback</code> — body{' '}
          <code>{'{ release_id?: number }'}</code>. Omit <code>release_id</code> to roll back to
          the previous release.
        </Text>
        <CodeBlock code={'{ "release_id": 42 }'} />
      </Card>

      <Alert color="blue" variant="light" icon={<IconAlertCircle size={16} />}>
        All responses use the same envelope: successful calls return{' '}
        <code>{'{ "data": ... }'}</code>; errors return a standard <code>4xx</code>/<code>5xx</code>{' '}
        status with a JSON message body.
      </Alert>
    </Stack>
  );
}
