<?php
namespace Panadas\LoggerModule;

use Monolog\Logger as BaseLogger;
use Panadas\Framework\Application;
use Panadas\Framework\ApplicationAwareInterface;
use Panadas\Framework\ApplicationAwareTrait;

class Logger extends BaseLogger implements ApplicationAwareInterface
{

    use ApplicationAwareTrait;

    public function __construct(Application $application, array $handlers = [], array $processors = [])
    {
        parent::__construct($application->getName(), $handlers, $processors);

        $this->setApplication($application);
    }
}
