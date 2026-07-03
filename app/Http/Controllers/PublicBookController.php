<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\UserSetting;
use App\Services\CalDavService;
use App\Services\MailService;
use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBookController extends Controller
{
    public function config(string $slug): JsonResponse
    {
        $s = UserSetting::where('slug', $slug)->firstOrFail();

        return response()->json([
            'owner_name'      => $s->owner_name ?? $s->user->name,
            'slot_minutes'    => $s->slot_minutes,
            'google_meet'     => $s->googleConnected(),
        ]);
    }

    public function slots(Request $request, string $slug): JsonResponse
    {
        $s   = UserSetting::where('slug', $slug)->firstOrFail();
        $tz  = $request->query('timezone', 'Europe/Berlin');
        if (!$this->validTimezone($tz)) $tz = 'Europe/Berlin';

        $slots = (new SlotService())->generateSlots($s, $tz);
        return response()->json($slots);
    }

    public function book(Request $request, string $slug): JsonResponse
    {
        $s = UserSetting::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'slot_start'     => 'required|date',
            'name'           => 'required|string|max:120',
            'email'          => 'required|email|max:200',
            'note'           => 'nullable|string|max:500',
            'is_google_meet' => 'nullable|boolean',
            'timezone'       => 'nullable|string|max:64',
        ]);

        $tz     = $this->validTimezone($data['timezone'] ?? '') ? ($data['timezone'] ?? 'UTC') : 'UTC';
        $start  = new \DateTime($data['slot_start']);
        $end    = (clone $start)->modify("+{$s->slot_minutes} minutes");

        // Check slot still free
        $taken = Booking::where('user_id', $s->user_id)
            ->where('status', 'confirmed')
            ->where('start_dt', '<', $end->format('Y-m-d H:i:s'))
            ->where('end_dt', '>', $start->format('Y-m-d H:i:s'))
            ->exists();

        if ($taken) {
            return response()->json(['message' => 'This slot is no longer available. Please pick another time.'], 409);
        }

        $caldavUid = null;
        if ($s->caldav_url && $s->caldav_user && $s->caldav_pass) {
            $caldavUid = (new CalDavService())->createEvent($s, [
                'start' => $start->format('Y-m-d H:i:s'),
                'end'   => $end->format('Y-m-d H:i:s'),
                'name'  => $data['name'],
                'note'  => $data['note'] ?? '',
            ]);
        }

        $booking = Booking::create([
            'user_id'         => $s->user_id,
            'start_dt'        => $start->format('Y-m-d H:i:s'),
            'end_dt'          => $end->format('Y-m-d H:i:s'),
            'name'            => $data['name'],
            'email'           => $data['email'],
            'note'            => $data['note'] ?? null,
            'ip'              => $request->ip(),
            'timezone'        => $tz,
            'status'          => 'confirmed',
            'is_google_meet'  => !empty($data['is_google_meet']),
            'caldav_uid'      => $caldavUid,
            'cancel_token'    => bin2hex(random_bytes(24)),
        ]);

        if ($s->send_customer_email) {
            (new MailService())->sendConfirmation($booking, $s);
        }

        return response()->json(['ok' => true, 'id' => $booking->id], 201);
    }

    private function validTimezone(string $tz): bool
    {
        return $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }
}
