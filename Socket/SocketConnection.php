<?php
namespace Poirot\Connection\Socket;

use Poirot\Connection\aConnection;
use Poirot\Connection\Exception\exSendExpressionToServer;
use Poirot\Connection\Exception\exConnection;
use Poirot\Std\ErrorStack;


class SocketConnection
    extends aConnection
{
    /** @var string server remote address */
    protected $remoteAddr;
    protected $timeout;

    protected $_conn;
    protected $lastReceive;


    /**
     * Get Prepared Resource Transporter
     *
     * - prepare resource with options
     *
     * @return resource
     * @throws exConnection|\Exception
     */
    function getConnect()
    {
        $errorNum = 0;
        $errorStr = '';


        $remoteAddr = $this->getRemoteAddr();
        if ( empty($remoteAddr) )
            throw new \Exception('No Address To Remote Server Given.');


        ErrorStack::handleException(function($ex) { $this->_handleError($ex); });
        ErrorStack::handleError(E_ALL, function($ex) { $this->_handleError($ex); });

        if ( false === $conn = stream_socket_client($remoteAddr, $errorNum, $errorStr, $this->getTimeout()) ) {
            ($errorNum != 0) ?: $errorStr = 'Could not open socket';
            throw new \RuntimeException($errorStr);
        }

        if (false === $result = stream_set_timeout($conn, $this->getTimeout()))
            throw new \RuntimeException('Could not set stream timeout');

        ErrorStack::handleDone();


        return $conn;
    }

    /**
     * Send Expression On the wire
     *
     * !! get expression from getRequest()
     *
     * @throws exSendExpressionToServer
     * @return void|mixed
     */
    function doSend()
    {
        # prepare new request
        $this->lastReceive = null;

        ## prepare expression before send
        $expr = $this->getLastRequest();

        if ( false === $result = fwrite($this->_conn, $expr) )
            throw new exSendExpressionToServer('Could not send request to ' . $this->getRemoteAddr());


        return $result;
    }

    /**
     * Receive Server Response
     *
     * - it will executed after a request call to server
     *   by send expression
     * - return null if request not sent
     *
     * @param int|null $length
     *
     * @return string|null
     * @throws \Exception No Transporter established
     */
    function receive($length = null)
    {
        if ($this->lastReceive)
            return $this->lastReceive;


        $conn = $this->_conn;
        stream_set_timeout( $conn, $this->getTimeout() );

        // check connection is alive yet
        $info = stream_get_meta_data($conn);
        if (! empty($info['timed_out']))
            throw new \RuntimeException($this->getRemoteAddr() . ' has timed out.');


        if ( false === $r = fgets($conn, ($length === null) ? $length : 1024) )
            throw new \RuntimeException('Could not read from ' . $this->getRemoteAddr());

        return $r;
    }

    /**
     * Is Transporter Resource Available?
     *
     * @return bool
     */
    function isConnected()
    {
        return is_resource($this->_conn);
    }

    /**
     * Close Transporter
     * @return void
     */
    function close()
    {
        if (! $this->isConnected() )
            return;

        fclose($this->_conn);
    }


    // Options:

    /**
     * @return string
     */
    function getRemoteAddr()
    {
        return $this->remoteAddr;
    }

    /**
     * An example $remote string may be 'tcp://mail.example.com:25' or 'ssh://hostname.com:2222'
     *
     * @param string $remoteAddr
     * @return $this
     */
    function setRemoteAddr($remoteAddr)
    {
        $this->remoteAddr = (string) $remoteAddr;
        return $this;
    }

    /**
     * @return int
     */
    function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @param int $timeout
     * @return $this
     */
    function setTimeout($timeout)
    {
        $this->timeout = (int) $timeout;
        return $this;
    }


    // ..

    protected function _handleError($ex)
    {
        throw new exConnection(sprintf(
            'Could not open socket to (%s);'
            , $this->getRemoteAddr()
        ), null, $ex);
    }
}
