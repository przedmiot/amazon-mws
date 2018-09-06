<?php
namespace MCS\Exception;


use Throwable;

class MWSProductValidationException extends \Exception
{
    public $errors = [];

    public function __construct(string $message = "", int $code = 0, array $errors)
    {
        $this->errors = $errors;
        return parent::__construct($message, $code);
    }
}