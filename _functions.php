<?php
namespace Poirot\Connection\Http
{
    /**
     * Parse Response Headers
     *
     * @param string $headers
     *
     * @return array['version'=>string, 'status'=>int, 'reason'=>string, 'headers'=>array(key=>val)]
     */
    function parseResponseHeaders($headers)
    {
        if (!preg_match_all('/.*[\r\n]?/', $headers, $lines))
            throw new \InvalidArgumentException('Error Parsing Request Message.');

        $lines = $lines[0];

        $firstLine = array_shift($lines);

        $regex   = '/^HTTP\/(?P<version>1\.[01]) (?P<status>\d{3})(?:[ ]+(?P<reason>.*))?$/';
        $matches = array();
        if (!preg_match($regex, $firstLine, $matches))
            throw new \InvalidArgumentException(
                'A valid response status line was not found in the provided string.'
                . ' response:'
                . $headers
            );


        // ...

        $return = array();

        $return['version'] = $matches['version'];
        $return['status']  = (int) $matches['status'];
        $return['reason']  = (isset($matches['reason']) ? $matches['reason'] : '');
        // headers:
        $return['headers'] = array();
        while ($nextLine = array_shift($lines)) {
            if (trim($nextLine) == '')
                // headers end
                break;

            if (! preg_match('/^(?P<label>[^()><@,;:\"\\/\[\]?=}{ \t]+):(?P<value>.*)$/', $nextLine, $matches))
                throw new \InvalidArgumentException(
                    'Valid Header not found: '.$nextLine
                );

            $return['headers'][$matches['label']] = trim($matches['value']);
        }

        return $return;
    }

    /**
     * Parse Request Message Expresssion
     *
     * @param string $request
     *
     * @return array
     */
    function parseRequest($request)
    {
        if (!preg_match_all('/.*[\n]?/', $request, $lines))
            throw new \InvalidArgumentException('Error Parsing Request Message.');

        $lines = $lines[0];

        // request line:
        $firstLine = array_shift($lines);
        $matches = null;
        $methods = '\w+';
        $regex     = '#^(?P<method>' . $methods . ')\s(?P<uri>[^ ]*)(?:\sHTTP\/(?P<version>\d+\.\d+)){0,1}#';
        if (!preg_match($regex, $firstLine, $matches))
            throw new \InvalidArgumentException(
                'A valid request line was not found in the provided message.'
            );

        $return = array();

        $return['method'] = $matches['method'];
        $return['uri']    = $matches['uri'];

        if (isset($matches['version']))
            $return['version'] = $matches['version'];

        // headers:
        while ($nextLine = array_shift($lines)) {
            if (trim($nextLine) == '')
                // headers end
                break;

            if (! preg_match('/^(?P<label>[^()><@,;:\"\\/\[\]?=}{ \t]+):(?P<value>.*)$/', $nextLine, $matches))
                throw new \InvalidArgumentException(
                    'Valid Header not found: '.$nextLine
                );

            $return['headers'][$matches['label']] = trim($matches['value']);
        }

        // body:
        parse_str(urldecode(implode("\r\n", $lines)), $output);
        $return['body'] = $output;
        return $return;
    }
}
