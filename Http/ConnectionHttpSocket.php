<?php
namespace Poirot\Connection\Http;

use Poirot\Connection\Exception\exServerNotUnderstand;
use Poirot\Stream\Context\ContextStreamHttp;
use Poirot\Stream\Context\ContextStreamSocket;
use Poirot\Stream\Interfaces\Context\iContextStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

use Poirot\Stream\Interfaces\iStreamable;
use Poirot\Stream\Psr\StreamBridgeFromPsr;
use Poirot\Stream\Streamable;
use Poirot\Stream\StreamClient;

use Poirot\Connection\aConnection;
use Poirot\Connection\Exception\exSendExpressionToServer;

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

    /** @var string */
    protected $socketUri    = null;
    /** @var float */
    protected $timeout      = 20;
    /** @var boolean */
    protected $persist      = false;
    /** @var boolean */
    protected $async        = false;
    /** @var iContextStream */
    protected $context;


    /**
     * Send Expression On the wire
     *
     * - be aware if you pass streamable it will continue from current
     *   not rewind implemented here
     *
     * !! get expression from getRequest()
     *
     * @throws exSendExpressionToServer
     * @return iStreamable Response
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
            $expr = $this->makeStreamFromRequestExpression($expr);

            if (!$expr instanceof iStreamable)
                throw new \InvalidArgumentException(sprintf(
                    'Http Expression must instance of iHttpRequest, RequestInterface or string. given: "%s".'
                    , \Poirot\Std\flatten($expr)
                ));


            if ( $expr->resource()->isSeekable() )
                $expr->rewind();

            // send request to server
            $this->_sendToServer($expr);
            $response = $this->receive();
        }
        catch (\Exception $e) {
            throw new exSendExpressionToServer(sprintf(
                'Request Call Error When Send To Server (%s)'
                , $this->getServerAddress()
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
     * @return string|null
     * @throws \Exception No Transporter established
     */
    final function receive()
    {
        if ($this->lastReceive)
            return $this->lastReceive;

        $stream  = $this->_serverConnAsStream();

        # read headers:
        $headers = \Poirot\Connection\Http\readAndSkipHeaders($stream);
        if (empty($headers))
            throw new exServerNotUnderstand(sprintf(
                'Server not respond to request; response headers not received. [%s]'
                , $headers
            ));

        $Buffer = static::_newBufferStream();
        // write received
        $Buffer->write($headers);


        $ParsedResponse = \Poirot\Connection\Http\parseResponseHeaders($headers);

        // Fire up registered methods to prepare expression
        // Header Received:
        if (false === $this->canContinueWithReceivedHeaders($ParsedResponse))
            // terminate and return response
            goto finalize;


        # Read Body:

        /* indicate the end of response from server:
         *
         * (1) closing the connection;
         * (2) examining Content-Length;
         * (3) getting all chunks in the case of Transfer-Encoding: Chunked.
         * There is also
         * (4) the timeout method: assume that the timeout means end of data, but the latter is not really reliable.
         */

        $headers = $ParsedResponse['headers'];

        if (array_key_exists('Content-Length', $headers)) {
            // (2) examining Content-Length;
            $length = (int) $headers['Content-Length'];
            $stream->pipeTo($Buffer, $length);
        } elseif (array_key_exists('Transfer-Encoding', $headers) && $headers['Transfer-Encoding'] == 'chunked') {
            // (3) Chunked
            # ! # read data but remain origin chunked data
            $delim = "\r\n";
            do {
                if ( null === $chunkSize = $stream->readLine($delim) )
                    // Read Error Connection Lost
                    break;


                ## ! remain chunked
                $Buffer->write($chunkSize.$delim);

                if ($chunkSize === $delim || $chunkSize === '')
                    continue;

                ### read this chunk of data
                $stream->pipeTo($Buffer, hexdec($chunkSize));

            } while ($chunkSize !== "0");
            ## ! write end CLRF
            $Buffer->write($delim);

        } else {
            // ..
            $stream->pipeTo($Buffer);
        }


finalize:

        $rStream = $this->finalizeResponseFromStream($Buffer, $ParsedResponse);
        return $rStream;
    }
    
    /**
     * Make Stream From Expression To Send Over Connection
     *
     * @param string|RequestInterface|StreamInterface $expr @see self::request
     *
     * @return iStreamable
     */
    protected function makeStreamFromRequestExpression($expr)
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

            $expr = $tStream;

        } elseif ($expr instanceof StreamInterface)
            $expr = new StreamBridgeFromPsr($expr);

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
     * Determine received response headers
     *
     * @param array &$parsedResponse By reference
     *        array['version'=>string, 'status'=>int, 'reason'=>string, 'headers'=>array(key=>val)]
     *
     * @return true|false Consider continue with reading body from stream?
     */
    protected function canContinueWithReceivedHeaders(&$parsedResponse)
    {
        return true; // keep continue
    }

    /**
     * Finalize Response Buffer
     *
     * @param iStreamable $response
     * @param array       $parsedResponse
     * 
     * @return iStreamable
     * @throws \Exception
     */
    protected function finalizeResponseFromStream($response, $parsedResponse)
    {
        if (!$response instanceof iStreamable)
            throw new \Exception(sprintf(
                'Response must be iStreamable instance; given (%s).'
                , \Poirot\Std\flatten($response)
            ));
        
        return $response->rewind();
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

        return $this->_serverConnAsStream();
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
     * Send Data To Server
     * @param string|iStreamable $content
     */
    protected function _sendToServer($content)
    {
        $server = $this->_serverConnAsStream();

        if (is_string($content)) {
            $server->write($content);
        } else {
            $content->pipeTo($server);
        }
    }

    /**
     * @return iStreamable|Streamable
     * @throws \Exception
     */
    protected function _serverConnAsStream()
    {
        if ($this->_streamableServerConnection)
            return $this->_streamableServerConnection;

        ## determine protocol

        $serverUrl = $this->getServerAddress();

        if (!$serverUrl)
            throw new \RuntimeException('Server Url is Mandatory For Connect.');


        // get connect

        try {

            return $this->_streamableServerConnection = $this->_connect($serverUrl);

        } catch(\Exception $e) {
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

        $stream  = new StreamClient($serverUrl);
        $stream->setPersist($this->isPersist());
        $stream->setAsync($this->isAsync());
        $stream->setTimeout($this->getTimeout());
        $stream->setContext($this->getContext());
        $stream->setServerAddress($serverUrl);       // we want replaced prepared server address!

        $resource = $stream->getConnect();
        return new Streamable($resource);
    }


    // Options:

    /**
     * Set Socket Uri
     *
     * Note: When specifying a numerical IPv6 address (e.g. fe80::1),
     *       you must enclose the IP in square brackets—for example,
     *       tcp://[fe80::1]:80
     *
     * @param string $socketUri
     *
     * @return $this
     */
    function setServerAddress($socketUri)
    {
        $this->socketUri = (string) $socketUri;
        return $this;
    }

    /**
     * Get Current Socket Uri That Stream Built With
     *
     * @return string|null
     */
    function getServerAddress()
    {
        return $this->socketUri;
    }

    /**
     * Set Default Base Context Options
     *
     * @param iContextStream $context
     *
     * @throws \InvalidArgumentException
     * @return $this
     */
    function setContext(iContextStream $context)
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Get Default Base Context Options
     *
     * @return iContextStream
     */
    function getContext()
    {
        if (!$this->context) {
            $context = new ContextStreamSocket;
            $context->bindWith(new ContextStreamHttp);
//            $this->context->bindWith(new HttpsContext);

            $this->setContext($context);
        }

        return $this->context;
    }

    /**
     * Set timeout period on a stream
     *
     * - must store time in float mode
     *   @see self::getTimeout
     *
     * @param float|array $seconds In Form Of time.utime
     *
     * @return $this
     */
    function setTimeout($seconds)
    {
        if (is_array($seconds))
            $seconds = implode('.', $seconds);

        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Get Timeout
     *
     * @return float
     */
    function getTimeout()
    {
        if (!$this->timeout)
            $this->setTimeout(ini_get('default_socket_timeout'));

        return $this->timeout;
    }

    /**
     * Set To Persistent Internet or Unix Domain Socket
     * Connection Built
     *
     * @param bool $flag
     *
     * @return $this
     */
    function setPersist($flag = true)
    {
        $this->persist = (boolean) $flag;
        return $this;
    }

    /**
     * Indicate Is Connection Have To Built On Persistent Mode
     *
     * @return boolean
     */
    function isPersist()
    {
        return $this->persist;
    }

    /**
     * @param boolean $async
     * @return $this
     */
    function setAsync($async = true)
    {
        $this->async = (boolean) $async;
        return $this;
    }

    /**
     * @return boolean
     */
    function isAsync()
    {
        return $this->async;
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
        $path     = /*isset($parsed_url['path']) ? $parsed_url['path'] :*/ '';
        $query    = /*isset($parsed_url['query']) ? '?' . $parsed_url['query'] :*/ '';
        $fragment = /*isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] :*/ '';

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
