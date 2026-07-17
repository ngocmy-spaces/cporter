import { NavLink, Outlet } from 'react-router-dom';

type NavItem = { to: string; label: string; end?: boolean };

const NAV: NavItem[] = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/projects', label: 'Projects' },
  { to: '/deployments', label: 'Deployments' },
  { to: '/releases', label: 'Releases' },
  { to: '/logs', label: 'Logs' },
  { to: '/settings', label: 'Settings' },
  { to: '/users', label: 'Users' },
  { to: '/api-keys', label: 'API Keys' },
];

export function Layout() {
  return (
    <div className="flex min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
      <aside className="flex w-56 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="px-5 py-4 text-lg font-semibold tracking-tight">
          c<span className="text-indigo-500">Porter</span>
        </div>
        <nav className="flex-1 space-y-1 px-3 py-2">
          {NAV.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                [
                  'block rounded-md px-3 py-2 text-sm font-medium transition',
                  isActive
                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800',
                ].join(' ')
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="px-5 py-3 text-xs text-slate-400">v0.1.0 · Phase 0</div>
      </aside>

      <main className="flex-1 overflow-auto">
        <div className="mx-auto max-w-6xl px-8 py-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
