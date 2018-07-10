<?php


namespace MCS\Laravel;


use Psr\Log\AbstractLogger;

/**
 * Class LaravelLoggerAdapter
 * @package Itl\ShoperAppStoreFoundation
 *
 * It seems, there is no simple method to get a default logger instance in laravel 5.6. So, we need an adapter.
 */
class LaravelLoggerAdapter extends AbstractLogger
{
    public function log($level, $message, array $context = array())
    {
        \Log::$level($message, $context);
    }

}