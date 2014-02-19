<?php
namespace Panadas\LoggerPlugin\Handler;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\HandlerInterface;
use Panadas\Event\DataStructure\EventParams;
use Panadas\Event\Event;
use Panadas\Event\Publisher;
use Panadas\Framework\Application;
use Panadas\Framework\ApplicationAwareInterface;
use Panadas\Framework\ApplicationAwareTrait;
use Panadas\HttpMessage\HtmlResponse;
use Panadas\HttpMessage\Request;
use Panadas\LoggerPlugin\DataStructure\ConsoleOptions;
use Panadas\LoggerPlugin\DataStructure\Processors;
use Panadas\LoggerPlugin\DataStructure\Records;
use Panadas\LoggerPlugin\Logger;
use Panadas\LoggerPlugin\Formatter\Console as ConsoleFormatter;
use Panadas\Util\Php;
use Panadas\LoggerPlugin\DataStructure\ConsolePanels;

class Console extends Publisher implements HandlerInterface, ApplicationAwareInterface
{

    use ApplicationAwareTrait;

    private $level;
    private $bubble = true;
    private $requestDateTime;
    private $records;
    private $processors;
    private $formatter;

    public function __construct(
        Application $application,
        $level = Logger::DEBUG,
        $bubble = true,
        \DateTime $requestDateTime = null,
        Records $records = null,
        Processors $processors = null,
        FormatterInterface $formatter = null
    ) {
        parent::__construct();

        if (null === $requestDateTime) {
            $requestDateTime = new \DateTime();
        }

        if (null === $records) {
            $records = new Records();
        }

        if (null === $processors) {
            $processors = new Processors();
        }

        if (null === $formatter) {
            $formatter = new ConsoleFormatter();
        }

        $this
            ->setApplication($application)
            ->setLevel($level)
            ->setBubble($bubble)
            ->setRequestDateTime($requestDateTime)
            ->setRecords($records)
            ->setProcessors($processors)
            ->setFormatter($formatter);

        $application
            ->before("handle", [$this, "beforeHandleEvent"])
            ->before("send", [$this, "beforeSendEvent"]);
    }

    public function getLevel()
    {
        return $this->level;
    }

    protected function setLevel($level)
    {
        $this->level = (int) $level;

        return $this;
    }

    public function isBubble()
    {
        return $this->bubble;
    }

    protected function setBubble($bubble)
    {
        $this->bubble = (bool) $bubble;

        return $this;
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

    public function getRecords()
    {
        return $this->records;
    }

    protected function setRecords(Records $records)
    {
        $this->records = $records;

        return $this;
    }

    public function getProcessors()
    {
        return $this->processors;
    }

    protected function setProcessors(Processors $processors)
    {
        $this->processors = $processors;

        return $this;
    }

    public function isHandling(array $record)
    {
        return ($record["level"] >= $this->getLevel());
    }

    public function handle(array $record)
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        foreach ($this->getProcessors() as $processor) {
            $record = $processor($record);
        }

        $record["formatted"] = $this->getFormatter()->format($record);

        $this->getRecords()->append($record);

        return !$this->isBubble();
    }

    public function handleBatch(array $records)
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function pushProcessor($callback)
    {
        $this->getProcessors()->append($callback);

        return $this;
    }

    public function popProcessor()
    {
        return $this->getProcessors()->pop();
    }

    public function getFormatter()
    {
        return $this->formatter;
    }

    public function setFormatter(FormatterInterface $formatter)
    {
        $this->formatter = $formatter;

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

        $request = $event->getParams()->get("request");
        $response = $event->getParams()->get("response");

        if (!$response instanceof HtmlResponse) {
            return;
        }

        try {
            $response->appendContent($this->render($request, $response));
        } catch (\Exception $ignore) {
        }
    }

    protected function render(Request $request, HtmlResponse $response)
    {
        $event = $this->publish(
            "render",
            [$this, "onRenderEvent"],
            (new EventParams())
                ->set("request", $request)
                ->set("response", $response)
                ->set(
                    "options", (new ConsoleOptions())
                        ->set("selector", null)
                        ->set("timer", null)
                        ->set("panels", new ConsolePanels())
                        ->set("speed", null)
                )
        );

        $options = $event->getParams()->get("options");

        return '
            <link rel="stylesheet" href="/css/panadas.css">
            <script src="//code.jquery.com/jquery-2.1.0.min.js"></script>
            <script src="/js/panadas.js"></script>
            <script>
                jQuery(function() {
                    new panadas.console(jQuery, ' . json_encode($options). ');
                });
            </script>
            <div id="' . $response->escAttr(ltrim($options->get("selector"), "#")) . '"></div>
        ';
    }

    protected function onRenderEvent(Event $event)
    {
        $eventParams = $event->getParams();

        $request = $eventParams->get("request");
        $response = $eventParams->get("response");
        $options = $eventParams->get("options");

        if (null === $options->get("selector")) {
            $options->set("selector", "#panadas-console");
        }

        if (null === $options->get("timer")) {
            $options->set("timer", sprintf("%.3f", (microtime(true) - $this->getRequestDateTime()->format("U.u"))));
        }

        if (null === $options->get("speed")) {
            $options->set("speed", 200);
        }

        $panels = $options->get("panels");

        if (!$panels->has("log")) {
            $panels->set("log", $this->createLogPanel($request, $response));
        }

        if (!$panels->has("request")) {
            $panels->set("request", $this->createRequestPanel($request, $response));
        }
    }

    protected function createLogPanel(Request $request, HtmlResponse $response)
    {
        $requestTimestamp = $this->getRequestDateTime()->format("U.u");
        $records = $this->getRecords();

        $content = '
            <table>
        ';

        foreach ($records as $record) {

            if ($record["level"] >= Logger::ERROR) {
                $class = "panadas-text-error";
            } elseif ($record["level"] >= Logger::WARNING) {
                $class = "panadas-text-warning";
            } else {
                $class = "panadas-text-info";
            }

            $offset = sprintf("%.3f", ($record["datetime"]->format("U.u") - $requestTimestamp));

            $message = $response->esc($record["message"]);

            if ($record["context"]) {

                $context = [];
                foreach ($record["context"] as $key => $value) {
                    $context[] = "{$key}: " . Php::toString($value);
                }

                $message .= '
                    <br>
                    <small>' . $response->esc(implode("<br>", $context)) . '</small>
                ';

            }

            $content .= '
                <tr class="' . $response->escAttr($class) . '">
                    <td>' . $response->esc($offset) . 's</td>
                    <td>' . $response->esc($record["level_name"]) . '</td>
                    <td width="100%">' . $message . '</td>
                </tr>
            ';

        }

        $content .= "</table>";

        return [
            "label" => "Log",
            "counter" => count($records),
            "content" => $content
        ];
    }

    protected function createRequestPanel(Request $request, HtmlResponse $response)
    {
        $sources = [
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
        $content = '
            <table>
        ';

        foreach ($sources as $source) {

            $count = count($source["params"]);
            $counter += $count;

            $content .= '
                    <tr>
                        <th colspan="2">' . $response->esc($source["label"]) . '</th>
                    </tr>
            ';

            if ($count > 0) {

                foreach ($source["params"] as $key => $value) {
                    $content .= '
                        <tr>
                            <td class="panadas-text-right">' . $response->esc($key) . '</td>
                            <td class="panadas-text-info" width="100%">' . $response->esc($value) . '</td>
                        </tr>
                    ';
                }

            } else {

                $content .= '
                    <tr>
                        <td></td>
                        <td class="panadas-text-muted">No parameters provided</td>
                    </tr>
                ';

            }

        }

        $content .= '
            </table>
        ';

        return [
            "label" => "Request",
            "counter" => $counter,
            "content" => $content
        ];
    }
}
