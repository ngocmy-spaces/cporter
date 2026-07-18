import { useState } from 'react';
import { Alert, Button, Card, Container, PasswordInput, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { IconAlertCircle } from '@tabler/icons-react';
import axios from 'axios';
import { useAuth } from '@/lib/auth';

interface LoginFormValues {
  email: string;
  password: string;
}

export function LoginPage() {
  const { login, isLoggingIn } = useAuth();
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<LoginFormValues>({
    initialValues: { email: '', password: '' },
    validate: {
      email: (value) => (/^\S+@\S+\.\S+$/.test(value) ? null : 'Enter a valid email'),
      password: (value) => (value.length > 0 ? null : 'Password is required'),
    },
  });

  const handleSubmit = form.onSubmit(async (values) => {
    setFormError(null);
    try {
      await login(values.email, values.password);
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const errors = error.response.data?.errors as Record<string, string[]> | undefined;
        setFormError(errors?.email?.[0] ?? error.response.data?.message ?? 'Invalid credentials.');
      } else {
        setFormError('Unable to sign in. Please try again.');
      }
    }
  });

  return (
    <Container size={420} my={120}>
      <Title ta="center" fw={700}>
        c
        <Text span inherit c="indigo.5">
          Porter
        </Text>
      </Title>
      <Text c="dimmed" size="sm" ta="center" mt="xs">
        Sign in to manage projects and deployments.
      </Text>

      <Card withBorder shadow="sm" radius="md" mt="xl" p="xl">
        <form onSubmit={handleSubmit}>
          <TextInput
            label="Email"
            placeholder="you@example.com"
            required
            {...form.getInputProps('email')}
          />
          <PasswordInput
            label="Password"
            placeholder="Your password"
            required
            mt="md"
            {...form.getInputProps('password')}
          />

          {formError && (
            <Alert color="red" variant="light" mt="md" icon={<IconAlertCircle size={16} />}>
              {formError}
            </Alert>
          )}

          <Button type="submit" fullWidth mt="xl" loading={isLoggingIn}>
            Sign in
          </Button>
        </form>
      </Card>
    </Container>
  );
}
