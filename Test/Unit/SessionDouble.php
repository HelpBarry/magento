<?php

namespace Bluebarry\Bluebarry\Test\Unit;

use Bluebarry\Bluebarry\Model\Session;

/**
 * Session getSession()/setSession() are magic (SessionManager::__call). PHPUnit 12 can no longer mock
 * magic methods, so this double implements them for real and records writes. Works on PHPUnit 9-12.
 */
class SessionDouble extends Session
{
    /** @var mixed */
    public $stored = null;

    /** @var array<int, mixed> */
    public array $writes = [];

    public function __construct()
    {
    }

    /**
     * @return mixed
     */
    public function getSession()
    {
        return $this->stored;
    }

    /**
     * @param mixed $value
     * @return $this
     */
    public function setSession($value)
    {
        $this->writes[] = $value;
        $this->stored = $value;
        return $this;
    }
}
