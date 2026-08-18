<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private string $projectId;

    public function __construct()
    {
        $this->projectId = config('services.firebase.project_id', '');
    }

    /**
     * إرسال إشعار FCM لمستخدم واحد عبر HTTP v1 API
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (! $user->fcm_token || empty($this->projectId)) {
            return false;
        }

        return $this->send($user->fcm_token, $title, $body, $data);
    }

    /**
     * إرسال إشعار FCM لعدة مستخدمين
     */
    public function sendToMany(iterable $users, string $title, string $body, array $data = []): void
    {
        foreach ($users as $user) {
            if ($user instanceof User) {
                $this->sendToUser($user, $title, $body, $data);
            }
        }
    }

    /**
     * إرسال إشعار عبر Firebase HTTP v1 API
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            $accessToken = $this->getAccessToken();

            $payload = [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_map('strval', $data + [
                        'title' => $title,
                        'body' => $body,
                    ]),
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => 'school_notifications',
                            'sound' => 'default',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", $payload);

            if ($response->successful()) {
                return true;
            }

            Log::error('FCM send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'token' => substr($fcmToken, 0, 20) . '...',
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('FCM exception', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * توليد JWT access token من ملف service account باستخدام OpenSSL (بدون مكتبات خارجية)
     */
    private function getAccessToken(): string
    {
        return Cache::remember('fcm_access_token', 55 * 60, function () {
            $serviceAccountPath = config('services.firebase.service_account_path');

            if (! $serviceAccountPath || ! file_exists($serviceAccountPath)) {
                throw new \RuntimeException('Firebase service account file not found: ' . ($serviceAccountPath ?? 'not configured'));
            }

            $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

            $now = time();

            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = $this->base64UrlEncode(json_encode([
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signatureInput = "{$header}.{$payload}";

            openssl_sign($signatureInput, $signature, $serviceAccount['private_key'], 'SHA256');
            $encodedSignature = $this->base64UrlEncode($signature);

            $jwt = "{$header}.{$payload}.{$encodedSignature}";

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Failed to get FCM access token: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
