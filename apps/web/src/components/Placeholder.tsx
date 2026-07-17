import type { ReactNode } from 'react';

export function Placeholder({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <section>
      <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
      <div className="mt-4 rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900">
        {children ?? 'Chưa triển khai — xem TASKS.md để biết task tương ứng.'}
      </div>
    </section>
  );
}
