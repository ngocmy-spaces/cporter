import { Badge } from '@mantine/core';
import type { DeploymentStatus, ReleaseState } from '@/lib/types';

const DEPLOYMENT_COLORS: Record<DeploymentStatus, string> = {
  success: 'green',
  failed: 'red',
  rolled_back: 'orange',
  running: 'blue',
  queued: 'gray',
  hooks_pending: 'blue',
};

const RELEASE_COLORS: Record<ReleaseState, string> = {
  active: 'green',
  ready: 'blue',
  pending: 'gray',
  extracting: 'blue',
  superseded: 'gray',
  failed: 'red',
  pruned: 'gray',
};

export function DeploymentStatusBadge({ status }: { status: DeploymentStatus }) {
  return (
    <Badge color={DEPLOYMENT_COLORS[status]} variant="light">
      {status.replace('_', ' ')}
    </Badge>
  );
}

export function ReleaseStateBadge({ state }: { state: ReleaseState }) {
  return (
    <Badge color={RELEASE_COLORS[state]} variant="light">
      {state}
    </Badge>
  );
}
