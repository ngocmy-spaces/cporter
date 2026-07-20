import type { ComponentType } from 'react';
import { AppShell, Burger, Button, Container, Group, NavLink, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { Link, Outlet, useLocation } from 'react-router-dom';
import {
  IconApi,
  IconArrowLeft,
  IconBrandGithub,
  IconInfoCircle,
  IconKey,
  IconPackage,
  IconRobot,
  IconServer,
  IconTerminal2,
} from '@tabler/icons-react';
import { ColorSchemeToggle } from '@/components/ColorSchemeToggle';

type DocsNavItem = {
  to: string;
  label: string;
  icon: ComponentType<{ size?: number; stroke?: number }>;
};

const DOCS_NAV: DocsNavItem[] = [
  { to: '/docs/overview', label: 'Overview', icon: IconInfoCircle },
  { to: '/docs/cpanel-setup', label: 'cPanel setup', icon: IconServer },
  { to: '/docs/quickstart', label: 'Quickstart (CLI)', icon: IconTerminal2 },
  { to: '/docs/github-action', label: 'GitHub Action', icon: IconBrandGithub },
  { to: '/docs/artifact', label: 'Artifact & packaging', icon: IconPackage },
  { to: '/docs/env', label: 'Environment variables', icon: IconKey },
  { to: '/docs/mcp', label: 'AI agent (MCP)', icon: IconRobot },
  { to: '/docs/api-reference', label: 'API reference', icon: IconApi },
];

/**
 * Public docs shell — deliberately rendered as a sibling of the authenticated app in
 * `App.tsx` (outside the `RequireAuth` gate) so it is reachable without logging in.
 */
export function DocsLayout() {
  const { pathname } = useLocation();
  const [mobileOpened, { toggle: toggleMobile, close: closeMobile }] = useDisclosure(false);

  return (
    <AppShell
      header={{ height: 56 }}
      navbar={{ width: 240, breakpoint: 'sm', collapsed: { mobile: !mobileOpened, desktop: false } }}
      padding="md"
    >
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between">
          <Group gap="sm">
            <Burger opened={mobileOpened} onClick={toggleMobile} hiddenFrom="sm" size="sm" aria-label="Toggle navigation" />
            <Text fw={700} size="lg" component={Link} to="/docs/overview" style={{ textDecoration: 'none', color: 'inherit' }}>
              c
              <Text span inherit c="indigo.5">
                Porter
              </Text>
              <Text span inherit c="dimmed" fw={400} size="sm" ml={6}>
                docs
              </Text>
            </Text>
          </Group>
          <Group gap="sm">
            <Button
              component={Link}
              to="/"
              variant="subtle"
              size="xs"
              leftSection={<IconArrowLeft size={16} stroke={1.5} />}
            >
              Back to app
            </Button>
            <ColorSchemeToggle />
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="xs">
        {DOCS_NAV.map((item) => {
          const Icon = item.icon;
          return (
            <NavLink
              key={item.to}
              component={Link}
              to={item.to}
              label={item.label}
              leftSection={<Icon size={18} stroke={1.5} />}
              active={pathname.startsWith(item.to)}
              onClick={closeMobile}
            />
          );
        })}
      </AppShell.Navbar>

      <AppShell.Main>
        <Container size="md" px={0}>
          <Outlet />
        </Container>
      </AppShell.Main>
    </AppShell>
  );
}
