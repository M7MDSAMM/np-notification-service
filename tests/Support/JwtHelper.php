<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

trait JwtHelper
{
    private string $privateKey;

    protected function setUpJwt(): void
    {
        [$private, $public] = $this->generateKeyPair();
        $this->privateKey = $private;

        config([
            'jwt.keys.public_content' => $public,
            'jwt.keys.public'         => null,
            'jwt.issuer'              => 'user-service',
            'jwt.audience'            => 'notification-platform',
        ]);
    }

    private function makeToken(string $role = 'admin'): string
    {
        $now = time();

        return JWT::encode([
            'iss'  => 'user-service',
            'aud'  => 'notification-platform',
            'sub'  => 'admin-uuid',
            'typ'  => 'admin',
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + 3600,
        ], $this->privateKey, 'RS256');
    }

    private function authHeaders(string $role = 'admin'): array
    {
        return ['Authorization' => 'Bearer ' . $this->makeToken($role)];
    }

    private function generateKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);

        return [$privateKey, $pub['key']];
    }
}
