<?php

namespace Bluebarry\Bluebarry\Model\Api;

/**
 * bluebarry's answer to one request.
 */
class Response
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var string
     */
    private $body;

    /**
     * @var string|null
     */
    private $error;

    /**
     * @param int $status 0 when no answer came
     * @param string $body
     * @param string|null $error
     */
    public function __construct(int $status, string $body, ?string $error = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->error = $error;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Worth trying again later: no answer, a timeout, rate limiting or a server error.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
