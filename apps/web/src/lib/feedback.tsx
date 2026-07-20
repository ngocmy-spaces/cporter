import { notifications } from '@mantine/notifications';
import { IconCheck, IconX } from '@tabler/icons-react';
import axios from 'axios';

/** Pull a human-readable message out of an Axios error body (`error` or `message` keys). */
export function extractApiError(error: unknown): string | undefined {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { error?: string; message?: string } | undefined;
    return data?.error ?? data?.message;
  }
  return undefined;
}

/** Green success toast — the app's standard success feedback. */
export function notifySuccess(title: string, message: string): void {
  notifications.show({ color: 'green', title, message, icon: <IconCheck size={16} /> });
}

/** Red error toast, preferring the server message and falling back to a generic line. */
export function notifyError(title: string, error?: unknown, fallback = 'Please try again.'): void {
  notifications.show({
    color: 'red',
    title,
    message: extractApiError(error) ?? fallback,
    icon: <IconX size={16} />,
  });
}

/**
 * Map a Laravel 422 validation response onto Mantine form field errors.
 * Returns true when it handled the error (caller should stop), false otherwise
 * so the caller can fall back to `notifyError`.
 */
export function applyFormErrors(
  error: unknown,
  form: { setErrors: (errors: Record<string, string>) => void },
  /** Optional server→form field rename, e.g. map the composed `base_path` onto `base_subpath`. */
  remap?: (field: string) => string,
): boolean {
  if (axios.isAxiosError(error) && error.response?.status === 422) {
    const errors = error.response.data?.errors as Record<string, string[]> | undefined;
    if (errors) {
      form.setErrors(
        Object.fromEntries(
          Object.entries(errors).map(([field, messages]) => [
            remap ? remap(field) : field,
            messages[0],
          ]),
        ),
      );
      return true;
    }
  }
  return false;
}
