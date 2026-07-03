<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use App\Services\CalDavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $s = UserSetting::firstOrNew(['user_id' => $request->user()->id]);
        $data = $s->toArray();
        $data['google_connected']  = $s->googleConnected();
        $data['has_caldav_pass']   = !empty($s->caldav_pass);
        $data['has_google_secret'] = !empty($s->google_client_secret);
        unset($data['google_client_secret']);
        return response()->json($data);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug'                 => 'nullable|string|max:64|regex:/^[a-z0-9\-]+$/',
            'owner_name'           => 'nullable|string|max:120',
            'slot_minutes'         => 'nullable|integer|min:5|max:480',
            'buffer_minutes'       => 'nullable|integer|min:0|max:240',
            'days_ahead'           => 'nullable|integer|min:1|max:365',
            'booking_lead_hours'   => 'nullable|integer|min:0|max:720',
            'workdays'             => 'nullable|string|max:20',
            'day_start'            => 'nullable|string|max:5',
            'day_end'              => 'nullable|string|max:5',
            'blackout_dates'       => 'nullable|string|max:1000',
            'embed_theme'          => 'nullable|in:light,dark',
            'embed_layout'         => 'nullable|in:default,mini',
            'send_customer_email'  => 'nullable|boolean',
            'caldav_user'          => 'nullable|string|max:200',
            'caldav_pass'          => 'nullable|string|max:200',
            'caldav_url'           => 'nullable|url|max:500',
            'google_client_id'     => 'nullable|string|max:200',
            'google_client_secret' => 'nullable|string|max:200',
        ]);

        // Prevent slug collision with other users
        if (!empty($data['slug'])) {
            $taken = UserSetting::where('slug', $data['slug'])
                ->where('user_id', '!=', $request->user()->id)
                ->exists();
            if ($taken) {
                return response()->json(['message' => 'This slug is already taken.'], 422);
            }
        }

        // Don't overwrite sensitive fields with empty or null value
        foreach (['google_client_secret', 'caldav_pass'] as $field) {
            if (array_key_exists($field, $data) && empty($data[$field])) {
                unset($data[$field]);
            }
        }

        UserSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );

        return response()->json(['ok' => true]);
    }

    public function caldavTest(Request $request): JsonResponse
    {
        $s = UserSetting::where('user_id', $request->user()->id)->first();
        if (!$s?->caldav_url) return response()->json(['error' => 'No CalDAV configured']);

        $tz    = new \DateTimeZone('Europe/Berlin');
        $start = new \DateTime('today', $tz);
        $end   = (clone $start)->modify('+30 days');
        $busy  = (new CalDavService())->getBusy($s, $start, $end, $tz);

        return response()->json([
            'count' => count($busy),
            'busy'  => array_map(fn($b) => [
                'start' => $b[0]->format('Y-m-d H:i:s e'),
                'end'   => $b[1]->format('Y-m-d H:i:s e'),
            ], $busy),
        ]);
    }

    public function caldavDisconnect(Request $request): JsonResponse
    {
        UserSetting::where('user_id', $request->user()->id)->update([
            'caldav_user' => null,
            'caldav_pass' => null,
            'caldav_url'  => null,
        ]);
        return response()->json(['ok' => true]);
    }

    public function caldavDiscover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caldav_user' => 'required|email',
            'caldav_pass' => 'required|string',
        ]);

        $url = (new CalDavService())->discoverUrl($data['caldav_user'], $data['caldav_pass']);

        if (!$url) {
            return response()->json(['message' => 'Could not detect calendar URL. Check your Apple ID and app password.'], 422);
        }

        return response()->json(['url' => $url]);
    }
}
