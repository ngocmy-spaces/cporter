import type { EnvVar } from '@/lib/types';

const KEY_RE = /^[A-Za-z_][A-Za-z0-9_]*$/;
/** cPorter's managed-file marker (mirrors EnvFileRenderer::MARKER) — ignored on import. */
const MARKER_PREFIX = '# Managed by cPorter';

export interface ParseEnvResult {
  vars: EnvVar[];
  /** Lines that looked like a var but were unusable (no `=` or invalid key). Comments/blanks don't count. */
  skipped: number;
}

/**
 * Parse pasted/uploaded `.env` text into key/value pairs, tolerating real-world dotenv files:
 * comments, blank lines, `export ` prefixes, single/double quotes (with escapes in double quotes),
 * inline comments after unquoted values, and `=` inside values. Invalid keys are skipped, not
 * thrown, so one bad line doesn't lose the rest. Duplicates: last occurrence wins.
 */
export function parseEnvText(text: string): ParseEnvResult {
  const map = new Map<string, string>();
  let skipped = 0;

  for (const rawLine of text.split(/\r?\n/)) {
    const line = rawLine.trim();

    // Skip blanks, comments, and our own marker line.
    if (line === '' || line.startsWith('#') || line.startsWith(MARKER_PREFIX)) {
      continue;
    }

    const withoutExport = line.startsWith('export ') ? line.slice('export '.length).trim() : line;

    const eq = withoutExport.indexOf('=');
    if (eq === -1) {
      skipped += 1;
      continue;
    }

    const key = withoutExport.slice(0, eq).trim();
    if (!KEY_RE.test(key)) {
      skipped += 1;
      continue;
    }

    map.set(key, parseValue(withoutExport.slice(eq + 1)));
  }

  return {
    vars: Array.from(map, ([key, value]) => ({ key, value })),
    skipped,
  };
}

function parseValue(raw: string): string {
  const value = raw.trim();
  if (value === '') {
    return '';
  }

  // Double-quoted: unquote and process escape sequences.
  if (value.startsWith('"')) {
    const end = findClosingQuote(value, '"');
    if (end !== -1) {
      return unescapeDoubleQuoted(value.slice(1, end));
    }
  }

  // Single-quoted: literal, no escape processing.
  if (value.startsWith("'")) {
    const end = value.indexOf("'", 1);
    if (end !== -1) {
      return value.slice(1, end);
    }
  }

  // Unquoted: strip a trailing inline comment (` #…` preceded by whitespace) and trim.
  return stripInlineComment(value);
}

/** Index of the unescaped closing quote, or -1 if unterminated. */
function findClosingQuote(value: string, quote: string): number {
  for (let i = 1; i < value.length; i += 1) {
    if (value[i] === '\\') {
      i += 1; // skip escaped char
      continue;
    }
    if (value[i] === quote) {
      return i;
    }
  }
  return -1;
}

function unescapeDoubleQuoted(inner: string): string {
  return inner.replace(/\\([\\"'nrt])/g, (_m, ch: string) => {
    switch (ch) {
      case 'n':
        return '\n';
      case 'r':
        return '\r';
      case 't':
        return '\t';
      default:
        return ch; // \\ \" \'
    }
  });
}

function stripInlineComment(value: string): string {
  const hash = value.search(/\s#/);
  return (hash === -1 ? value : value.slice(0, hash)).trim();
}
