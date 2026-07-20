import type { ComponentType } from 'react';
import { ActionIcon, AppShell, Badge, Burger, Group, Menu, NavLink, Text, Tooltip, UnstyledButton } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { Link, Outlet, useLocation } from 'react-router-dom';
import {
  IconBook,
  IconChevronDown,
  IconFileText,
  IconFolders,
  IconKey,
  IconLayoutDashboard,
  IconLock,
  IconLogout,
  IconRocket,
  IconServer,
  IconUsers,
} from '@tabler/icons-react';
import { useAuth } from '@/lib/auth';
import { ColorSchemeToggle } from '@/components/ColorSchemeToggle';
import { ChangePasswordModal } from '@/components/ChangePasswordModal';

type NavItem = {
  to: string;
  label: string;
  end?: boolean;
  icon: ComponentType<{ size?: number; stroke?: number }>;
  adminOnly?: boolean;
};

/** Primary, everyday operations — shown flat at the top of the navbar. */
const MAIN_NAV: NavItem[] = [
  { to: '/', label: 'Overview', end: true, icon: IconLayoutDashboard },
  { to: '/projects', label: 'Projects', icon: IconFolders },
  { to: '/deployments', label: 'Deployments', icon: IconRocket },
];

/** Governance & introspection — grouped under an "Admin" section. */
const ADMIN_NAV: NavItem[] = [
  { to: '/activity', label: 'Activity', icon: IconFileText },
  { to: '/users', label: 'Users', icon: IconUsers, adminOnly: true },
  { to: '/api-keys', label: 'API Keys', icon: IconKey },
  { to: '/system', label: 'System', icon: IconServer },
];

function UserMenu() {
  const { user, logout } = useAuth();
  const [passwordOpened, { open: openPassword, close: closePassword }] = useDisclosure(false);
  if (!user) return null;

  return (
    <>
      <Menu position="bottom-end" width={200} withArrow>
        <Menu.Target>
          <UnstyledButton aria-label="Account menu">
            <Group gap={6}>
              <Text size="sm" c="dimmed">
                {user.email}
              </Text>
              <IconChevronDown size={16} stroke={1.5} />
            </Group>
          </UnstyledButton>
        </Menu.Target>
        <Menu.Dropdown>
          <Menu.Item leftSection={<IconLock size={16} stroke={1.5} />} onClick={openPassword}>
            Change password
          </Menu.Item>
          <Menu.Divider />
          <Menu.Item
            color="red"
            leftSection={<IconLogout size={16} stroke={1.5} />}
            onClick={() => void logout()}
          >
            Log out
          </Menu.Item>
        </Menu.Dropdown>
      </Menu>

      <ChangePasswordModal opened={passwordOpened} onClose={closePassword} />
    </>
  );
}

export function Layout() {
  const { pathname } = useLocation();
  const { user } = useAuth();
  const [mobileOpened, { toggle: toggleMobile, close: closeMobile }] = useDisclosure(false);
  const isActive = (item: NavItem) => (item.end ? pathname === item.to : pathname.startsWith(item.to));
  const isVisible = (item: NavItem) => !item.adminOnly || user?.role === 'admin';
  const renderNav = (item: NavItem) => {
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
  };
  const adminNav = ADMIN_NAV.filter(isVisible);

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
              v0.1.0
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
        {MAIN_NAV.map(renderNav)}
        <Text size="xs" tt="uppercase" fw={700} c="dimmed" px="sm" mt="md" mb={4}>
          Admin
        </Text>
        {adminNav.map(renderNav)}
      </AppShell.Navbar>

      <AppShell.Main>
        <Outlet />
      </AppShell.Main>
    </AppShell>
  );
}
