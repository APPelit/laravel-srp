<?php

namespace APPelit\SRP;

use APPelit\SRP\Exceptions\SrpDataException;
use APPelit\SRP\Exceptions\SrpSessionException;
use APPelit\SRP\Exceptions\SrpStepException;
use APPelit\SRP\Exceptions\SrpValidationException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Thinbus\ThinbusSrp;

final class SrpService
{
    /** @var Container */
    private $app;

    /**
     * @param \Illuminate\Contracts\Container\Container $app
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Perform the challenge part of the SRP flow
     * @param string $identifier
     * @param string $salt
     * @param string $verifier
     * @return \APPelit\SRP\ChallengeResponse
     * @throws \APPelit\SRP\Exceptions\SrpException
     */
    public function challenge(string $identifier, string $salt, string $verifier): ChallengeResponse
    {
        /** @var ThinbusSrp $srp */
        $srp = $this->app->make(ThinbusSrp::class);

        /** @var Cache $cache */
        $cache = $this->app->make('cache');

        try {
            $B = $srp->step1($identifier, $salt, $verifier);
        } catch (\Exception $e) {
            throw new SrpStepException($e->getMessage(), $e->getCode(), $e);
        }

        $time = '' . microtime(true);
        $session = hash('sha256', "{$time}{$identifier}");
        $cache->put("srp:challenge:{$session}", $srp, 5);

        return new ChallengeResponse($salt, $B, $session);
    }

    /**
     * @param string $session
     * @param string $A
     * @param string $M1
     * @return \APPelit\SRP\AuthenticateResponse
     * @throws \APPelit\SRP\Exceptions\SrpException
     */
    public function authenticate(string $session, string $A, string $M1): AuthenticateResponse
    {
        /** @var Cache $cache */
        $cache = $this->app->make('cache');

        // Pull the saved information from the cache
        if (
            !($srp = $cache->pull("srp:challenge:{$session}")) ||
            !($srp instanceof ThinbusSrp)
        ) {
            throw new SrpSessionException();
        }

        try {
            $M2 = $srp->step2($A, $M1);
        } catch (\Exception $e) {
            switch ($e->getMessage()) {
                case 'Possible dictionary attack refusing to collaborate.':
                    throw new SrpStepException($e->getMessage(), $e->getCode(), $e);
                case 'Client sent invalid key: A mod N == 0.':
                    throw new SrpDataException($e->getMessage(), $e->getCode(), $e);
                case 'Client M1 does not match Server M1.':
                    throw new SrpValidationException($e->getMessage(), $e->getCode(), $e);
            }

            throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
        }

        $sessionKey = $srp->getSessionKey();

        return new AuthenticateResponse($M2, $sessionKey);
    }
}
