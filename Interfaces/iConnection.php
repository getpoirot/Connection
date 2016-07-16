<?php
namespace Poirot\Connection\Interfaces;

use Poirot\Connection\Exception\ApiCallException;
use Poirot\Connection\Exception\ConnectException;
use Poirot\Stream\Streamable;
use Psr\Http\Message\StreamInterface;

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
     * Get Latest Request
     *
     * @return null|mixed
     */
    function getLastRequest();

    /**
     * Send Expression To Server
     *
     * - send expression to server through transporter
     *   resource
     *
     * - don't set request globally through request() if
     *   expr set
     *
     * !! it must be connected
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
     * @throws \Exception No Transporter established
     * @return null|string|StreamInterface
     */
    function receive();

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
