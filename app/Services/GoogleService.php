<?php

namespace App\Services;

use App\Models\UserSetting;
use DateTime;
use DateTimeZone;

class GoogleService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function authUrl(string $clientId, string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/calendar.events',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    public function exchangeCode(UserSetting $s, string $code, string $redirectUri): bool
    {
        $data = $this->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $s->google_client_id,
            'client_secret' => $s->getRawOriginal('google_client_secret') ?? $s->google_client_secret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            \Log::error('Google exchange failed', ['response' => $data, 'redirect_uri' => $redirectUri]);
            return false;
        }

        $this->saveTokens($s, $data);
        return true;
    }

    public function createEvent(UserSetting $s, array $booking): array
    {
        if (!$this->ensureToken($s)) return ['success' => false, 'error' => 'Token expired'];

        $tz    = $booking['timezone'] ?? 'UTC';
        $start = (new DateTime($booking['start'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));
        $end   = (new DateTime($booking['end'],   new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));

        $body = [
            'summary' => 'Call with ' . $booking['name'],
            'description' => $booking['note'] ?? '',
            'start' => ['dateTime' => $start->format('c'), 'timeZone' => $tz],
            'end'   => ['dateTime' => $end->format('c'),   'timeZone' => $tz],
            'conferenceData' => [
                'createRequest' => [
                    'requestId'            => bin2hex(random_bytes(16)),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];

        $resp = $this->apiPost(
            self::EVENTS_URL . '?conferenceDataVersion=1&sendUpdates=none',
            $s->google_access_token,
            $body
        );

        if (!$resp || ($resp['error'] ?? null)) {
            return ['success' => false, 'error' => $resp['error']['message'] ?? 'API error'];
        }

        return [
            'success'   => true,
            'event_id'  => $resp['id'] ?? '',
            'meet_link' => $resp['hangoutLink'] ?? '',
        ];
    }

    public function deleteEvent(UserSetting $s, string $eventId): bool
    {
        if (!$eventId || !$this->ensureToken($s)) return false;

        $url = self::EVENTS_URL . '/' . urlencode($eventId);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $s->google_access_token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return in_array($code, [200, 204, 404]);
    }

    private function ensureToken(UserSetting $s): bool
    {
        if (empty($s->google_refresh_token) || empty($s->google_client_id)) return false;

        $expires = $s->google_token_expires;
        if ($expires && now()->gte($expires->subMinutes(5))) {
            return $this->refreshToken($s);
        }

        return !empty($s->google_access_token);
    }

    private function refreshToken(UserSetting $s): bool
    {
        $data = $this->post(self::TOKEN_URL, [
            'client_id'     => $s->google_client_id,
            'client_secret' => $s->getRawOriginal('google_client_secret') ?? $s->google_client_secret,
            'refresh_token' => $s->getRawOriginal('google_refresh_token') ?? $s->google_refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if (empty($data['access_token'])) return false;

        $this->saveTokens($s, $data);
        return true;
    }

    private function saveTokens(UserSetting $s, array $data): void
    {
        $expires = (new DateTime('now', new DateTimeZone('UTC')))
            ->modify('+' . (int)($data['expires_in'] ?? 3600) . ' seconds');

        $s->update([
            'google_access_token'  => $data['access_token'],
            'google_refresh_token' => $data['refresh_token'] ?? $s->getRawOriginal('google_refresh_token'),
            'google_token_expires' => $expires->format('Y-m-d H:i:s'),
        ]);
    }

    private function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res ? (json_decode($res, true) ?? []) : [];
    }

    private function apiPost(string $url, string $token, array $body): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res ? json_decode($res, true) : null;
    }
}
