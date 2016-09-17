<?php
namespace Poirot\Connection\Http;

use Poirot\Connection\aConnection;
use Poirot\Connection\Exception\ApiCallException;
use Poirot\Stream\Interfaces\iStreamable;
use Poirot\Stream\Psr\StreamBridgeFromPsr;
use Poirot\Stream\Streamable;
use Poirot\Stream\StreamClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

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

class ConnectionHttpSocket 
    extends aConnection
{
    /** @var Streamable When Connected */
    protected $_streamableServerConnection;

    /**
     * the options will not changed when connected
     * @var OptionsHttpSocket
     */
    protected $connected_options;

    /** @var bool  */
    protected $lastReceive = false;

    /** @var \StdClass (object) ['headers'=> .., 'body'=>stream_offset] latest request expression to receive on events */
    protected $_tmp_expr;

    protected $_supportedScheme = array(
        'http'  => array(
            'port'    => 80,
            'wrapper' => 'tcp'
        ),
        'https' => array(
            'port'    => 443,
            'wrapper' => 'ssl'
        )
    );

    
    /**
     * Construct
     *
     * - pass transporter options on construct
     *
     * @param null|string|array|\Traversable $serverUri_options
     * @param array|\Traversable|null        $options           Transporter Options
     */
    function __construct($serverUri_options = null, $options = null)
    {
        if (is_array($serverUri_options) || $serverUri_options instanceof \Traversable)
            $options = $serverUri_options;
        elseif(is_string($serverUri_options))
            $this->optsData()->setServerAddress($serverUri_options);

        parent::__construct($options);
    }

    /**
     * @override IDE Completion
     *
     * @param string|RequestInterface|StreamInterface $expr
     *
     * @return $this
     */
    function request($expr)
    {
        if (!(\Poirot\Std\isStringify($expr) || $expr instanceof RequestInterface || $expr instanceof StreamInterface))
            throw new \InvalidArgumentException(sprintf(
                'Expression must instance of RequestInterface/StreamInterface PSR or request string; given: (%s).'
                , \Poirot\Std\flatten($expr)
            ));

        $this->expr = $expr;
        return $this;
    }

    /**
     * TODO ssl connection
     * @link http://www.devdungeon.com/content/how-use-ssl-sockets-php
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

        return $this->_getServerConnection();
    }
    
    /**
     * Send Expression On the wire
     *
     * - be aware if you pass streamable it will continue from current
     *   not rewind implemented here
     *
     * !! get expression from getRequest()
     *
     * @throws ApiCallException
     * @return string Response
     */
    final function doSend()
    {
        # prepare new request
        $this->lastReceive = null;

        # write stream
        try
        {
            ## prepare expression before send
            $expr = $this->getLastRequest();
            $expr = $this->triggerBeforeSendPrepareExpression($expr);
            
            if (!$expr instanceof iStreamable)
                throw new \InvalidArgumentException(sprintf(
                    'Http Expression must instance of iHttpRequest, RequestInterface or string. given: "%s".'
                    , \Poirot\Std\flatten($expr)
                ));

            // send request to server
            $this->_sendToServer($expr);
            $response = $this->receive();
        }
        catch (\Exception $e) {
            throw new ApiCallException(sprintf(
                'Request Call Error When Send To Server (%s)'
                , $this->optsData()->getServerAddress()
            ), 0, 1, __FILE__, __LINE__, $e);
        }

        $this->lastReceive = $response;
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
     * @return null|string|iStreamable
     */
    function receive()
    {
        if ($this->lastReceive)
            return $this->lastReceive;

        $stream = $this->_getServerConnection();

        # read headers:
        $headers = $this->_readHeadersFromStream($stream);

        if (empty($headers))
            throw new \Exception('Server not respond to this request.');


        $body    = null;

        // Fire up registered methods to prepare expression
        $responseHeaders = $headers;
        // Header Received:
        if (!$this->triggerResponseHeaderReceived($responseHeaders))
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

        $headers = \Poirot\Connection\Http\parseHeaderLines($headers);
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

                if ($chunkSize === $delim || $chunkSize === '')
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

        $header = '';
        foreach ($headers as $h => $v) {
            $header .= "{$h}: ".$v."\r\n";
        }
        $header .= "\r\n";

        $rStream = new Streamable\SAggregateStreams();
        $rStream->addStream(new Streamable\STemporary($header));
        $rStream->addStream($body);
        return $rStream->rewind();
    }

    /**
     * Is Transporter Resource Available?
     *
     * @return bool
     */
    function isConnected()
    {
        return ($this->_streamableServerConnection !== null && $this->_streamableServerConnection->resource()->isAlive());
    }

    /**
     * Close Transporter
     * @return void
     */
    function close()
    {
        if (!$this->isConnected())
            return;

        $this->_streamableServerConnection->resource()->close();
        $this->_streamableServerConnection = null;
        $this->connected_options = null;
    }


    // ...

    /**
     * Before Send Prepare Expression
     *
     * @param string|RequestInterface|StreamInterface $expr
     *
     * @return iStreamable
     */
    protected function triggerBeforeSendPrepareExpression($expr)
    {
        # PSR RequestInterface
        if ($expr instanceof RequestInterface) {
            $tStream = new Streamable\SAggregateStreams();
         
            ## headers
            $headers = \Poirot\Psr7\renderHeaderHttpMessage($expr);
            $tStream->addStream(new Streamable\STemporary($headers));
            $tStream->addStream(new Streamable\STemporary("\r\n"));

            ## body
            $body = $expr->getBody();
            if ($body)
                $tStream->addStream(new StreamBridgeFromPsr($body));

            $expr = $tStream->rewind();
        }

        # Stringify
        if (!$expr instanceof iStreamable && is_object($expr))
            $expr = (string) $expr;

        # String
        if (is_string($expr)) {
            $tStream = new Streamable\STemporary($expr);
            $expr    = $tStream->rewind();
        }

        return $expr;
    }

    /**
     * $responseHeaders can be changed by reference
     *
     * @param string $responseHeaders
     *
     * @return boolean consider continue with reading body from stream?
     */
    protected function triggerResponseHeaderReceived(&$responseHeaders)
    {
        return true; // keep continue
    }


    /**
     * Read Only Header Parts of Stream
     * @param iStreamable $stream
     * @return string
     * @throws \Exception
     */
    protected function _readHeadersFromStream(iStreamable $stream)
    {
        if ($stream->getCurrOffset() > 0)
            throw new \Exception(sprintf(
                'Reading Headers Must Start From Begining Of Request Stream; current offset is: (%s).'
                , $stream->getCurrOffset()
            ));

        $headers = '';
        ## 255 can be vary, its each header length.
        while(!$stream->isEOF() && ($line = $stream->readLine("\r\n", 255)) !== null ) {
            $break = false;
            $headers .= $line."\r\n";
            if (trim($line) === '')
                ## http headers part read complete
                $break = true;

            if ($break) break;
        }

        return $headers;
    }

    /**
     * Send Data To Server
     * @param string|iStreamable $content
     */
    protected function _sendToServer($content)
    {
        $server = $this->_getServerConnection();

        if (is_string($content))
            $server->write($content);
        else
            $content->pipeTo($server);
    }

    /**
     * @return iStreamable|Streamable
     * @throws \Exception
     */
    protected function _getServerConnection()
    {
        if ($this->_streamableServerConnection)
            return $this->_streamableServerConnection;

        # apply options to resource

        ## options will not take an affect after connect
        $this->connected_options = clone $this->optsData();

        ## determine protocol

        $serverUrl = $this->optsData()->getServerAddress();

        if (!$serverUrl)
            throw new \RuntimeException('Server Url is Mandatory For Connect.');


        // get connect

        try {

            return $this->_streamableServerConnection = $this->_connect($serverUrl);

        } catch(\Exception $e) {
            kd($e);
            throw new \Exception(sprintf(
                'Cannot connect to (%s).'
                , $serverUrl
                , $e->getCode()
                , $e ## as previous exception
            ));
        }
    }

    /**
     * Do Connect To Server With Streamable
     *
     * @param string $serverUrl
     *
     * @return iStreamable
     */
    function _connect($serverUrl)
    {
        // TODO validate scheme, ssl connection

        $parsedServerUrl = parse_url($serverUrl);
        $serverUrl = $this->_unparse_url($parsedServerUrl);

        $options = $this->optsData();
        $stream  = new StreamClient($serverUrl);
        $stream->setPersist($options->isPersist());
        $stream->setAsync($options->isPersist());
        $stream->setTimeout($options->getTimeout());
        $stream->setContext($options->getContext());
        $stream->setServerAddress($serverUrl);       // we want replaced prepared server address!

        $resource = $stream->getConnect();
        return new Streamable($resource);
    }
    
    // options:

    /**
     * @override just for ide completion
     * @return OptionsHttpSocket
     */
    function optsData()
    {
        if ($this->isConnected())
            ## the options will not changed when connected
            return $this->connected_options;

        return parent::optsData();
    }

    /**
     * @override
     * @return OptionsHttpSocket
     */
    static function newOptsData($builder = null)
    {
        $options = new OptionsHttpSocket;
        if ($builder !== null)
            $options->import($builder);
        
        return $options; 
    }


    // ...

    protected function _unparse_url($parsed_url) {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] : '';

        if (!isset($this->_supportedScheme[$scheme]))
            throw new \Exception(sprintf('Scheme (%s) not support.', $scheme));

        $wrapper  = $this->_supportedScheme[$scheme]['wrapper'].'://';
        $port     = $this->_supportedScheme[$scheme]['port'];

        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : ':'.$port;
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        return "$wrapper$user$pass$host$port$path$query$fragment";
    }
    
    /**
     * @return Streamable\STemporary
     */
    protected static function _newBufferStream()
    {
        return new Streamable\STemporary();
    }
}
