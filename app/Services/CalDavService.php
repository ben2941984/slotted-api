<?php

namespace App\Services;

use App\Models\UserSetting;
use DateTime;
use DateTimeZone;

class CalDavService
{
    public function discoverUrl(string $user, string $pass): ?string
    {
        $base = 'https://caldav.icloud.com';

        // PROPFIND principal
        $body = '<?xml version="1.0" encoding="UTF-8"?><D:propfind xmlns:D="DAV:"><D:prop><D:current-user-principal/></D:prop></D:propfind>';
        $xmlHeaders = ['Content-Type: application/xml; charset=utf-8', 'Depth: 0'];
        $res  = $this->request('PROPFIND', $base . '/', $user, $pass, $body, $xmlHeaders);
        if (!$res) return null;

        if (preg_match('|<D:href>(.*?)</D:href>|', $res, $m)) {
            $principal = rtrim($m[1], '/');
            // find calendar-home-set
            $body2 = '<?xml version="1.0" encoding="UTF-8"?><D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:prop><C:calendar-home-set/></D:prop></D:propfind>';
            $res2  = $this->request('PROPFIND', $base . $principal, $user, $pass, $body2, $xmlHeaders);
            if ($res2 && preg_match('|calendar-home-set.*?<D:href>(.*?)</D:href>|s', $res2, $m2)) {
                return $base . $m2[1];
            }
        }

        return null;
    }

    public function getBusy(UserSetting $s, DateTime $start, DateTime $end, DateTimeZone $tz): array
    {
        $dtStart = (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $dtEnd   = (clone $end)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

        $body = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop><d:getetag/><c:calendar-data/></d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="{$dtStart}" end="{$dtEnd}"/>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>
XML;

        $res = $this->request('REPORT', $s->caldav_url, $s->caldav_user, $s->caldav_pass, $body, ['Content-Type: application/xml; charset=utf-8', 'Depth: 1']);
        if (!$res) return [];

        return $this->parseBusyTimes($res, $tz);
    }

    public function createEvent(UserSetting $s, array $data): ?string
    {
        $uid  = strtoupper(bin2hex(random_bytes(8))) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $ics  = $this->buildIcs($uid, $data);
        $url  = rtrim($s->caldav_url, '/') . '/' . $uid . '.ics';
        $res  = $this->request('PUT', $url, $s->caldav_user, $s->caldav_pass, $ics, ['Content-Type: text/calendar; charset=utf-8']);
        return $res !== null ? $uid : null;
    }

    public function deleteEvent(UserSetting $s, string $uid): void
    {
        $url = rtrim($s->caldav_url, '/') . '/' . $uid . '.ics';
        $this->request('DELETE', $url, $s->caldav_user, $s->caldav_pass, '', []);
    }

    private function buildIcs(string $uid, array $d): string
    {
        $now  = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');
        $dtS  = (new DateTime($d['start']))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $dtE  = (new DateTime($d['end']))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $summary = $this->escapeIcs($d['name'] ?? 'Booking');
        $desc    = $this->escapeIcs($d['note'] ?? '');

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Slotted//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "DTSTAMP:{$now}\r\n"
            . "DTSTART:{$dtS}\r\n"
            . "DTEND:{$dtE}\r\n"
            . "SUMMARY:{$summary}\r\n"
            . ($desc ? "DESCRIPTION:{$desc}\r\n" : '')
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function parseBusyTimes(string $xml, DateTimeZone $tz): array
    {
        $busy = [];
        preg_match_all('/DTSTART(;[^:]+)?:(\S+)/i', $xml, $starts);
        preg_match_all('/DTEND(;[^:]+)?:(\S+)/i', $xml, $ends);

        foreach ($starts[2] as $i => $raw) {
            $s = $this->parseDt($raw, $starts[1][$i] ?? '', $tz);
            $e = isset($ends[2][$i]) ? $this->parseDt($ends[2][$i], $ends[1][$i] ?? '', $tz) : null;
            if ($s && $e) $busy[] = [$s, $e];
        }

        return $busy;
    }

    private function parseDt(string $raw, string $param, DateTimeZone $local): ?DateTime
    {
        $raw = trim($raw);
        // TZID param
        if (preg_match('/TZID=([^:;\s]+)/i', $param, $m)) {
            try {
                return new DateTime($raw, new DateTimeZone($m[1]));
            } catch (\Throwable) {}
        }
        // UTC Z suffix
        if (str_ends_with($raw, 'Z')) {
            return new DateTime($raw, new DateTimeZone('UTC'));
        }
        // All-day
        if (preg_match('/^\d{8}$/', $raw)) {
            return new DateTime($raw, $local);
        }
        return new DateTime($raw, $local);
    }

    private function request(string $method, string $url, string $user, string $pass, string $body, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => "{$user}:{$pass}",
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($res !== false && $code < 400) ? $res : null;
    }

    private function escapeIcs(string $s): string
    {
        return str_replace(["\r\n", "\r", "\n", ',', ';'], ['\\n', '\\n', '\\n', '\\,', '\\;'], $s);
    }
}
