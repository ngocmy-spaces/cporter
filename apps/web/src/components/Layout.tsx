import type { ComponentType } from 'react';
import { ActionIcon, AppShell, Badge, Burger, Group, NavLink, Text, Tooltip } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { Link, Outlet, useLocation } from 'react-router-dom';
import {
  IconBook,
  IconFileText,
  IconFolders,
  IconKey,
  IconLayoutDashboard,
  IconLogout,
  IconRocket,
  IconSettings,
  IconUsers,
  IconVersions,
} from '@tabler/icons-react';
import { useAuth } from '@/lib/auth';
import { ColorSchemeToggle } from '@/components/ColorSchemeToggle';

type NavItem = {
  to: string;
  label: string;
  end?: boolean;
  icon: ComponentType<{ size?: number; stroke?: number }>;
};

const NAV: NavItem[] = [
  { to: '/', label: 'Dashboard', end: true, icon: IconLayoutDashboard },
  { to: '/projects', label: 'Projects', icon: IconFolders },
  { to: '/deployments', label: 'Deployments', icon: IconRocket },
  { to: '/releases', label: 'Releases', icon: IconVersions },
  { to: '/logs', label: 'Logs', icon: IconFileText },
  { to: '/settings', label: 'Settings', icon: IconSettings },
  { to: '/users', label: 'Users', icon: IconUsers },
  { to: '/api-keys', label: 'API Keys', icon: IconKey },
];

function UserMenu() {
  const { user, logout } = useAuth();
  if (!user) return null;

  return (
    <Group gap={6}>
      <Text size="sm" c="dimmed">
        {user.email}
      </Text>
      <Tooltip label="Log out">
        <ActionIcon
          variant="subtle"
          color="gray"
          size="lg"
          aria-label="Log out"
          onClick={() => void logout()}
        >
          <IconLogout size={18} stroke={1.5} />
        </ActionIcon>
      </Tooltip>
    </Group>
  );
}

export function Layout() {
  const { pathname } = useLocation();
  const { user } = useAuth();
  const [mobileOpened, { toggle: toggleMobile, close: closeMobile }] = useDisclosure(false);
  const isActive = (item: NavItem) => (item.end ? pathname === item.to : pathname.startsWith(item.to));
  const navItems = NAV.filter((item) => item.to !== '/users' || user?.role === 'admin');

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
            <Text fw={700} size="lg">
              c
              <Text span inherit c="indigo.5">
                Porter
              </Text>
            </Text>
          </Group>
          <Group gap="sm">
            <Badge variant="light" color="gray">
              v0.1.0 · Phase 0
            </Badge>
            <Tooltip label="Documentation">
              <ActionIcon
                component={Link}
                to="/docs"
                variant="subtle"
                color="gray"
                size="lg"
                aria-label="Documentation"
              >
                <IconBook size={18} stroke={1.5} />
              </ActionIcon>
            </Tooltip>
            <UserMenu />
            <ColorSchemeToggle />
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="xs">
        {navItems.map((item) => {
          const Icon = item.icon;
          return (
            <NavLink
              key={item.to}
              component={Link}
              to={item.to}
              label={item.label}
              leftSection={<Icon size={18} stroke={1.5} />}
              active={isActive(item)}
              onClick={closeMobile}
            />
          );
        })}
      </AppShell.Navbar>

      <AppShell.Main>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
