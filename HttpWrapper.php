<?php
namespace Poirot\Connection;

use Poirot\Connection\Exception\exConnection;
use Poirot\Connection\Exception\exLogicalTransportFallback;
use Poirot\Connection\Http\ConnectionHttpSocket;
use Poirot\Connection\Http\StreamFilter\DechunkFilter;
use Poirot\Http\Header\CollectionHeader;
use Poirot\Http\Header\FactoryHttpHeader;
use Poirot\Http\HttpMessage\Request\StreamBodyMultiPart;
use Poirot\Http\HttpRequest;
use Poirot\Http\Psr\RequestBridgeInPsr;
use Poirot\Psr7\HttpResponse;
use Poirot\Psr7\Stream;
use Poirot\Psr7\UploadedFile;
use Poirot\Std\Traits\tConfigurableSetter;
use Poirot\Std\Type\StdArray;
use Poirot\Stream\Streamable\STemporary;


class HttpWrapper
{
    use tConfigurableSetter;

    protected $timeout = 5;
    protected $connectTimeout = 5;
    protected $followRedirects = true;
    protected $maxTries = 3;
    protected $defHeaders = [
        #'Accept: application/json',
        #'charset: utf-8'
    ];

    private $_headersSize = 0;


    /**
     * HttpWrapper constructor.
     *
     * @param \Traversable $options
     */
    function __construct($options = null)
    {
        if ($options !== null)
            $this->with($options, true);
    }


    function get($url, array $data = [], array $headers = [])
    {
        return $this->send('GET', $url, $data, $headers);
    }

    function head($url, array $data = [], array $headers = [])
    {
        return $this->send('HEAD', $url, $data, $headers);
    }

    function post($url, array $data = [], array $headers = [])
    {
        return $this->send('POST', $url, $data, $headers);
    }

    function delete($url, array $data = [], array $headers = [])
    {
        return $this->send('DELETE', $url, $data, $headers);
    }

    function send($method, $url, array $data = [], array $headers = [])
    {
        if (! extension_loaded('curl') )
            throw new \Exception('cURL library is not loaded');


        $headers = array_merge(
            $this->defHeaders
            , $headers
        );

        $url = (string) $url;

        $ch  = curl_init();


        ## Connection Options
        #
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $h) { $this->_headersSize += strlen($h); return strlen($h); });

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);


        ## Http Method Valuable
        #
        try {
            switch (strtoupper($method)) {
                case 'HEAD':
                    curl_setopt($ch, CURLOPT_NOBODY, 1);
                    break;
                case 'GET':
                    curl_setopt($ch, CURLOPT_HTTPGET, 1);
                    $url = \Poirot\Http\appendQuery($url, http_build_query($data));
                    break;
                case 'POST':
                    $this->_assertDataValueToStream($data, $ch);
                    break;
                default:
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                    $this->_assertDataValueToStream($data, $ch);

            }
        } catch (exLogicalTransportFallback $e)
        {
            return $this->_sendViaStream($method, $url, $data, $headers);
        }


        ## Request Options
        #
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $this->followRedirects);

        $chHeaders = [];
        foreach ($this->_normalizeHeaders($headers) as $k => $v)
            $chHeaders[] = $k.': '.$v;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $chHeaders);


        ## Send Request
        #
        $this->_headersSize = 0;

        $ret = \Poirot\Std\reTry(function () use ($ch, $method, $url)
        {
            $ret = curl_exec($ch);

            if ($curl_errno = curl_errno($ch)) {
                // Connection Error
                $curl_error = curl_error($ch);
                $errorMessage = $curl_errno.':'.$curl_error.' '."When $method: $url";
                throw new exConnection($errorMessage, $curl_errno);
            }

            return $ret;

        }, $this->maxTries, 1000);


        $httpResponse = $this->_buildFinalResponseFromResource($ret, $ch);


        // Handle Follow Redirect
        //
        if ($httpResponse && substr($httpResponse->getStatusCode(), 0, 1) == 3 && $this->followRedirects)
            return $this->send($method, curl_getinfo($ch, CURLINFO_REDIRECT_URL), $data, $headers);



        curl_close($ch);

        return $httpResponse;
    }




    // Options:

    function setTimeout($timeout)
    {
        $this->timeout = (int) $timeout;
        return $this;
    }

    function setConnectTimeout($connectTimeout)
    {
        $this->connectTimeout = (int) $connectTimeout;
        return $this;
    }

    function setMaxTries($maxTries)
    {
        $this->maxTries = (int) $maxTries;
        return $this;
    }

    function setFollowRedirects($followRedirects)
    {
        $this->followRedirects = (bool)$followRedirects;
        return $this;
    }

    function setDefaultHeaders(array $headers)
    {
        $this->defHeaders = $this->_normalizeHeaders($headers);
        return $this;
    }


    // ..

    /**
     * Normalize Header Lines To Associated Array
     * @param array $headers
     * @return array
     */
    private function _normalizeHeaders(array $headers)
    {
        $normHeaders = [];
        foreach ($headers as $label => $value) {
            if ( is_int($label) ) {
                $normHeaders += \Poirot\Connection\Http\splitLabelValue($value);
                continue;
            }

            $label = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($label))));
            $normHeaders[$label] = $value;
        }


        return $normHeaders;
    }

    private function _buildFinalResponseFromResource($ret, $ch)
    {
        if (! is_resource($ch) || ! $ret)
            return false;


        $headers_size = $this->_headersSize;
        if (is_numeric($headers_size) && $headers_size > 0) {
            $headers = trim(substr($ret, 0, $headers_size));
            $body = substr($ret, $headers_size);
        } else
            list($headers, $body) = preg_split('/\r\n\r\n|\r\r|\n\n/', $ret, 2);
        if (! $body)
            $body = '';

        $raw_headers = $headers;
        $header_lines = explode("\r\n", $headers);
        $headers = array();
        foreach ($header_lines as $header_line) {
            $buf = explode(': ', $header_line);
            if (count($buf) == 1)
                continue;

            $headers[$buf[0]] = $buf[1];
        }


        $stream = new Stream('php://memory', 'w+');
        $stream->write($body);
        $stream->rewind();

        $response = new HttpResponse($stream, curl_getinfo($ch, CURLINFO_HTTP_CODE), $headers);
        return $response;
    }

    private function _assertDataValueToStream($data, $ch)
    {
        if ( is_array($data) ) {
            $hasFallback = false;
            foreach ($data as $k => $v) {
                if ($v instanceof UploadedFile)
                    throw new exLogicalTransportFallback;

                if ( is_resource($v) ) {
                    if ($hasFallback == false)
                    {
                        // File Attached as Data
                        $fMeta = stream_get_meta_data( $v );
                        if ( isset($fMeta['uri']) ) {
                            $mimeType = $this->_getMimeTypeFromResource($fMeta);
                            $data[$k] = new \CURLFile($fMeta['uri'], $mimeType);
                        } else {
                            // Fallback to multipart/form-data
                            // curl not support for send stream resource it must convert to string
                            // that in large files cause high memory usage
                            throw new exLogicalTransportFallback;
                        }

                    } else
                    {
                        // DEPRECATED; using fallback
                        // curl not support for send stream resource it must convert to string
                        // that in large files cause high memory usage

                        // It's not possible to send curl upload file (post)
                        // make data as multipart/form-data
                        $data = new StreamBodyMultiPart($data);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                "Content-Type: multipart/form-data; boundary=".$data->getBoundary()
                            ] //setting our mime type for make it work on $_FILE variable
                        );


                        break;
                    }
                }

            }


            curl_setopt($ch, CURLOPT_POST, 1);

            if ( is_array($data) ) {
                $data = StdArray::of($data)->makeFlattenFace();
                $data = $data->value;
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }

    private function _getMimeTypeFromResource (array $fileMetadata)
    {
        if ('http' == $fileMetadata['wrapper_type']) {
            $headers = new CollectionHeader;
            foreach ($fileMetadata['wrapper_data'] as $i => $h) {
                if ($i === 0)
                    // This is request Status Header (GET HTTP 1.1)
                    continue;

                $headers->insert(FactoryHttpHeader::of($h));
            }

            if (! $headers->has('Content-Type') )
                return 'application/octet-stream';


            return \Poirot\Http\Header\renderHeader($headers->get('Content-Type'));
        }


        return \Module\HttpFoundation\getMimeTypeOfFile($fileMetadata['uri']);
    }


    private function _sendViaStream($method, $url, $data, $headers)
    {
        $stream = new ConnectionHttpSocket([
            'server_address' => $url,
            'time_out'       => 3000,
        ]);


        $body = new StreamBodyMultiPart($data);

        $request = new HttpRequest;

        $request->setMethod($method);

        $request->headers()
            ->insert(FactoryHttpHeader::of([
                'Content-Type' => 'multipart/form-data; boundary='.$body->getBoundary()
            ]));


        $parsedUrl = parse_url($url);
        $headers['Host'] = $parsedUrl['host'];
        $headers['Content-Length'] = $body->getSize();

        $request->setTarget($parsedUrl['path']);

        foreach ($headers as $h => $v)
            $request->headers()
                ->insert( FactoryHttpHeader::of([$h => $v]) );


        $request = $request->setBody($body);

        $expression = new RequestBridgeInPsr($request);

        /** @var STemporary $res */
        $res = $stream->send( $expression );

        /*
         * Array
            (
                [version] => 1.1
                [status] => 200
                [reason] => OK
                [headers] => Array
                    (
                        [Cache-Control] => no-store, no-cache, must-revalidate
                        [Content-Type] => application/json
                        [Date] => Sun, 07 Jan 2018 11:45:55 GMT
                        [Expires] => Thu, 19 Nov 1981 08:52:00 GMT
                        [Pragma] => no-cache
                        [Server] => Apache/2.4.10 (Debian)
                        [Set-Cookie] => PHPSESSID=120fbf3ce4730785dfcde165d2f35a28; expires=Tue, 06-Feb-2018 11:45:57 GMT; Max-Age=2592000; path=/
                        [Transfer-Encoding] => chunked
                        [Vary] => Authorization
                    )

            )
        */
        $headers   = \Poirot\Connection\Http\readAndSkipHeaders($res);
        $status = \Poirot\Connection\Http\parseStatusLine($headers);
        $status = $status['status'];
        $headers = \Poirot\Connection\Http\parseResponseHeaders($headers);

        if (isset($headers['headers']['Transfer-Encoding']) && false !== strpos($headers['headers']['Transfer-Encoding'], 'chunked'))
            $res->resource()->appendFilter(new DechunkFilter());

        $body = $res->read();


        $stream = new Stream('php://memory', 'w+');
        $stream->write($body);
        $stream->rewind();

        $response = new HttpResponse($stream, $status, $headers['headers']);
        return $response;
    }
}
