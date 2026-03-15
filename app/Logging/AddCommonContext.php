<?php

namespace App\Logging;

use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class AddCommonContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new class implements ProcessorInterface
        {
            public function __invoke(LogRecord $record): LogRecord
            {
                return $record->with(extra: array_merge($record->extra, [
                    'service' => env('SERVICE_NAME', config('app.name', 'notification-service')),
                ]));
            }
        });
    }
}
