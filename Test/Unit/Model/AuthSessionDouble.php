<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model;

use Magento\Backend\Model\Auth\Session;

/**
 * Auth session double: getUser() is a magic method on the real class, so it is declared here.
 */
class AuthSessionDouble extends Session
{
    /**
     * @var mixed
     */
    private $user;

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $error;

    /**
     * @param mixed $user
     * @param \Throwable|null $error
     */
    public function __construct($user = null, ?\Throwable $error = null)
    {
        $this->user = $user;
        $this->error = $error;
    }

    /**
     * Return the configured user or throw the configured error.
     *
     * @return mixed
     * @throws \Throwable
     */
    public function getUser()
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->user;
    }
}
