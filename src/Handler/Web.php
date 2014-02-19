<?php
namespace Panadas\LoggerPlugin\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Panadas\Event\Event;
use Panadas\Framework\Application;
use Panadas\Framework\ApplicationAwareInterface;
use Panadas\Framework\ApplicationAwareTrait;
use Panadas\HttpMessage\HtmlResponse;
use Panadas\HttpMessage\Request;
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

        $request = $event->getParams()->get("request");

        try {
            $response->appendContent($this->render($request, $response));
        } catch (\Exception $ignore) {
        }
    }

    protected function write(array $record)
    {
        return $this->getRecords()->append($record);
    }

    protected function render(Request $request, HtmlResponse $response)
    {
        $requestTimestamp = $this->getRequestDateTime();

        $options = [
            "selector" => "#panadas-console",
            "timer" => number_format(microtime(true) - $requestTimestamp->format("U.u"), 3),
            "panels" => [],
            "speed" => 200
        ];

        $records = $this->getRecords();

        $content = "<table>";

        foreach ($this->getRecords() as $record) {

            if ($record["level"] >= Logger::ERROR) {
                $class = "panadas-text-error";
            } elseif ($record["level"] >= Logger::WARNING) {
                $class = "panadas-text-warning";
            } elseif ($record["level"] >= Logger::INFO) {
                $class = "panadas-text-info";
            } else {
                $class = null;
            }

            $offset = number_format(($record["datetime"]->format("U.u") - $requestTimestamp->format("U.u")), 3);
            $message = $response->esc($record["message"]);

            if ($record["context"]) {
                $context = [];
                foreach ($record["context"] as $key => $value) {
                    $context[] = "{$key}: " . Php::toString($value);
                }
                $message .= "<br>";
                $message .= "<small>{$response->esc(implode("<br>", $context))}</small>";
            }

            $content .= "<tr class=\"{$class}\">";
            $content .= "<td>{$response->esc($offset)}s</td>";
            $content .= "<td>{$response->esc($record["level_name"])}</td>";
            $content .= "<td width=\"100%\">{$message}</td>";
            $content .= "</tr>";

        }

        $content .= "</table>";

        $options["panels"]["log"] = [
            "label" => "Log",
            "counter" => count($records),
            "content" => $content
        ];

        $parameters = [
            [
               "label" => "Query",
               "params" => $request->getQueryParams()
            ],
            [
               "label" => "Data",
               "params" => $request->getDataParams()
            ],
            [
               "label" => "Cookies",
               "params" => $request->getCookies()
            ],
            [
               "label" => "Server",
               "params" => $request->getServerParams()
            ],
        ];

        $counter = 0;
        $content = null;

        foreach ($parameters as $paramData) {

            $content .= "<table>";
            $content .= "<tr>";
            $content .= "<th colspan=\"2\">";
            $content .= "{$response->esc($paramData["label"])} ";
            $content .= "<span class=\"panadas-badge\">{$paramData["params"]->count()}</span>";
            $content .= "</th>";
            $content .= "</tr>";

            if ($paramData["params"]->populated()) {

                foreach ($paramData["params"] as $key => $value) {
                    $content .= "<tr>";
                    $content .= "<td>{$response->esc($key)}</td>";
                    $content .= "<td width=\"100%\">{$response->esc($value)}</td>";
                    $content .= "</tr>";
                    $counter++;
                }

            } else {

                $content .= "<tr>";
                $content .= "<td colspan=\"2\">&mdash;</td>";
                $content .= "</tr>";

            }

            $content .= "</table>";
        }

        $options["panels"]["request"] = [
            "label" => "Request",
            "counter" => $counter,
            "content" => $content
        ];

        return '
            <link rel="stylesheet" href="/css/panadas.css">
            <script src="//code.jquery.com/jquery-2.1.0.min.js"></script>
            <script src="/js/panadas.js"></script>
            <script>
                jQuery(function() {
                    new panadas.console(jQuery, ' . json_encode($options). ');
                });
            </script>
            <div id="' . $response->escAttr(ltrim($options["selector"], "#")) . '"></div>
        ';
    }

    protected function getDefaultFormatter()
    {
        return new WebFormatter();
    }
}
