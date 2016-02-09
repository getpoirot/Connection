<?php
namespace Poirot\Connection\Http;

use Poirot\ApiClient\Exception\ApiCallException;
use Poirot\ApiClient\Exception\ConnectException;
use Poirot\Connection\AbstractConnection;
use Poirot\Core\Interfaces\iDataSetConveyor;
use Poirot\Core\Traits\CloneTrait;
use Poirot\Stream\Interfaces\iStreamable;
use Poirot\Stream\Streamable;
use Poirot\Stream\StreamClient;

/*
$httpRequest = new HttpRequest([
    'uri' => '/payam/',
    'headers' => [
        'Host'            => '95.211.189.240',
        'Accept-Encoding' => 'gzip',
        'Cache-Control'   => 'no-cache',
    ]
]);

$stream = new HttpSocketConnection(['server_url' => 'http://95.211.189.240/']);
$startTime = microtime(true);
($stream->isConnected()) ?: $stream->getConnect();
$res = $stream->send($httpRequest->toString());
printf("HttpSocket: %f<br/>", microtime(true) - $startTime);

$body = $res->body;
$body->getResource()->appendFilter(new PhpRegisteredFilter('zlib.inflate'), STREAM_FILTER_READ);
### skip the first 10 bytes for zlib
$body = new SegmentWrapStream($body, -1, 10);
echo $body->read();
*/

class HttpSocketConnection extends AbstractConnection
{
    use CloneTrait;

    /** @var Streamable When Connected */
    protected $streamable;

    /**
     * the options will not changed when connected
     * @var HttpSocketOptions
     */
    protected $connected_options;

    /** @var bool  */
    protected $lastReceive = false;

    /** @var \StdClass (object) ['headers'=> .., 'body'=>stream_offset] latest request expression to receive on events */
    protected $_tmp_expr;

    /**
     * Construct
     *
     * - pass transporter options on construct
     *
     * @param null|string|$options        $serverUri_options
     * @param array|iDataSetConveyor|null $options           Transporter Options
     */
    function __construct($serverUri_options = null, $options = null)
    {
        if (is_array($serverUri_options) || $serverUri_options instanceof iDataSetConveyor)
            $options = $serverUri_options;
        elseif(is_string($serverUri_options))
            $this->inOptions()->setServerUrl($serverUri_options);

        parent::__construct($options);
    }

    /**
     * TODO ssl connection
     *
     * Get Prepared Resource Transporter
     *
     * - prepare resource with options
     *
     * @throws \Exception
     * @return mixed Transporter Resource
     */
    final function getConnect()
    {
        if ($this->isConnected())
            ## close current transporter if connected
            $this->close();


        # apply options to resource

        ## options will not take an affect after connect
        $this->connected_options = clone $this->inOptions();

        ## determine protocol

        $serverUrl = $this->inOptions()->getServerUrl();

        if (!$serverUrl)
            throw new \RuntimeException('Server Url is Mandatory For Connect.');


        // get connect

        try{
            $this->streamable = $this->doConnect($serverUrl);
        } catch(\Exception $e)
        {
            throw new \Exception(sprintf(
                'Cannot connect to (%s).'
                , $serverUrl
                , $e->getCode()
                , $e ## as previous exception
            ));
        }

        return $this->streamable;
    }

    /**
     * Do Connect To Server With Streamable
     *
     * @param string $serverUrl
     *
     * @return iStreamable
     */
    function doConnect($serverUrl)
    {
        // TODO validate scheme, ssl connection

        $parsedServerUrl = parse_url($serverUrl);
        $parsedServerUrl['scheme'] = 'tcp';
        (isset($parsedServerUrl['port'])) ?: $parsedServerUrl['port'] = 80;
        $serverUrl = $this->__unparse_url($parsedServerUrl);

        $stream = new StreamClient(
            \Poirot\Core\array_merge(
                $this->inOptions()->toArray()
                , ['socket_uri' => $serverUrl]
            )
        );

        ### options
        $stream->setTimeout($this->inOptions()->getTimeout());
        $stream->setPersist($this->inOptions()->isPersist());

        $resource = $stream->getConnect();
        return new Streamable($resource);
    }

    /**
     * TODO make request and response are too slow
     * Send Expression On the wire
     *
     * - be aware if you pass streamable it will continue from current
     *   not rewind implemented here
     *
     * !! get expression from getRequest()
     *
     * @throws ApiCallException|ConnectException
     * @return string Response
     */
    final function doSend()
    {
//        $start = microtime(true);

        # prepare new request
        $this->lastReceive = null;

        # get connect if not
        if (!$this->isConnected())
            throw new ConnectException;


        # write stream
        try
        {
            ## prepare expression before send
            $expr = $this->getRequest();
            $expr = $this->onBeforeSendPrepareExpression($expr);
            if (is_object($expr) && !$expr instanceof iStreamable)
                $expr = (string) $expr;

            if (is_string($expr))
                $expr = (new Streamable\TemporaryStream($expr))->rewind();

            if (!$expr instanceof iStreamable)
                throw new \InvalidArgumentException(sprintf(
                    'Http Expression must instance of iHttpRequest, RequestInterface or string. given: "%s".'
                    , \Poirot\Core\flatten($expr)
                ));

            $response = $this->__handleReqRes($expr);
        }
        catch (\Exception $e) {
            throw new ApiCallException(sprintf(
                'Request Call Error When Send To Server (%s)'
                , $this->inOptions()->getServerUrl()
            ), 0, 1, __FILE__, __LINE__, $e);
        }

//        printf("get connect: %f<br/>", microtime(true) - $start);

        $this->lastReceive = $response;
        return $response;
    }

    /**
     * Before Send Prepare Expression
     * @param mixed $expr
     * @return iStreamable|string
     */
    function onBeforeSendPrepareExpression($expr)
    {
        return $expr;
    }

    /**
     * $responseHeaders can be changed by reference
     *
     * @param string $responseHeaders
     *
     * @return boolean consider continue with reading body from stream?
     */
    function onResponseHeaderReceived(&$responseHeaders)
    {
        return true; // keep continue
    }

    /**
     * Get Body And Response Headers And Return Expected Response
     *
     * @param string|mixed     $responseHeaders default has headers string but it can changed
     *                                          with onResponseHeaderReceived
     * @param iStreamable|null $body
     *
     * @return mixed Expected Response
     */
    function onResponseReceivedComplete($responseHeaders, $body)
    {
        return (object) ['header' => $responseHeaders, 'body' => $body];
    }

    /**
     * Send Request To Server And Receive Response
     *
     * @param iStreamable $expr
     *
     * @throws \Exception
     * @return string
     */
    final protected function __handleReqRes($expr)
    {
        # send request
        $headers = $this->__readHeadersFromStream($expr);

        $this->streamable->write($headers);
        $expr->pipeTo($this->streamable);

        # receive rest response body
        $response = $this->receive();
        return $response;
    }

    /**
     * Receive Server Response
     *
     * !! return response object if request completely sent
     *
     * - it will executed after a request call to server
     *   from send expression method to receive responses
     * - return null if request not sent or complete
     * - it must always return raw response body from server
     *
     * @throws \Exception No Transporter established
     * @return null|string|Streamable
     */
    final function receive()
    {
        if ($this->lastReceive)
            return $this->lastReceive;


        $stream = $this->streamable;

        $streamMeta = $stream->getResource()->meta();
        if ($streamMeta && $streamMeta->isTimedOut())
            throw new \RuntimeException(
                "Read timed out after {$this->inOptions()->getTimeout()} seconds."
            );

        # read headers:
        $headers = $this->__readHeadersFromStream($stream);
        $body    = null;

        if (empty($headers))
            throw new \Exception('Server not respond to this request.');

        // Fire up registered methods to prepare expression
        $responseHeaders = $headers;
        // Header Received:
        if (!$this->onResponseHeaderReceived($responseHeaders))
            // terminate and return response
            goto finalize;


        # read body:
        /* indicate the end of response from server:
         *
         * (1) closing the connection;
         * (2) examining Content-Length;
         * (3) getting all chunks in the case of Transfer-Encoding: Chunked.
         * There is also
         * (4) the timeout method: assume that the timeout means end of data, but the latter is not really reliable.
         */
        $buffer = static::_newBufferStream();

        $headers = self::parseHeaderLines($headers);
        if (array_key_exists('Content-Length', $headers)) {
            // (2) examining Content-Length;
            $length = (int) $headers['Content-Length'];
            $stream->pipeTo($buffer, $length);
        } elseif (array_key_exists('Transfer-Encoding', $headers) && $headers['Transfer-Encoding'] == 'chunked') {
            // (3) Chunked
            # ! # read data but remain origin chunked data
            $delim = "\r\n";
            do {
                $chunkSize = $stream->readLine($delim);
                ## ! remain chunked
                $buffer->write($chunkSize.$delim);

                if ($chunkSize === $delim)
                    continue;

                ### read this chunk of data
                $stream->pipeTo($buffer, hexdec($chunkSize));

            } while ($chunkSize !== "0");
            ## ! write end CLRF
            $buffer->write($delim);

        } else {
            // ..
            $stream->pipeTo($buffer);
        }

        $body = $buffer->rewind();

finalize:

        return $this->onResponseReceivedComplete($responseHeaders, $body);
    }

    /**
     * Is Transporter Resource Available?
     *
     * @return bool
     */
    function isConnected()
    {
        return ($this->streamable !== null && $this->streamable->getResource()->isAlive());
    }

    /**
     * Close Transporter
     * @return void
     */
    function close()
    {
        if (!$this->isConnected())
            return;

        $this->streamable->getResource()->close();
        $this->streamable = null;
        $this->connected_options = null;
    }


    // options:

    /**
     * @override just for ide completion
     * @return HttpSocketOptions
     */
    function inOptions()
    {
        if ($this->isConnected())
            ## the options will not changed when connected
            return $this->connected_options;

        return parent::inOptions();
    }

    /**
     * @override
     * @return HttpSocketOptions
     */
    static function newOptions($builder = null)
    {
        return new HttpSocketOptions($builder);
    }

    // util:

    /**
     * Parse Header line
     *
     * @param string $message
     *
     * @return false|array[string 'label', string 'value']
     */
    static function parseHeaderLines($message)
    {
        if (!preg_match_all('/.*[\n]?/', $message, $lines))
            throw new \InvalidArgumentException('Error Parsing Request Message.');

        $lines = $lines[0];

        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('/^(?P<label>[^()><@,;:\"\\/\[\]?=}{ \t]+):(?P<value>.*)$/', $line, $matches))
                $headers[$matches['label']] = trim($matches['value']);
        }

        return $headers;
    }

    // ...

    protected function __unparse_url($parsed_url) {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    protected function __readHeadersFromStream(iStreamable $stream)
    {
        $headers = '';
        ## 255 can be vary, its each header length.
        // TODO just read header part from aggregate stream, it can be tagged for each stream
        while(!$stream->isEOF() && ($line = $stream->readLine("\r\n", 255)) !== null ) {
            $break = false;
            $headers .= $line."\r\n";
            if (trim($line) === '') {
                ## http headers part read complete
                $break = true;
            }

            if ($break) break;
        }

        return $headers;
    }

    /**
     * @return Streamable\TemporaryStream
     */
    protected static function _newBufferStream()
    {
        return new Streamable\TemporaryStream();
    }
}
