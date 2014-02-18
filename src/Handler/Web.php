<?php
namespace Panadas\LoggerPlugin\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Panadas\EventModule\Event;
use Panadas\Framework\Application;
use Panadas\Framework\ApplicationAwareInterface;
use Panadas\Framework\ApplicationAwareTrait;
use Panadas\HttpMessageModule\HtmlResponse;
use Panadas\LoggerPlugin\DataStructure\Records;
use Panadas\LoggerPlugin\Logger;
use Panadas\LoggerPlugin\Formatter\Web as WebFormatter;
use Panadas\Util\Php;

class Web extends AbstractProcessingHandler implements ApplicationAwareInterface
{

    use ApplicationAwareTrait;

    private $requestDateTime;
    private $records;

    public function __construct(
        Application $application,
        $level = Logger::DEBUG,
        $bubble = true,
        \DateTime $requestDateTime = null,
        Records $records = null
    ) {
        parent::__construct($level, $bubble);

        if (null === $requestDateTime) {
            $requestDateTime = new \DateTime();
        }

        if (null === $records) {
            $records = new Records();
        }

        $this
            ->setApplication($application)
            ->setRequestDateTime($requestDateTime)
            ->setRecords($records);

        $application
            ->before("handle", [$this, "beforeHandleEvent"])
            ->before("send", [$this, "beforeSendEvent"]);
    }

    public function getRequestDateTime()
    {
        return $this->requestDateTime;
    }

    protected function setRequestDateTime(\DateTime $requestDateTime)
    {
        $this->requestDateTime = $requestDateTime;

        return $this;
    }

    protected function getRecords()
    {
        return $this->records;
    }

    protected function setRecords(Records $records)
    {
        $this->records = $records;

        return $this;
    }

    public function beforeHandleEvent(Event $event)
    {
        $serverParams = $event->getParams()->get("request")->getServerParams();

        if (!$serverParams->has("REQUEST_TIME_FLOAT")) {
            return;
        }

        $this->setRequestDateTime(\DateTime::createFromFormat("U.u", $serverParams->get("REQUEST_TIME_FLOAT")));
    }

    public function beforeSendEvent(Event $event)
    {
        $records = $this->getRecords();

        if (!$records->populated()) {
            return;
        }

        $response = $event->getParams()->get("response");
        if (!$response instanceof HtmlResponse) {
            return;
        }

        try {
            $response->appendContent($this->render($response));
        } catch (\Exception $ignore) {
        }
    }

    protected function write(array $record)
    {
        return $this->getRecords()->append($record);
    }

    protected function render(HtmlResponse $response)
    {
        $requestTimestamp = $this->getRequestDateTime()->format("U.u");

        $content = "<table class=\"table table-condensed\" id=\"#panadas-web-debug\" width=\"100%\">";

        foreach ($this->getRecords() as $record) {

            if ($record["level"] >= Logger::ERROR) {
                $class = "danger";
            } elseif ($record["level"] >= Logger::WARNING) {
                $class = "warning";
            } else {
                $class = "success";
            }

            $offset = number_format(($record["datetime"]->format("U.u") - $requestTimestamp), 3);
            $message = $response->esc($record["message"]);

            if ($record["context"]) {
                $context = [];
                foreach ($record["context"] as $key => $value) {
                    $context[] = "{$key}: " . Php::toString($value);
                }
                $message .= "<br><span class=\"text-muted\">" . $response->esc(implode("<br>", $context)) . "</span>";
            }

            $content .= "<tr class=\"{$response->escAttr($class)}\">";
            $content .= "<td><small><kbd>{$response->esc($offset)}s</kbd></small></td>";
            $content .= "<td><small><kbd>{$response->esc($record["level_name"])}</kbd></small></td>";
            $content .= "<td width=\"100%\"><small><kbd>{$message}</kbd></small></td>";
            $content .= "</tr>";

        }

        $content .= "</table>";

        return $content;
    }

    protected function getDefaultFormatter()
    {
        return new WebFormatter();
    }
}
