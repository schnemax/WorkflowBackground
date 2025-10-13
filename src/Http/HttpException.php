<?php
namespace App\Http;

class HttpException extends \RuntimeException
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        $this->status = $status;
        parent::__construct($message, $status);
    }
}
