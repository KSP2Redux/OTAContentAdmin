<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('openidconnect')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $identity = Socialite::driver('openidconnect')->user();
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('auth.login')->withErrors(['sso' => 'Authentik sign-in failed.']);
        }

        $claims = $identity->getRaw();
        $groups = array_values(array_filter((array) ($claims['groups'] ?? []), 'is_string'));
        abort_unless(in_array(config('ota.auth.publisher_group'), $groups, true), 403, 'You are not a Redux OTA publisher.');

        $user = User::query()->updateOrCreate(
            ['oidc_sub' => $identity->getId()],
            ['name' => $identity->getName() ?: $identity->getNickname() ?: $identity->getId(), 'email' => $identity->getEmail(), 'groups' => $groups, 'last_login_at' => now()],
        );

        Auth::login($user);
        request()->session()->regenerate();
        request()->session()->put('oidc_id_token', $identity->accessTokenResponseBody['id_token'] ?? null);
        if (is_string($claims['sid'] ?? null)) {
            DB::table('oidc_sessions')->updateOrInsert(['sid' => $claims['sid']], [
                'laravel_session_id' => request()->session()->getId(),
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return redirect()->intended('/admin');
    }

    public function logout(): RedirectResponse
    {
        $idToken = request()->session()->get('oidc_id_token');
        Auth::logout();

        return Socialite::driver('openidconnect')->logout($idToken, route('auth.logout.callback'));
    }

    public function logoutCallback(Request $request): RedirectResponse
    {
        abort_unless(Socialite::driver('openidconnect')->validateLogoutState($request), 403);
        DB::table('oidc_sessions')->where('laravel_session_id', $request->session()->getId())->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('auth.login');
    }

    public function backchannelLogout(Request $request): Response
    {
        try {
            $claims = Socialite::driver('openidconnect')->verifyLogoutToken($request->input('logout_token'));
        } catch (Throwable) {
            return response('', 400);
        }
        $query = DB::table('oidc_sessions');
        if (is_string($claims['sid'] ?? null)) {
            $query->where('sid', $claims['sid']);
        } else {
            $userId = User::query()->where('oidc_sub', $claims['sub'] ?? null)->value('id');
            $query->where('user_id', $userId ?? 0);
        }
        $rows = $query->get();
        foreach ($rows as $row) {
            Session::getHandler()->destroy($row->laravel_session_id);
        }
        DB::table('oidc_sessions')->whereIn('sid', $rows->pluck('sid'))->delete();

        return response('', 200);
    }
}
