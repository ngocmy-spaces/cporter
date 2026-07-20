import { useMemo, useState } from 'react';
import { Badge, Code, Paper, Select, Skeleton, Stack, Table, Text, Title, Tooltip } from '@mantine/core';
import { IconHistory } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { EmptyState } from '@/components/EmptyState';
import { PanelBody } from '@/components/PanelBody';
import { TimeAgo } from '@/components/TimeAgo';
import type { ApiEnvelope, AuditLog } from '@/lib/types';

/** `App\Models\Deployment` -> `Deployment` */
function shortSubjectType(subjectType: string | null): string | null {
  if (!subjectType) return null;
  const parts = subjectType.split('\\');
  return parts[parts.length - 1];
}

function metaSummary(meta: Record<string, unknown> | null): string | null {
  if (!meta || Object.keys(meta).length === 0) return null;
  try {
    return JSON.stringify(meta);
  } catch {
    return null;
  }
}

export function LogsPage() {
  const [actionFilter, setActionFilter] = useState<string | null>(null);

  const auditLogs = useQuery({
    queryKey: ['audit-logs'],
    queryFn: async () => (await api.get<ApiEnvelope<AuditLog[]>>('/audit-logs')).data.data,
    refetchInterval: 15_000,
  });

  const allLogs = auditLogs.data ?? [];

  const actionOptions = useMemo(
    () => Array.from(new Set((auditLogs.data ?? []).map((l) => l.action))).sort(),
    [auditLogs.data],
  );

  const filteredLogs = actionFilter ? allLogs.filter((l) => l.action === actionFilter) : allLogs;

  return (
    <Stack gap="lg">
      <div>
        <Title order={2}>Activity</Title>
        <Text c="dimmed" size="sm">
          Audit trail — who did what across cPorter: projects, releases, API keys, users.
        </Text>
      </div>

      <Select
        label="Filter by action"
        placeholder="All actions"
        data={actionOptions}
        value={actionFilter}
        onChange={setActionFilter}
        clearable
        searchable
        w={280}
      />

      <Paper withBorder radius="md">
        <PanelBody
          query={auditLogs}
          errorTitle="Couldn't load activity"
          loader={
            <Stack gap="sm" p="md">
              {Array.from({ length: 6 }).map((_, i) => (
                <Skeleton key={i} height={32} radius="sm" />
              ))}
            </Stack>
          }
        >
          <Table.ScrollContainer minWidth={800}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Action</Table.Th>
                  <Table.Th>Actor</Table.Th>
                  <Table.Th>Subject</Table.Th>
                  <Table.Th>Meta</Table.Th>
                  <Table.Th>IP</Table.Th>
                  <Table.Th>When</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {filteredLogs.map((log) => {
                  const subjectLabel = shortSubjectType(log.subject_type);
                  const meta = metaSummary(log.meta);
                  return (
                    <Table.Tr key={log.id}>
                      <Table.Td>
                        <Badge variant="light" color="indigo">
                          {log.action}
                        </Badge>
                      </Table.Td>
                      <Table.Td>{log.actor ?? '—'}</Table.Td>
                      <Table.Td>
                        <Text size="sm" c="dimmed">
                          {subjectLabel ? `${subjectLabel} #${log.subject_id}` : '—'}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        {meta ? (
                          <Tooltip label={meta} multiline maw={360}>
                            <Code
                              style={{
                                display: 'inline-block',
                                maxWidth: 260,
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                                verticalAlign: 'bottom',
                              }}
                            >
                              {meta}
                            </Code>
                          </Tooltip>
                        ) : (
                          <Text size="sm" c="dimmed">
                            —
                          </Text>
                        )}
                      </Table.Td>
                      <Table.Td>
                        <Text size="sm" c="dimmed">
                          {log.ip ?? '—'}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        <TimeAgo iso={log.created_at} />
                      </Table.Td>
                    </Table.Tr>
                  );
                })}
                {filteredLogs.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={6}>
                      <EmptyState
                        icon={<IconHistory size={28} />}
                        title={allLogs.length === 0 ? 'No activity yet' : 'No matching activity'}
                        description={
                          allLogs.length === 0
                            ? 'Audit events will appear here as actions happen across cPorter.'
                            : 'No audit events match the current filter.'
                        }
                      />
                    </Table.Td>
                  </Table.Tr>
                )}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        </PanelBody>
      </Paper>
    </Stack>
  );
}
