<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\MailService;
use App\Services\CalDavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->orderBy('start_dt', 'desc')
            ->limit(500)
            ->get(['id', 'name', 'email', 'note', 'start_dt', 'end_dt', 'status', 'is_google_meet', 'google_meet_link']);

        return response()->json($bookings);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) abort(403);
        if ($booking->status === 'cancelled') {
            return response()->json(['message' => 'Already cancelled.'], 422);
        }

        $this->doCancelIntegrations($booking);
        $booking->update(['status' => 'cancelled']);

        return response()->json(['ok' => true]);
    }

    public function cancelPublic(Request $request, string $token): JsonResponse
    {
        $booking = Booking::where('cancel_token', $token)->where('status', 'confirmed')->firstOrFail();
        $s       = $booking->user->settings;

        $this->doCancelIntegrations($booking);
        $booking->update(['status' => 'cancelled']);

        if ($s) (new MailService())->sendCancellation($booking, $s);

        return response()->json(['ok' => true, 'name' => $booking->name]);
    }

    private function doCancelIntegrations(Booking $booking): void
    {
        $s = $booking->user->settings ?? null;
        if (!$s) return;

        if ($booking->caldav_uid && $s->caldav_url && $s->caldav_user) {
            (new CalDavService())->deleteEvent($s, $booking->caldav_uid);
        }

        // Google Calendar deletion would go here when GoogleService is added
    }
}
