<?php

namespace App\Domain\Auth;

interface JwtTokenServiceInterface
{
    /**
     * Validate and decode a JWT. Returns the claims array.
     *
     * @throws InvalidTokenException
     */
    public function validateToken(string $token): array;
}
