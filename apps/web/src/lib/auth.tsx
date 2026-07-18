import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { api } from '@/lib/api';
import type { ApiEnvelope, User } from '@/lib/types';

const AUTH_QUERY_KEY = ['auth', 'user'] as const;

/** Prime the XSRF-TOKEN cookie (a 401 on /auth/user won't set it — see routes/api.php /csrf). */
async function ensureCsrf(): Promise<void> {
  try {
    await api.get('/csrf');
  } catch {
    // ignore — login will surface any real problem
  }
}

interface AuthContextValue {
  user: User | null;
  isLoading: boolean;
  isLoggingIn: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();

  // Bootstrap query: also the GET that seeds the XSRF-TOKEN cookie before any POST.
  const userQuery = useQuery({
    queryKey: AUTH_QUERY_KEY,
    queryFn: async () => {
      try {
        await ensureCsrf();
        const { data } = await api.get<ApiEnvelope<User>>('/auth/user');
        return data.data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 401) {
          return null;
        }
        throw error;
      }
    },
    retry: false,
    staleTime: 5 * 60_000,
    refetchOnWindowFocus: false,
  });

  const loginMutation = useMutation({
    mutationFn: async ({ email, password }: { email: string; password: string }) => {
      await ensureCsrf();
      const { data } = await api.post<ApiEnvelope<User>>('/auth/login', { email, password });
      return data.data;
    },
    onSuccess: (user) => {
      queryClient.setQueryData(AUTH_QUERY_KEY, user);
    },
  });

  const logoutMutation = useMutation({
    mutationFn: async () => {
      await api.post('/auth/logout');
    },
    onSuccess: () => {
      queryClient.setQueryData(AUTH_QUERY_KEY, null);
      queryClient.clear();
    },
  });

  const value = useMemo<AuthContextValue>(
    () => ({
      user: userQuery.data ?? null,
      isLoading: userQuery.isLoading,
      isLoggingIn: loginMutation.isPending,
      login: async (email, password) => {
        await loginMutation.mutateAsync({ email, password });
      },
      logout: async () => {
        await logoutMutation.mutateAsync();
      },
    }),
    [userQuery.data, userQuery.isLoading, loginMutation, logoutMutation],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within an <AuthProvider>.');
  return ctx;
}
