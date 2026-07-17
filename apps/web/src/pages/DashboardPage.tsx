import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export function DashboardPage() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: async () => (await api.get<{ status: string }>('/health')).data,
  });

  const apiStatus = health.isLoading
    ? 'checking…'
    : health.isError
      ? 'unreachable'
      : (health.data?.status ?? 'unknown');

  return (
    <section>
      <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
      <p className="mt-1 text-sm text-slate-500">Tổng quan hệ thống cPorter.</p>

      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="API" value={apiStatus} ok={health.isSuccess} />
        <Stat label="Projects" value="—" />
        <Stat label="Deployments (24h)" value="—" />
        <Stat label="Releases" value="—" />
      </div>

      <div className="mt-6 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900">
        Widgets (deploy gần đây, success rate, cảnh báo) sẽ thêm ở Phase 3 — xem TASKS.md.
      </div>
    </section>
  );
}

function Stat({ label, value, ok }: { label: string; value: string; ok?: boolean }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <div className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</div>
      <div
        className={[
          'mt-1 text-xl font-semibold',
          ok === true ? 'text-emerald-600 dark:text-emerald-400' : '',
          ok === false ? 'text-rose-600 dark:text-rose-400' : '',
        ].join(' ')}
      >
        {value}
      </div>
    </div>
  );
}
