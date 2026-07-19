/**
 * Minimal, dependency-free argument parser. Supports `--flag value`, `--flag=value`,
 * boolean `--flag`, negatable `--no-flag`, and collects bare positionals in order.
 */
export interface ParsedArgs {
  positionals: string[];
  flags: Record<string, string | boolean>;
}

export function parseArgs(argv: string[]): ParsedArgs {
  const positionals: string[] = [];
  const flags: Record<string, string | boolean> = {};

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i]!;
    if (!arg.startsWith('--')) {
      positionals.push(arg);
      continue;
    }
    const body = arg.slice(2);
    const eq = body.indexOf('=');
    if (eq !== -1) {
      flags[body.slice(0, eq)] = body.slice(eq + 1);
      continue;
    }
    if (body.startsWith('no-')) {
      flags[body.slice(3)] = false;
      continue;
    }
    // A following non-flag token is this flag's value; otherwise it's a boolean flag.
    const next = argv[i + 1];
    if (next !== undefined && !next.startsWith('--')) {
      flags[body] = next;
      i++;
    } else {
      flags[body] = true;
    }
  }

  return { positionals, flags };
}

/** Read a string flag, falling back to an env var, then a default. */
export function str(
  flags: Record<string, string | boolean>,
  name: string,
  env?: string,
  fallback?: string,
): string | undefined {
  const v = flags[name];
  if (typeof v === 'string') return v;
  if (env && process.env[env]) return process.env[env];
  return fallback;
}

/** Read a boolean flag (default when absent). `--flag` → true, `--no-flag` → false. */
export function bool(
  flags: Record<string, string | boolean>,
  name: string,
  fallback: boolean,
): boolean {
  const v = flags[name];
  if (typeof v === 'boolean') return v;
  if (typeof v === 'string') return v !== 'false' && v !== '0';
  return fallback;
}

/** Read a numeric flag; returns undefined when absent, throws on non-numeric. */
export function num(
  flags: Record<string, string | boolean>,
  name: string,
): number | undefined {
  const v = flags[name];
  if (v === undefined) return undefined;
  const n = Number(v);
  if (Number.isNaN(n)) throw new Error(`--${name} must be a number (got "${String(v)}").`);
  return n;
}
