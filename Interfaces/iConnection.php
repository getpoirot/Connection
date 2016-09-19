<?php
namespace Poirot\Connection\Interfaces;

use Psr\Http\Message\StreamInterface;

use Poirot\Stream\Streamable;

use Poirot\Connection\Exception\ApiCallException;
use Poirot\Connection\Exception\ConnectException;


interface iConnection
{
    /**
     * Get Prepared Resource Transporter
     *
     * - prepare resource with options
     *
     * @throws ConnectException
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
     * @throws ApiCallException
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
     * @return StreamInterface|null
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
