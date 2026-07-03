<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\UserSetting;
use DateTime;
use DateTimeZone;

class SlotService
{
    public function generateSlots(UserSetting $s, string $timezone = 'Europe/Berlin'): array
    {
        $tz            = new DateTimeZone($timezone);
        $slotMinutes   = max(5, (int) $s->slot_minutes);
        $bufferMinutes = max(0, (int) $s->buffer_minutes);
        $daysAhead     = max(1, (int) $s->days_ahead);
        $leadHours     = max(0, (int) $s->booking_lead_hours);

        $workdays = array_map('intval', array_filter(
            array_map('trim', explode(',', (string) $s->workdays))
        ));

        [$sh, $sm] = $this->parseTime((string) $s->day_start);
        [$eh, $em] = $this->parseTime((string) $s->day_end);

        $today    = new DateTime('today', $tz);
        $earliest = (new DateTime('now', $tz))->modify("+{$leadHours} hours");

        $rangeStart = (clone $today)->setTime(0, 0, 0);
        $rangeEnd   = (clone $today)->modify("+{$daysAhead} days")->setTime(23, 59, 59);

        $caldavBusy = [];
        if ($s->caldav_url && $s->caldav_user && $s->caldav_pass) {
            $caldavBusy = (new CalDavService())->getBusy($s, $rangeStart, $rangeEnd, $tz);
        }

        $out = [];

        for ($d = 0; $d <= $daysAhead; $d++) {
            $day     = (clone $today)->modify("+{$d} days");
            $weekday = (int) $day->format('N');
            $ymd     = $day->format('Y-m-d');

            if (!in_array($weekday, $workdays, true)) continue;
            if ($this->isBlackout($ymd, (string) $s->blackout_dates)) continue;

            $cursor = (clone $day)->setTime($sh, $sm, 0);
            $endDay = (clone $day)->setTime($eh, $em, 0);

            while (true) {
                $slotEnd = (clone $cursor)->modify("+{$slotMinutes} minutes");
                if ($slotEnd > $endDay) break;

                $checkStart = (clone $cursor)->modify("-{$bufferMinutes} minutes");
                $checkEnd   = (clone $slotEnd)->modify("+{$bufferMinutes} minutes");

                if (
                    $cursor >= $earliest
                    && !$this->overlapsExisting($s->user_id, $checkStart, $checkEnd)
                    && !$this->overlapsCalDav($caldavBusy, $checkStart, $checkEnd)
                ) {
                    $out[$ymd][] = [
                        'start' => $cursor->format('Y-m-d H:i:s'),
                        'end'   => $slotEnd->format('Y-m-d H:i:s'),
                        'label' => $cursor->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                    ];
                }

                $cursor->modify("+{$slotMinutes} minutes");
            }
        }

        return $out;
    }

    private function parseTime(string $hhmm): array
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m)) return [9, 0];
        return [(int) $m[1], (int) $m[2]];
    }

    private function isBlackout(string $ymd, string $raw): bool
    {
        if (trim($raw) === '') return false;
        return in_array($ymd, array_map('trim', explode(',', $raw)), true);
    }

    private function overlapsExisting(int $userId, DateTime $start, DateTime $end): bool
    {
        return Booking::where('user_id', $userId)
            ->where('status', 'confirmed')
            ->where('start_dt', '<', $end->format('Y-m-d H:i:s'))
            ->where('end_dt', '>', $start->format('Y-m-d H:i:s'))
            ->exists();
    }

    private function overlapsCalDav(array $busy, DateTime $start, DateTime $end): bool
    {
        foreach ($busy as [$bs, $be]) {
            if (!($end <= $bs || $start >= $be)) return true;
        }
        return false;
    }
}
