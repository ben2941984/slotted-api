<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use App\Services\GoogleService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class GoogleOAuthController extends Controller
{
    private string $redirectUri;

    public function __construct()
    {
        $this->redirectUri = config('app.url') . '/api/auth/google/callback';
    }

    // GET /api/auth/google/redirect?token=XXX  (public — token in query param)
    public function redirect(Request $request)
    {
        $token = $request->query('token', '');
        $pat   = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        $user  = $pat?->tokenable;

        if (!$user) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $s = UserSetting::where('user_id', $user->id)->firstOrFail();

        if (empty($s->google_client_id)) {
            return response()->json(['message' => 'No Google Client ID saved in settings.'], 422);
        }

        $url = (new GoogleService())->authUrl($s->google_client_id, $this->redirectUri, $token);

        return redirect($url);
    }

    // DELETE /api/auth/google/disconnect  (auth:sanctum)
    public function disconnect(Request $request)
    {
        UserSetting::where('user_id', $request->user()->id)->update([
            'google_access_token'  => null,
            'google_refresh_token' => null,
            'google_token_expires' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    // GET /api/auth/google/callback  (public — Google redirects here)
    public function callback(Request $request)
    {
        $code  = $request->query('code', '');
        $state = $request->query('state', '');

        $frontendBase = env('FRONTEND_URL', 'http://localhost:4322');

        if (!$code || !$state) {
            return redirect($frontendBase . '/dashboard/settings?google=error&reason=no_code');
        }

        // Identify user from Sanctum token passed as state
        $pat  = PersonalAccessToken::findToken($state);
        $user = $pat?->tokenable;

        if (!$user) {
            return redirect($frontendBase . '/dashboard/settings?google=error&reason=invalid_token');
        }

        $s = UserSetting::where('user_id', $user->id)->firstOrFail();

        \Log::info('Google callback', [
            'redirect_uri' => $this->redirectUri,
            'client_id'    => $s->google_client_id,
            'has_secret'   => !empty($s->google_client_secret),
            'code_len'     => strlen($code),
        ]);

        $ok = (new GoogleService())->exchangeCode($s, $code, $this->redirectUri);

        if (!$ok) {
            return redirect($frontendBase . '/dashboard/settings?google=error&reason=exchange_failed');
        }

        return redirect($frontendBase . '/dashboard/settings?google=connected');
    }
}
