import { ActionIcon, Box, Code, CopyButton, Text, Tooltip } from '@mantine/core';
import { IconCheck, IconCopy } from '@tabler/icons-react';

interface CodeBlockProps {
  code: string;
  /** Small uppercase label above the block, e.g. "bash", "yaml", "json". */
  label?: string;
}

/** Copy-pasteable code snippet used across the docs pages (plain Mantine `Code`, no extra deps). */
export function CodeBlock({ code, label }: CodeBlockProps) {
  return (
    <Box>
      {label && (
        <Text size="xs" c="dimmed" fw={600} tt="uppercase" mb={4}>
          {label}
        </Text>
      )}
      <Box pos="relative">
        <Code block style={{ overflowX: 'auto', paddingRight: 40, whiteSpace: 'pre' }}>
          {code}
        </Code>
        <CopyButton value={code} timeout={2000}>
          {({ copied, copy }) => (
            <Tooltip label={copied ? 'Copied' : 'Copy'} withArrow position="left">
              <ActionIcon
                variant="subtle"
                color={copied ? 'teal' : 'gray'}
                size="sm"
                onClick={copy}
                aria-label="Copy code"
                style={{ position: 'absolute', top: 8, right: 8 }}
              >
                {copied ? <IconCheck size={14} /> : <IconCopy size={14} />}
              </ActionIcon>
            </Tooltip>
          )}
        </CopyButton>
      </Box>
    </Box>
  );
}
