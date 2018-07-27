<?php

namespace APPelit\SRP;

use Illuminate\Contracts\Support\Jsonable;

final class AuthenticateResponse implements Jsonable, \JsonSerializable
{
    /** @var string */
    private $M2;

    /** @var string */
    private $sessionKey;

    /**
     * @param string $M2
     * @param string $sessionKey
     */
    public function __construct(string $M2, string $sessionKey)
    {
        $this->M2 = $M2;
        $this->sessionKey = $sessionKey;
    }

    /**
     * @return string
     */
    public function getM2(): string
    {
        return $this->M2;
    }

    /**
     * @return string
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'M2' => $this->M2,
        ];
    }
}
