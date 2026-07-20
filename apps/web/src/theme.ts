import { createTheme, Paper } from '@mantine/core';

/**
 * cPorter Mantine theme. Override tokens here (colors, radius, fonts).
 * Docs: https://mantine.dev/llms/theming-theme-object.md
 */
export const theme = createTheme({
  primaryColor: 'indigo',
  defaultRadius: 'md',
  fontFamily:
    'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
  fontFamilyMonospace:
    'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace',
  components: {
    // Every surface in the app is a bordered, md-radius Paper — make that the default
    // so pages don't repeat the props (and stay consistent if the token ever changes).
    Paper: Paper.extend({ defaultProps: { withBorder: true, radius: 'md' } }),
  },
});
