<?php
namespace Poirot\Connection\Http;

use Poirot\Std\Struct\aDataOptions;
use Poirot\Stream\Context\ContextStreamHttp;
use Poirot\Stream\Context\ContextStreamSocket;
use Poirot\Stream\Interfaces\Context\iContextStream;

class OptionsHttpSocket
    extends aDataOptions
{
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
}
