import { Routes, Route, Navigate, Outlet } from 'react-router-dom';
import { Center, Loader } from '@mantine/core';
import { useAuth } from '@/lib/auth';
import { Layout } from '@/components/Layout';
import { LoginPage } from '@/pages/LoginPage';
import { DashboardPage } from '@/pages/DashboardPage';
import { ProjectsPage } from '@/pages/ProjectsPage';
import { ProjectDetailPage } from '@/pages/ProjectDetailPage';
import { DeploymentsPage } from '@/pages/DeploymentsPage';
import { ReleasesPage } from '@/pages/ReleasesPage';
import { LogsPage } from '@/pages/LogsPage';
import { SettingsPage } from '@/pages/SettingsPage';
import { UsersPage } from '@/pages/UsersPage';
import { ApiKeysPage } from '@/pages/ApiKeysPage';
import { DocsLayout } from '@/pages/docs/DocsLayout';
import { DocsOverviewPage } from '@/pages/docs/DocsOverviewPage';
import { DocsCpanelSetupPage } from '@/pages/docs/DocsCpanelSetupPage';
import { DocsQuickstartPage } from '@/pages/docs/DocsQuickstartPage';
import { DocsGithubActionPage } from '@/pages/docs/DocsGithubActionPage';
import { DocsMcpPage } from '@/pages/docs/DocsMcpPage';
import { DocsApiReferencePage } from '@/pages/docs/DocsApiReferencePage';

/**
 * Gate for the authenticated admin app. Declared as a layout route (no `path`) so that
 * public routes — currently `/docs/*` — can be declared as siblings, outside this gate,
 * and remain reachable without logging in.
 */
function RequireAuth() {
  const { user, isLoading } = useAuth();

  if (isLoading) {
    return (
      <Center h="100vh">
        <Loader />
      </Center>
    );
  }

  if (!user) {
    return <LoginPage />;
  }

  return <Outlet />;
}

export function App() {
  return (
    <Routes>
      {/* Public docs area — no auth required. */}
      <Route path="/docs" element={<DocsLayout />}>
        <Route index element={<Navigate to="overview" replace />} />
        <Route path="overview" element={<DocsOverviewPage />} />
        <Route path="cpanel-setup" element={<DocsCpanelSetupPage />} />
        <Route path="quickstart" element={<DocsQuickstartPage />} />
        <Route path="github-action" element={<DocsGithubActionPage />} />
        <Route path="mcp" element={<DocsMcpPage />} />
        <Route path="api-reference" element={<DocsApiReferencePage />} />
        <Route path="*" element={<Navigate to="overview" replace />} />
      </Route>

      {/* Authenticated admin app. */}
      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route index element={<DashboardPage />} />
          <Route path="projects" element={<ProjectsPage />} />
          <Route path="projects/:slug" element={<ProjectDetailPage />} />
          <Route path="deployments" element={<DeploymentsPage />} />
          <Route path="releases" element={<ReleasesPage />} />
          <Route path="logs" element={<LogsPage />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="users" element={<UsersPage />} />
          <Route path="api-keys" element={<ApiKeysPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Route>
    </Routes>
  );
}
