<?php
namespace Panadas\LoggerPlugin;

use Monolog\Logger as BaseLogger;
use Panadas\Framework\Application;
use Panadas\Framework\ApplicationAwareInterface;
use Panadas\Framework\ApplicationAwareTrait;
use Panadas\LoggerPlugin\Handler\Console;

class Logger extends BaseLogger implements ApplicationAwareInterface
{

    use ApplicationAwareTrait;

    private $consoleHandler;

    public function __construct(
        Application $application,
        array $handlers = [],
        array $processors = [],
        Console $consoleHandler = null
    ) {
        if ($application->isDebugMode()) {
            if (null === $consoleHandler) {
                $consoleHandler = new Console($application);
            }
            $handlers[] = $consoleHandler;
        }

        parent::__construct($application->getName(), $handlers, $processors);

        $this
            ->setApplication($application)
            ->setConsoleHandler($consoleHandler);
    }

    public function getConsoleHandler()
    {
        return $this->consoleHandler;
    }

    public function hasConsoleHandler()
    {
        return (null !== $this->getConsoleHandler());
    }

    protected function setConsoleHandler(Console $consoleHandler = null)
    {
        $this->consoleHandler = $consoleHandler;

        return $this;
    }

    protected function removeConsoleHandler()
    {
        return $this->setConsoleHandler(null);
    }
}
