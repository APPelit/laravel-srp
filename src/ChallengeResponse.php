<?php

namespace APPelit\SRP;

use Illuminate\Contracts\Support\Jsonable;

final class ChallengeResponse implements Jsonable, \JsonSerializable
{
    /** @var string */
    private $salt;

    /** @var string */
    private $B;

    /** @var string */
    private $session;

    /**
     * @param string $salt
     * @param string $B
     * @param string $session
     */
    public function __construct(string $salt, string $B, string $session)
    {
        $this->salt = $salt;
        $this->B = $B;
        $this->session = $session;
    }

    /**
     * @return string
     */
    public function getSalt(): string
    {
        return $this->salt;
    }

    /**
     * @return string
     */
    public function getB(): string
    {
        return $this->B;
    }

    /**
     * @return string
     */
    public function getSession(): string
    {
        return $this->session;
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
     * Specify data which should be serialized to JSON
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return [
            'session' => $this->session,
            'salt' => $this->salt,
            'B' => $this->B,
        ];
    }
}
