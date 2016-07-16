<?php
namespace Poirot\Connection\Http;

class HttpSocketOptions extends AbstractOptionsData
{
    use StreamClientOptionsTrait {
        ## these methods not available for Http Connection
        StreamClientOptionsTrait::setSocketUri as protected __hide__setSocketUri;
        StreamClientOptionsTrait::getSocketUri as protected __hide__getSocketUri;
        StreamClientOptionsTrait::setNoneBlocking as protected __hide__setNoneBlocking;
        StreamClientOptionsTrait::isNoneBlocking as protected __hide__isNoneBlocking;
    }

    protected $serverUrl = null;

    /**
     * Server Url That we Will Connect To
     * @param string $serverUrl
     * @return $this
     */
    public function setServerUrl($serverUrl)
    {
        $this->serverUrl = (string) $serverUrl;
        return $this;
    }

    /**
     * @return string
     */
    public function getServerUrl()
    {
        return $this->serverUrl;
    }

    /**
     * @param array|iDataStruct|AbstractContext $context
     * @return $this
     */
    public function setContext($context)
    {
        $this->getContext()->from($context);
        return $this;
    }

    /**
     * @return SocketContext
     */
    public function getContext()
    {
        if (!$this->context) {
            $this->context = new SocketContext;
            $this->context->bindWith(new HttpContext);
//            $this->context->bindWith(new HttpsContext);
        }

        return $this->context;
    }
}
