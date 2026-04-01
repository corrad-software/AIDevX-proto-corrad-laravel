import { apiRequest, ensureCsrfCookie } from "./client";
import type { User } from "@/types";

export async function register(data: { name: string; email: string; password: string; password_confirmation: string; customerCode: string }) {
  await ensureCsrfCookie();
  return apiRequest<{ data: { message: string; email: string } }>("/api/auth/register", {
    method: "POST",
    body: JSON.stringify(data),
  });
}

export async function verifyEmail(token: string) {
  return apiRequest<{ data: { message: string } }>("/api/auth/verify-email", {
    method: "POST",
    body: JSON.stringify({ token }),
  });
}

export async function forgotPassword(email: string) {
  await ensureCsrfCookie();
  return apiRequest<{ data: { message: string } }>("/api/auth/forgot-password", {
    method: "POST",
    body: JSON.stringify({ email }),
  });
}

export async function resetPassword(input: { email: string; token: string; password: string; passwordConfirmation: string }) {
  await ensureCsrfCookie();
  return apiRequest<{ data: { message: string } }>("/api/auth/reset-password", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export async function resendVerification(email: string) {
  await ensureCsrfCookie();
  return apiRequest<{ data: { message: string } }>("/api/auth/resend-verification", {
    method: "POST",
    body: JSON.stringify({ email }),
  });
}

export async function login(email: string, password: string) {
  await ensureCsrfCookie(true);
  return apiRequest<{ data: { user: User } }>("/api/auth/login", {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
}

export async function logout() {
  return apiRequest<{ data: { success: boolean } }>("/api/auth/logout", {
    method: "POST",
  });
}

export async function getMe() {
  return apiRequest<{ data: { user: User } }>("/api/auth/me");
}

export async function updateProfile(data: { name?: string; email?: string }) {
  return apiRequest<{ data: { user: User } }>("/api/auth/me", {
    method: "PUT",
    body: JSON.stringify(data),
  });
}

export async function changePassword(data: { currentPassword: string; newPassword: string }) {
  return apiRequest<{ data: { message: string } }>("/api/auth/password", {
    method: "POST",
    body: JSON.stringify(data),
  });
}

export async function uploadAvatar(file: File) {
  const formData = new FormData();
  formData.append("file", file);
  return apiRequest<{ data: { user: User } }>("/api/auth/avatar", {
    method: "POST",
    body: formData,
  });
}

export async function removeAvatar() {
  return apiRequest<{ data: { user: User } }>("/api/auth/avatar", {
    method: "DELETE",
  });
}

export type ImpersonateUser = { id: number; name: string; email: string };

export async function impersonateUser(userId: number) {
  return apiRequest<{ data: { user: User; impersonating: boolean; impersonatedBy: number } }>(
    "/api/auth/impersonate",
    { method: "POST", body: JSON.stringify({ userId }) },
  );
}

export async function stopImpersonate() {
  return apiRequest<{ data: { user: User } }>("/api/auth/stop-impersonate", {
    method: "POST",
  });
}

export async function getImpersonateUsers(q?: string) {
  const params = q ? `?q=${encodeURIComponent(q)}` : "";
  return apiRequest<{ data: ImpersonateUser[] }>(`/api/auth/impersonate-users${params}`);
}
