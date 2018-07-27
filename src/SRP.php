<?php

namespace APPelit\SRP;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \APPelit\SRP\ChallengeResponse challenge(string $identifier, string $salt, string $verifier)
 * @method static \APPelit\SRP\AuthenticateResponse authenticate(string $session, string $A, string $M1)
 */
final class SRP extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'srp';
    }
}
