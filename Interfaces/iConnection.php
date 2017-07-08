<?php
namespace Poirot\Connection\Interfaces;

use Poirot\Stream\Interfaces\iStreamable;
use Poirot\Connection\Exception\exSendExpressionToServer;
use Poirot\Connection\Exception\exConnection;


interface iConnection
{
    /**
     * Get Prepared Resource Transporter
     *
     * - prepare resource with options
     *
     * @throws exConnection
     * @return mixed Transporter Resource
     */
    function getConnect();

    /**
     * Set Request Expression To Send Over Wire
     *
     * @param mixed $expr
     *
     * @return $this
     */
    function request($expr);

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
    function send($expr = null);

    /**
     * Receive Server Response
     *
     * - it will executed after a request call to server
     *   by send expression
     * - return null if request not sent
     *
     * @return iStreamable|null
     * @throws \Exception No Transporter established
     */
    function receive();

    /**
     * Get Latest Request
     *
     * @return null|mixed
     */
    function getLastRequest();
    
    /**
     * Is Transporter Resource Available?
     *
     * @return bool
     */
    function isConnected();

    /**
     * Close Transporter
     * @return void
     */
    function close();
}
