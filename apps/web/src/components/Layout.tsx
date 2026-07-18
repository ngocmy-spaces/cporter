import type { ComponentType } from 'react';
import {
  ActionIcon,
  AppShell,
  Badge,
  Group,
  NavLink,
  Text,
  Tooltip,
  useComputedColorScheme,
  useMantineColorScheme,
} from '@mantine/core';
import { Link, Outlet, useLocation } from 'react-router-dom';
import {
  IconFileText,
  IconFolders,
  IconKey,
  IconLayoutDashboard,
  IconLogout,
  IconMoon,
  IconRocket,
  IconSettings,
  IconSun,
  IconUsers,
  IconVersions,
} from '@tabler/icons-react';
import { useAuth } from '@/lib/auth';

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

function ColorSchemeToggle() {
  const { setColorScheme } = useMantineColorScheme();
  const computed = useComputedColorScheme('light', { getInitialValueInEffect: true });
  return (
    <ActionIcon
      variant="default"
      size="lg"
      aria-label="Toggle color scheme"
      onClick={() => setColorScheme(computed === 'dark' ? 'light' : 'dark')}
    >
      {computed === 'dark' ? <IconSun size={18} stroke={1.5} /> : <IconMoon size={18} stroke={1.5} />}
    </ActionIcon>
  );
}

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
  const isActive = (item: NavItem) => (item.end ? pathname === item.to : pathname.startsWith(item.to));
  const navItems = NAV.filter((item) => item.to !== '/users' || user?.role === 'admin');

  return (
    <AppShell header={{ height: 56 }} navbar={{ width: 240, breakpoint: 'sm' }} padding="md">
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between">
          <Text fw={700} size="lg">
            c
            <Text span inherit c="indigo.5">
              Porter
            </Text>
          </Text>
          <Group gap="sm">
            <Badge variant="light" color="gray">
              v0.1.0 · Phase 0
            </Badge>
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
