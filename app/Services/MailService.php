<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\UserSetting;

class MailService
{
    public function sendConfirmation(Booking $b, UserSetting $s): void
    {
        $start    = $b->start_dt->format('D, d M Y H:i');
        $icsData  = $this->buildIcs($b);
        $ownerName = $s->owner_name ?? 'your contact';

        $text = "Hi {$b->name},\n\nYour booking is confirmed.\n\nDate: {$start}\n";
        if ($b->is_google_meet && $b->google_meet_link) {
            $text .= "Google Meet: {$b->google_meet_link}\n";
        } elseif ($b->note) {
            $text .= "Phone: {$b->note}\n";
        }
        $text .= "\nYour calendar invite is attached.\n\nTo cancel: " . url("/cancel/{$b->cancel_token}") . "\n";

        $this->send($b->email, "Booking confirmed — {$start}", $text, $icsData, "booking-{$b->id}.ics");

        // Owner notification
        $ownerText = "New booking from {$b->name} ({$b->email})\nDate: {$start}\n";
        if ($b->note) $ownerText .= "Note: {$b->note}\n";
        $this->send($s->caldav_user ?: $s->user->email, "New booking: {$b->name} — {$start}", $ownerText, $icsData, "booking-{$b->id}.ics");
    }

    public function sendCancellation(Booking $b, UserSetting $s): void
    {
        $start = $b->start_dt->format('D, d M Y H:i');
        $text  = "Hi {$b->name},\n\nYour booking on {$start} has been cancelled.\n";
        $this->send($b->email, "Booking cancelled — {$start}", $text);
    }

    private function send(string $to, string $subject, string $body, ?string $icsData = null, ?string $icsName = null): void
    {
        $from    = config('mail.from.address', 'noreply@slotted.de');
        $fromName = 'Slotted';

        if ($icsData && $icsName) {
            $boundary = uniqid('=_', true);
            $headers  = "From: {$fromName} <{$from}>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"";
            $message  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n"
                . "--{$boundary}\r\nContent-Type: text/calendar; charset=UTF-8; name=\"{$icsName}\"\r\n"
                . "Content-Disposition: attachment; filename=\"{$icsName}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($icsData)) . "\r\n--{$boundary}--";
        } else {
            $headers = "From: {$fromName} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
            $message = $body;
        }

        mail($to, $subject, $message, $headers);
    }

    private function buildIcs(Booking $b): string
    {
        $uid  = $b->caldav_uid ?? 'slotted-' . $b->id;
        $now  = gmdate('Ymd\THis\Z');
        $dtS  = $b->start_dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $dtE  = $b->end_dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $desc = $b->is_google_meet && $b->google_meet_link ? "Google Meet: {$b->google_meet_link}" : "Phone: {$b->note}";

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Slotted//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:{$now}\r\n"
            . "DTSTART:{$dtS}\r\n"
            . "DTEND:{$dtE}\r\n"
            . "SUMMARY:Call with {$b->name}\r\n"
            . "DESCRIPTION:{$desc}\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
