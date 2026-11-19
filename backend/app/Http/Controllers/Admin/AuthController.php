<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * 管理画面のログイン。パスワード → 二要素認証（TOTP）の2段階で、両方通ってから初めてログイン状態にする。
 * 二要素認証が未登録の管理者は、パスワードのあとに登録画面へ進み、登録が済むとログインになる。
 */
class AuthController extends Controller
{
    private const PENDING_KEY = 'admin_login.pending_id';

    private const SETUP_SECRET_KEY = 'admin_login.setup_secret';

    public function __construct(private TwoFactor $twoFactor, private AuditLogger $audit) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.home');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $throttleKey = 'admin-login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->withErrors(['email' => 'ログインの試行回数が多すぎます。'.RateLimiter::availableIn($throttleKey).'秒後にお試しください。'])->onlyInput('email');
        }

        $admin = Admin::firstWhere('email', Str::lower($data['email']));

        if (! $admin || ! $admin->is_active || ! Hash::check($data['password'], $admin->password)) {
            RateLimiter::hit($throttleKey, 300);
            $this->audit->log($admin, 'login.failed', payload: ['email' => Str::lower($data['email'])]);

            return back()->withErrors(['email' => 'メールアドレスまたはパスワードが正しくありません。'])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->session()->put(self::PENDING_KEY, $admin->id);

        return redirect()->route($admin->hasTwoFactor() ? 'admin.two-factor.challenge' : 'admin.two-factor.setup');
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        $admin = $this->pendingAdmin($request);

        if (! $admin || ! $admin->hasTwoFactor()) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.challenge');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $admin = $this->pendingAdmin($request);

        if (! $admin || ! $admin->hasTwoFactor()) {
            return redirect()->route('admin.login');
        }

        $code = (string) $request->validate(['code' => ['required', 'string']])['code'];
        $throttleKey = 'admin-2fa:'.$admin->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->withErrors(['code' => '試行回数が多すぎます。しばらくしてからお試しください。']);
        }

        if (! $this->twoFactor->verifyAdmin($admin, $code)) {
            RateLimiter::hit($throttleKey, 300);
            $this->audit->log($admin, 'login.two_factor_failed');

            return back()->withErrors(['code' => 'コードが正しくありません。']);
        }

        RateLimiter::clear($throttleKey);

        return $this->completeLogin($request, $admin);
    }

    public function showSetup(Request $request): View|RedirectResponse
    {
        $admin = $this->pendingAdmin($request);

        if (! $admin || $admin->hasTwoFactor()) {
            return redirect()->route('admin.login');
        }

        $secret = $request->session()->get(self::SETUP_SECRET_KEY) ?? $this->twoFactor->generateSecret();
        $request->session()->put(self::SETUP_SECRET_KEY, $secret);

        return view('admin.auth.setup', [
            'secret' => $secret,
            'qrSvg' => $this->twoFactor->qrCodeSvg($admin->email, $secret),
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        $admin = $this->pendingAdmin($request);
        $secret = $request->session()->get(self::SETUP_SECRET_KEY);

        if (! $admin || $admin->hasTwoFactor() || ! $secret) {
            return redirect()->route('admin.login');
        }

        $code = (string) $request->validate(['code' => ['required', 'string']])['code'];
        $throttleKey = 'admin-2fa:'.$admin->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->withErrors(['code' => '試行回数が多すぎます。しばらくしてからお試しください。']);
        }

        if (! $this->twoFactor->verifySecret($secret, $code)) {
            RateLimiter::hit($throttleKey, 300);

            return back()->withErrors(['code' => 'コードが正しくありません。認証アプリに表示されている6桁を入力してください。']);
        }

        RateLimiter::clear($throttleKey);

        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget(self::SETUP_SECRET_KEY);
        $this->audit->log($admin, 'two_factor.enabled', 'admin', $admin->id);

        return $this->completeLogin($request, $admin);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->log(Auth::guard('admin')->user(), 'logout');
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function completeLogin(Request $request, Admin $admin): RedirectResponse
    {
        $request->session()->forget(self::PENDING_KEY);
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();
        $this->audit->log($admin, 'login');

        return redirect()->intended(route('admin.home'));
    }

    private function pendingAdmin(Request $request): ?Admin
    {
        $id = $request->session()->get(self::PENDING_KEY);
        $admin = $id ? Admin::find($id) : null;

        return $admin && $admin->is_active ? $admin : null;
    }
}
