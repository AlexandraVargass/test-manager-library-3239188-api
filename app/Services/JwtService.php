<?php

namespace App\Services;

class JwtService
{
    private string $secret;
    private int $ttl;

    public function __construct()
    {
        $this->secret = env('JWT_SECRET', 'manager_library_jwt_super_secret_key_adso_3239188');
        $this->ttl = (int) env('JWT_TTL', 28800); // 8 horas por defecto (CA-01.1)
    }

    public function generateToken(string $userId, string $role): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload = [
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ];

        $base64Header = $this->base64UrlEncode(json_encode($header));
        $base64Payload = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", $this->secret, true);
        $base64Signature = $this->base64UrlEncode($signature);

        return "$base64Header.$base64Payload.$base64Signature";
    }

    public function validateToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $expectedSig = hash_hmac('sha256', "$headerB64.$payloadB64", $this->secret, true);
        if (!hash_equals($expectedSig, $this->base64UrlDecode($signatureB64))) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
