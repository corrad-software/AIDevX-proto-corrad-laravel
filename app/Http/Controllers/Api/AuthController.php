<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResendVerificationRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Traits\ApiResponse;
use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailVerificationService;
use App\Services\UserHierarchyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AuditService $auditService,
        protected EmailVerificationService $emailVerification,
        protected UserHierarchyService $hierarchy,
    ) {}

    /**
     * Authenticate a user and start a session.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::attempt($credentials)) {
            return $this->sendError(401, 'INVALID_CREDENTIALS', 'Invalid email or password');
        }

        $request->session()->regenerate();

        $user = Auth::user();
        if (($user->user_level ?? '') === UserLevel::USER && ! $user->email_verified_at) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->sendError(403, 'EMAIL_NOT_VERIFIED', 'Please verify your email before signing in. Check your inbox or use “Resend verification”.');
        }

        $this->auditService->logAuth('login', $user);

        return $this->sendOk([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Register new end user (daftar). Sends verification email.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $customer = Customer::where('customer_code', $data['customer_code'])->where('is_active', true)->first();

        if (! $customer) {
            return $this->sendError(400, 'INVALID_CUSTOMER', 'Invalid or inactive customer code.');
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'user_level' => UserLevel::USER,
            'customer_code' => $customer->customer_code,
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => null,
        ]);
        $user->customers()->attach($customer->id);

        $this->emailVerification->invalidateForEmail($user->email);
        $token = $this->emailVerification->createToken($user->email, 60);

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $verificationUrl = $frontendUrl.'/verify-email?token='.urlencode($token);

        Mail::to($user->email)->send(new EmailVerificationMail($verificationUrl, $user->name));

        return $this->sendCreated([
            'message' => 'Registration successful. Check your email to verify your address before signing in.',
            'email' => $user->email,
        ]);
    }

    /**
     * Request password reset link (always same response to avoid email enumeration).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && $this->appNotifications->mailAppearsConfigured()) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );
            $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
            $resetUrl = $frontendUrl.'/admin/reset-password?token='.urlencode($token).'&email='.urlencode($user->email);
            try {
                Mail::to($user->email)->send(new PasswordResetMail($resetUrl, $user->name));
            } catch (\Throwable) {
                // Swallow — generic response below
            }
        }

        return $this->sendOk([
            'message' => 'If an account exists for this email, password reset instructions have been sent.',
        ]);
    }

    /**
     * Complete password reset using token from email.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return $this->sendError(400, 'INVALID_TOKEN', 'Invalid or expired reset link.');
        }

        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            return $this->sendError(400, 'INVALID_TOKEN', 'Reset link has expired. Request a new one.');
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return $this->sendError(404, 'NOT_FOUND', 'User not found');
        }

        $user->update(['password' => Hash::make($data['password'])]);
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return $this->sendOk(['message' => 'Password has been reset. You can sign in now.']);
    }

    /**
     * Resend registration verification email (self-service users only meaningful).
     */
    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if ($user && ! $user->email_verified_at && $this->appNotifications->mailAppearsConfigured()) {
            $this->emailVerification->invalidateForEmail($user->email);
            $token = $this->emailVerification->createToken($user->email, 60);
            $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
            $verificationUrl = $frontendUrl.'/verify-email?token='.urlencode($token);
            try {
                Mail::to($user->email)->send(new EmailVerificationMail($verificationUrl, $user->name));
            } catch (\Throwable) {
                // generic message
            }
        }

        return $this->sendOk([
            'message' => 'If this account exists and is not yet verified, a new verification email has been sent.',
        ]);
    }

    /**
     * Verify email with token from registration.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $token = $request->input('token');
        if (! $token) {
            return $this->sendError(400, 'MISSING_TOKEN', 'Verification token is required.');
        }

        $email = $this->emailVerification->verifyToken($token);
        if (! $email) {
            return $this->sendError(400, 'INVALID_TOKEN', 'Invalid or expired verification link.');
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            return $this->sendError(404, 'USER_NOT_FOUND', 'User not found.');
        }

        $user->update(['email_verified_at' => now()]);

        return $this->sendOk([
            'message' => 'Email verified. You can sign in now.',
        ]);
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user) {
            $this->auditService->logAuth('logout', $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->sendOk(['success' => true]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $this->userPayload($user);

        $impersonatedBy = session('impersonated_by');
        if ($impersonatedBy) {
            $payload['impersonating'] = true;
            $payload['impersonated_by'] = (int) $impersonatedBy;
        }

        return $this->sendOk([
            'user' => $payload,
        ]);
    }

    /**
     * Impersonate another user.
     * Super Admin: Levels 1–4. Internal Admin: 1–4 (not 0). External Admin: agent, user. Agent: user only.
     */
    public function impersonate(Request $request): JsonResponse
    {
        $realUser = $request->user();
        $level = UserLevel::normalize($realUser->user_level ?? UserLevel::USER);
        $allowedTargets = $this->impersonateAllowedTargets($level);
        if (empty($allowedTargets)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot impersonate');
        }

        $userId = (int) $request->input('user_id');
        $target = User::find($userId);
        if (! $target || ! $target->is_active) {
            return $this->sendError(404, 'NOT_FOUND', 'User not found or inactive');
        }

        if ($target->id === $realUser->id) {
            return $this->sendError(400, 'BAD_REQUEST', 'Cannot impersonate yourself');
        }

        $targetLevel = UserLevel::normalize($target->user_level ?? UserLevel::USER);
        if (! in_array($targetLevel, $allowedTargets, true)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot impersonate this user');
        }

        $request->session()->put('impersonate_user_id', $target->id);
        $request->session()->put('impersonated_by', $realUser->id);

        $this->auditService->log('impersonate', $realUser, 'user', $target->id, null, ['target' => $target->email]);

        return $this->sendOk([
            'user' => $this->userPayload($target),
            'impersonating' => true,
            'impersonated_by' => $realUser->id,
        ]);
    }

    /** @return string[] User levels the current user can impersonate */
    private function impersonateAllowedTargets(string $level): array
    {
        return UserLevel::impersonatableTargetLevels($level);
    }

    /**
     * Stop impersonating.
     */
    public function stopImpersonate(Request $request): JsonResponse
    {
        $request->session()->forget(['impersonate_user_id', 'impersonated_by']);
        $user = $request->user();

        return $this->sendOk([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * List users for impersonation dropdown.
     * Matches impersonateAllowedTargets().
     */
    public function impersonateUsers(Request $request): JsonResponse
    {
        $realUser = $request->user();
        $level = UserLevel::normalize($realUser->user_level ?? UserLevel::USER);
        $allowedTargets = $this->impersonateAllowedTargets($level);
        if (empty($allowedTargets)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot impersonate');
        }

        $q = $request->input('q');
        $query = User::where('is_active', true)
            ->where('id', '!=', $realUser->id)
            ->whereIn('user_level', $allowedTargets);
        if (UserLevel::normalize($level) !== UserLevel::SUPER_ADMIN) {
            $visibleIds = $this->hierarchy->visibleUserIdsFor($realUser, true);
            if ($visibleIds === []) {
                return $this->sendOk([]);
            }
            $query->whereIn('id', $visibleIds);
        }
        if ($q && strlen($q) >= 2) {
            $query->where(function ($b) use ($q) {
                $b->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%');
            });
        }
        $users = $query->orderBy('name')->limit(50)->get(['id', 'name', 'email']);

        return $this->sendOk($users->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values()->all());
    }

    /**
     * Update the authenticated user's profile (name and/or email).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [];
        if ($request->has('name')) {
            $data['name'] = $request->input('name');
        }
        if ($request->has('email')) {
            $data['email'] = $request->input('email');
        }

        $user->update($data);
        $user->refresh();

        return $this->sendOk([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return $this->sendError(400, 'INVALID_PASSWORD', 'Current password is incorrect');
        }

        $user->update([
            'password' => Hash::make($request->input('new_password')),
        ]);

        return $this->sendOk(['message' => 'Password changed successfully']);
    }

    /**
     * Upload an avatar for the authenticated user.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:png,jpg,jpeg,gif,webp|max:2048',
        ]);

        $user = $request->user();

        // Remove old avatar if exists
        if ($user->photo_url) {
            $oldPath = 'uploads/'.basename($user->photo_url);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $filename = 'avatar-'.time().'.'.$ext;

        $file->storeAs('uploads', $filename, 'public');

        $user->update([
            'photo_url' => '/storage/uploads/'.$filename,
        ]);
        $user->refresh();

        return $this->sendOk([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Remove the authenticated user's avatar.
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->photo_url) {
            $oldPath = 'uploads/'.basename($user->photo_url);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $user->update(['photo_url' => null]);
        $user->refresh();

        return $this->sendOk([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Build the user payload for API responses.
     * Includes menu_access from roles for RBAC menu filtering.
     */
    protected function userPayload($user): array
    {
        $user->loadMissing('roles');
        $menuAccess = $this->resolveMenuAccess($user);
        $permissions = $user->getAllPermissions();
        $customerRow = $user->customers()->first();

        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'photo_url' => $user->photo_url,
            'role' => $user->role,
            'user_level' => UserLevel::normalize($user->user_level ?? UserLevel::USER),
            'customer_code' => $user->customer_code,
            'customer_display_name' => $customerRow?->customer_name ?? $user->customer_code,
            'system_display_name' => $customerRow?->system_name,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'menu_access' => $menuAccess,
            'permissions' => $permissions,
        ];
    }

    /**
     * Resolve menu access from user's roles.
     * Super Admin: full access (null) UNLESS they have roles with non-empty menu_access — then respect it (so they can test).
     * Others: merge from roles with non-empty menu_access. If all empty, no access ([]).
     */
    protected function resolveMenuAccess($user): ?array
    {
        $roles = $user->roles;
        if ($roles->isEmpty()) {
            return $user->user_level === UserLevel::SUPER_ADMIN ? null : [];
        }

        $merged = [];
        foreach ($roles as $role) {
            $ma = $role->menu_access ?? [];
            if (! empty($ma)) {
                $merged = array_values(array_unique(array_merge($merged, $ma)));
            }
        }

        if (empty($merged)) {
            return $user->user_level === UserLevel::SUPER_ADMIN ? null : [];
        }

        return $merged;
    }
}
