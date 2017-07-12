<?php
namespace Poirot\Connection;

use Poirot\Std\ConfigurableSetter;

use Poirot\Connection\Exception\exSendExpressionToServer;
use Poirot\Connection\Exception\exConnection;
use Poirot\Connection\Interfaces\iConnection;


abstract class aConnection
    extends ConfigurableSetter
    implements iConnection
{
    /** @var mixed Expression to Send */
    protected $expr;


    /**
     * Get Prepared Resource Transporter
     *
     * - prepare resource with options
     *
     * @throws exConnection
     * @return mixed Transporter Resource
     */
    abstract function getConnect();

    /**
     * Send Expression To Server
     *
     * - send expression to server through transporter
     *   resource
     *
     * - don't set request globally through request() if
     *   expr set
     *
     * !! getConnect IF NOT
     *
     * @param mixed $expr Expression
     *
     * @throws exSendExpressionToServer
     * @return mixed Prepared Server Response
     */
    final function send($expr = null)
    {
        if ($expr === null)
            $expr = $this->getLastRequest();

        if ($expr === null)
            throw new \InvalidArgumentException(
                'Expression not set, nothing to do.'
            );

        # check connection
        if (! $this->isConnected() )
            $this->getConnect();

        # ! # remember last request
        $this->request($expr);
        $result = $this->doSend();
        return $result;
    }

    /**
     * Send Expression On the wire
     *
     * !! get expression from getRequest()
     *
     * @throws exSendExpressionToServer
     * @return mixed Response
     */
    abstract function doSend();

    /**
     * Receive Server Response
     *
     * - it will executed after a request call to server
     *   by send expression
     * - return null if request not sent
     *
     * @return string|null
     * @throws \Exception No Transporter established
     */
    abstract function receive();

    /**
     * Is Transporter Resource Available?
     *
     * @return bool
     */
    abstract function isConnected();

    /**
     * Close Transporter
     * @return void
     */
    abstract function close();


    /**
     * Set Request Expression To Send Over Wire
     *
     * @param mixed $expr
     *
     * @return $this
     */
    function request($expr)
    {
        $this->expr = $expr;
        return $this;
    }

    /**
     * Get Latest Request
     *
     * @return null|mixed
     */
    function getLastRequest()
    {
        return $this->expr;
    }


    // Options:


    // ..

    function __destruct()
    {
        if ( $this->isConnected() )
            $this->close();
    }
}
