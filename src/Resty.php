<?php
/**
 * The source file for Resty
 */


namespace Resty;

/**
 * A simple PHP library for doing RESTful HTTP stuff. Does not require the curl extension.
 * @link https://github.com/fictivekin/resty.php
 */
class Resty
{

    /**
     * The version of this lib
     */
    const VERSION = '0.6.1';

    /**
     * the default timeout for requests, in seconds
     */
    const DEFAULT_TIMEOUT = 240;

    /**
     * the default maximum number of redirects
     */
    const DEFAULT_MAX_REDIRECTS = 0;

    /**
     * @var bool enables debugging output
     */
    protected $debug = false;

    /**
     * logging function (should be a Closure)
     * @var \Closure
     */
    protected $logger = null;

    /**
     * @var bool whether or not to auto-parse the response body as JSON or XML
     */
    protected $parse_body = true;

    /**
     * @var string The user agent sent with the request
     * @see Resty::getUserAgent()   the method that retrieves the current user agent
     */
    protected $user_agent = null;

    /**
     * @var string the base url used to construct request endpoints
     */
    protected $base_url;

    /**
     * Stores the last request hash
     * @var array
     */
    protected $last_request;

    /**
     * Stores the last response hash
     * @var array
     */
    protected $last_response;

    /**
     * stores anon func callbacks (because you can't store them as obj props
     * @var array
     */
    protected $callbacks = array();

    /**
     * username for basic auth
     * @var string
     */
    protected $username;

    /**
     * password for basic auth
     * @var string
     */
    protected $password;

    /**
     * by default, silence the fopen warning if we can't open the stream
     * @var boolean
     */
    protected $silence_fopen_warning = true;

    /**
     * by default, don't raise an exception if fopen() fails
     * @var boolean
     */
    protected $raise_fopen_exception = false;

    /**
     * if true, send X-HTTP-Method-Override header for PATCH requests. default is false
     * @var boolean
     */
    protected $supports_patch = true;

    /**
     * by default, convert decoded JSON to an object of StdClass. If true,
     * convert to an array.
     * @var boolean
     */
    protected $json_to_array = false;

    /**
     * content-types that will trigger JSON parsing of body
     * @var array
     */
    public static $JSON_TYPES = array(
        'application/json',
        'text/json',
        'text/x-json',
    );

    /**
     * content-types that will trigger XML parsing
     * @var array
     */
    public static $XML_TYPES = array(
        'application/xml',
        'text/xml',
        'application/rss+xml',
        'application/xhtml+xml',
        'application/atom+xml',
        'application/xslt+xml',
        'application/mathml+xml',
    );

    /**
     * Class constructor
     *
     * Passed `opts` can include:
     * * $opts['onRequestLog'] - an anonymous function that takes the Resty::last_request property as arg
     * * $opts['onResponseLog'] - an anonymous function that takes the Resty::last_response property as arg
     * * $opts['silence_fopen_warning'] - boolean: silence warnings from fopen when trying to open stream
     * * $opts['raise_fopen_exception'] - boolean: raise an exception from fopen if trying to open stream fails
     * * $opts['supports_patch'] - boolean: set to false if the REST end point you're connecting to does not support PATCH -- will use the X-HTTP-Method-Override header.
     * * $opts['json_to_array'] - boolean: set to true if decoded JSON should be an array, not an object
     *
     * @see   Resty::last_request   the property that stores the last request
     * @see   Resty::last_response  the property that stores the last response
     * @see   Resty::sendRequest()  the method that sends a request
     * @param array $opts OPTIONAL array of options
     */
    public function __construct($opts = null)
    {
        if (!empty($opts['onRequestLog']) && ($opts['onRequestLog'] instanceof Closure)) {
            $this->callbacks['onRequestLog'] = $opts['onRequestLog'];
        }
        if (!empty($opts['onResponseLog']) && ($opts['onResponseLog'] instanceof Closure)) {
            $this->callbacks['onResponseLog'] = $opts['onResponseLog'];
        }
        if (isset($opts['silence_fopen_warning'])) {
            $this->silenceFopenWarning((bool)$opts['silence_fopen_warning']);
        }
        if (isset($opts['raise_fopen_exception'])) {
            $this->raiseFopenException((bool)$opts['raise_fopen_exception']);
        }
        if (isset($opts['supports_patch'])) {
            $this->supportsPatch((bool)$opts['supports_patch']);
        }
        if (isset($opts['json_to_array'])) {
            $this->jsonToArray((bool)$opts['json_to_array']);
        }
    }

    /**
     * retrieve the last request we sent
     *
     * valid keys are `['url', 'method', 'querydata', 'headers', 'options', 'opts']`
     *
     * @param  string $key just retrieve a given field from the hash
     * @return mixed
     */
    public function getLastRequest($key = null)
    {
        if (!isset($key)) {
            return $this->last_request;
        }

        return $this->last_request[$key];

    }

    /**
     * retrieve the last response we got
     *
     * valid keys are ['meta', 'status', 'headers', 'body']
     *
     * @param  string $key just retrieve a given field from the hash
     * @return mixed
     */
    public function getLastResponse($key = null)
    {
        if (!isset($key)) {
            return $this->last_response;
        }

        return $this->last_response[$key];

    }

    /**
     * get a specific link (via the rel tag) from the last response
     *
     * @param  string $rel
     * @return stdClass with a link and type.
     */
    public function getLink($rel)
    {
        $linkObj = new stdClass();
        $linkObj->link = '';
        $linkObj->type = '';
        if (isset($this->last_response['headers']['link'])) {
            $links = is_array($this->last_response['headers']['link']) ? $this->last_response['headers']['link'] : array($this->last_response['headers']['link']);
            foreach ($links as $link) {
                $matches = array();
                if (preg_match('/(<)(.*)(>;rel="(.*)";)(.*)(;type=")(.*)/', $link, $matches)) {
                    if ($rel == $matches[4]) {
                        $linkObj->link = $matches[2];
                        $linkObj->type = $matches[7];
                    }
                }
            }
        }

        return $linkObj;

    }

    /**
     * make a GET request
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|string $querydata hash of key/val pairs. This will be appended to the URL
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function get($url, $querydata = null, $headers = null, $options = null)
    {
        return $this->sendRequest($url, 'GET', $querydata, $headers, $options);
    }

    /**
     * make a POST request
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|string $querydata hash of key/val pairs. This will be sent in the request body
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function post($url, $querydata = null, $headers = null, $options = null)
    {
        return $this->sendRequest($url, 'POST', $querydata, $headers, $options);
    }

    /**
     * make a PUT request
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|string $querydata hash of key/val pairs. This will be sent in the request body
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function put($url, $querydata = null, $headers = null, $options = null)
    {
        return $this->sendRequest($url, 'PUT', $querydata, $headers, $options);
    }

    /**
     * make a PATCH request
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|string $querydata hash of key/val pairs. This will be sent in the request body
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function patch($url, $querydata = null, $headers = null, $options = null)
    {
        return $this->sendRequest($url, 'PATCH', $querydata, $headers, $options);
    }

    /**
     * make a DELETE request
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|string $querydata hash of key/val pairs. This will be appended to the URL
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function delete($url, $querydata = null, $headers = null, $options = null)
    {
        return $this->sendRequest($url, 'DELETE', $querydata, $headers, $options);
    }

    /**
     * make a POST request with a JSON body.
     *
     * The Content-Type header will be set to 'application/json'
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|stdClass $structure the array or object to send as JSON
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function postJson($url, $structure = null, $headers = null, $options = null)
    {
        return $this->sendJsonRequest($url, 'POST', $structure, $headers, $options);
    }

    /**
     * make a PUT request with a JSON body.
     *
     * The Content-Type header will be set to 'application/json'
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|stdClass $structure the array or object to send as JSON
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function putJson($url, $structure = null, $headers = null, $options = null)
    {
        return $this->sendJsonRequest($url, 'PUT', $structure, $headers, $options);
    }

    /**
     * make a PATCH request with a JSON body.
     *
     * The Content-Type header will be set to 'application/json'
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  array|stdClass $structure the array or object to send as JSON
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     */
    public function patchJson($url, $structure = null, $headers = null, $options = null)
    {
        return $this->sendJsonRequest($url, 'PATCH', $structure, $headers, $options);
    }

    /**
     * A wrapper for Resty::sendRequest that encodes the $structure as JSON
     * and sets the Content-Type header will be set to 'application/json'
     *
     * @param  string       $url the URL. This will be appended to the base_url, if any set
     * @param  string       $method  the HTTP method. This should really only be (POST|PUT|PATCH)
     * @param  array|stdClass $structure the array or object to send as JSON
     * @param  array        $headers hash of key/val pairs
     * @param  array        $options hash of key/val pairs ('timeout')
     * @return array        the response hash
     * @see    Resty::sendRequest() the method that sends a request
     * @author Ed Finkler
     */
    protected function sendJsonRequest($url, $method, $structure = null, $headers = null, $options = null)
    {
        if (!$headers) {
            $headers = array();
        }
        $headers['Content-Type'] = 'application/json';
        return $this->sendRequest($url, $method, json_encode($structure), $headers, $options);
    }


    /**
     *
     * POST one or more files
     *
     * The $files array should be a set of key/val pairs, with the key being
     * the field name, and the val the file path. ex:
     * $files['avatar'] = '/path/to/file.jpg';
     * $files['background'] = '/path/to/file2.jpg';
     *
     * @param  string $url
     * @param  array  $files
     * @param  array  $params
     * @param  array  $headers
     * @param  array  $options
     * @return array
     *
     */
    public function postFiles($url, $files, $params = null, $headers = null, $options = null)
    {

        $datastr = "";
        $boundary = "---------------------".substr(md5(rand(0, 32000)), 0, 10);

        // build params
        if (isset($params)) {
            foreach ($params as $key => $val) {
                $datastr .= "--$boundary\n";
                $datastr .= "Content-Disposition: form-data; name=\"".$key."\"\n\n".$val."\n";
            }
        }
        $datastr .= "--$boundary\n";

        // build files
        foreach ($files as $key => $file) {
            $filename = pathinfo($file, PATHINFO_BASENAME);
            $content_type = $this->getMimeType($file);
            $fileContents = file_get_contents($file);

            $datastr .= "Content-Disposition: form-data; name=\"{$key}\"; filename=\"{$filename}\"\n";
            $datastr .= "Content-Type: {$content_type}\n";
            $datastr .= "Content-Transfer-Encoding: binary\n\n";
            $datastr .= $fileContents."\n";
            $datastr .= "--$boundary\n";
        }

        if (!isset($headers)) {
            $headers = array();
        }
        $headers['Content-Type'] = 'multipart/form-data; boundary='.$boundary;

        return $this->post($url, $datastr, $headers, $options);
    }

    /**
     *
     * POST binary data directly, without reading from file(s)
     *
     * The $binary_data array should be a set of key/val pairs, with the key being
     * the field name, and the val the binary data. ex:
     * $files['avatar'] = <BINARY>;
     * $files['background'] = <BINARY>;
     *
     * with that data, a multipart POST body is created, identical to a file
     * upload, just without reading the data from a file
     *
     * @param  string $url
     * @param  array  $binary_data
     * @param  array  $params
     * @param  array  $headers
     * @param  array  $options
     * @return array
     */
    public function postBinary($url, $binary_data, $params = null, $headers = null, $options = null)
    {

        $datastr = "";
        $boundary = "---------------------".substr(md5(rand(0, 32000)), 0, 10);

        // build params
        if (isset($params)) {
            foreach ($params as $key => $val) {
                $datastr .= "--$boundary\n";
                $datastr .= "Content-Disposition: form-data; name=\"".$key."\"\n\n".$val."\n";
            }
        }
        $datastr .= "--$boundary\n";

        // build files
        foreach ($binary_data as $key => $bdata) {
            $filename = 'bdata';
            $content_type = 'application/octet-stream';

            $datastr .= "Content-Disposition: form-data; name=\"{$key}\"; filename=\"{$filename}\"\n";
            $datastr .= "Content-Type: {$content_type}\n";
            $datastr .= "Content-Transfer-Encoding: binary\n\n";
            $datastr .= $bdata."\n";
            $datastr .= "--$boundary\n";
        }

        if (!isset($headers)) {
            $headers = array();
        }
        $headers['Content-Type'] = 'multipart/form-data; boundary='.$boundary;

        return $this->post($url, $datastr, $headers, $options);
    }

    /**
     *
     * Stole this from the Amazon S3 class:
     *
     * Copyright (c) 2008, Donovan Schönknecht.  All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without
     * modification, are permitted provided that the following conditions are met:
     *
     * - Redistributions of source code must retain the above copyright notice,
     *   this list of conditions and the following disclaimer.
     * - Redistributions in binary form must reproduce the above copyright
     *   notice, this list of conditions and the following disclaimer in the
     *   documentation and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
     * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
     * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
     * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
     * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
     * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
     * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
     * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
     * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
     * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
     * POSSIBILITY OF SUCH DAMAGE.
     *
     * Amazon S3 is a trademark of Amazon.com, Inc. or its affiliates.
     *
     * @param string $filepath the path to the file
     * @see   Resty::postFiles()    the method to post files
     */
    protected function getMimeType($filepath)
    {

        if (extension_loaded('fileinfo') && isset($_ENV['MAGIC']) &&
            ($finfo = finfo_open(FILEINFO_MIME, $_ENV['MAGIC'])) !== false) {

            if (($type = finfo_file($finfo, $filepath)) !== false) {
                // Remove the charset and grab the last content-type
                $type = explode(' ', str_replace('; charset=', ';charset=', $type));
                $type = array_pop($type);
                $type = explode(';', $type);
                $type = trim(array_shift($type));
            }
            finfo_close($finfo);

        // If anyone is still using mime_content_type()
        } elseif (function_exists('mime_content_type')) {
            $type = trim(mime_content_type($filepath));
        }

        if ($type !== false && strlen($type) > 0) {
            return $type;
        }

        // Otherwise do it the old fashioned way
        static $exts = array(
            'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'png' => 'image/png',
            'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'ico' => 'image/x-icon',
            'swf' => 'application/x-shockwave-flash', 'pdf' => 'application/pdf',
            'zip' => 'application/zip', 'gz' => 'application/x-gzip',
            'tar' => 'application/x-tar', 'bz' => 'application/x-bzip',
            'bz2' => 'application/x-bzip2', 'txt' => 'text/plain',
            'asc' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html',
            'css' => 'text/css', 'js' => 'text/javascript',
            'xml' => 'text/xml', 'xsl' => 'application/xsl+xml',
            'ogg' => 'application/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/x-wav',
            'avi' => 'video/x-msvideo', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg',
            'mov' => 'video/quicktime', 'flv' => 'video/x-flv', 'php' => 'text/x-php'
        );
        $ext = strtolower(pathInfo($filepath, PATHINFO_EXTENSION));

        return isset($exts[$ext]) ? $exts[$ext] : 'application/octet-stream';
    }

    /**
     * bc wrapper
     * @param bool $state
     */
    public function enableDebugging($state = false)
    {
        $this->debug($state);
    }

    /**
     * enable or disable debugging. If no arg passed, just returns current state
     * @param  bool $state if not passed, state not changed
     * @return bool the current state
     */
    public function debug($state = null)
    {
        if (isset($state)) {
            $this->debug = (bool)$state;
        }
        return $this->debug;
    }

    /**
     * raise an exception from fopen if trying to open stream fails
     * @param  boolean $state = null optional, set the state
     * @return boolean the current state
     */
    public function raiseFopenException($state = null)
    {
        if (isset($state)) {
            $this->raise_fopen_exception = (bool)$state;
        }
        return $this->raise_fopen_exception;
    }

    /**
     * silence warnings from fopen when trying to open stream
     * @param  boolean $state optional, set the state
     * @return boolean the current state
     */
    public function silenceFopenWarning($state = null)
    {
        if (isset($state)) {
            $this->silence_fopen_warning = (bool)$state;
        }
        return $this->silence_fopen_warning;
    }

    /**
     * configure whether or not the REST endpoint supports the HTTP PATCH method. If set
     * to false, the X-HTTP-Method-Override header will be sent using HTTP POST.
     * @param  boolean $state = null optional, set the state
     * @return boolean the current state
     */
    public function supportsPatch($state = null)
    {
        if (isset($state)) {
            $this->supports_patch = (bool)$state;
        }
        return $this->supports_patch;
    }

    /**
     * configure whether to decode JSON into an array or an object. By default,
     * converts to object.
     * @param  boolean $state = null optional, set the state
     * @return boolean the current state
     */
    public function jsonToArray($state = null)
    {
        if (isset($state)) {
            $this->json_to_array = (bool)$state;
        }
        return $this->json_to_array;
    }

    /**
     * sets an alternate logging method
     * @param Closure $logger
     */
    public function setLogger(\Closure $logger)
    {
        $this->logger = $logger;
    }

    /**
     * enable or disable automatic parsing of body. default is true
     * @param bool $state default TRUE
     */
    public function parseBody($state = true)
    {
        $state = (bool)$state;
        $this->parse_body = $state;
    }

    /**
     * Sets the base URL for all subsequent requests
     * @param string $base_url
     */
    public function setBaseURL($base_url)
    {
        $this->base_url = $base_url;
    }

    /**
     * retrieves the current Resty::$base_url
     * @return string
     */
    public function getBaseURL()
    {
        return $this->base_url;
    }

    /**
     * Sets the user-agent
     * @param string $user_agent
     */
    public function setUserAgent($user_agent)
    {
        $this->user_agent = $user_agent;
    }

    /**
     * Gets the current user agent. if Resty::$user_agent is not set, uses a default
     * @return string
     */
    public function getUserAgent()
    {
        if (empty($this->user_agent)) {
            $this->user_agent = 'Resty ' . static::VERSION;
        }
        return $this->user_agent;
    }

    /**
     * Sets credentials for http basic auth
     * @param string $username
     * @param string $password
     */
    public function setCredentials($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * removes current credentials
     */
    public function clearCredentials()
    {
        $this->username = null;
        $this->password = null;
    }

    /**
     * takes a set of key/val pairs and builds an array of raw header strings
     *
     * @param  string $headers
     * @return void
     * @author Ed Finkler
     */
    protected function buildHeadersArray($headers)
    {
        $str_headers = array();
        foreach ($headers as $key => $value) {
            $str_headers[] = "{$key}: {$value}";
        }
        return $str_headers;
    }

    /**
     * Extracts the headers of a response from the stream's meta data
     * @param  array $meta
     * @return array
     */
    protected function metaToHeaders($meta)
    {
        $headers = array();

        if (!isset($meta['wrapper_data'])) {
            return $headers;
        }

        foreach ($meta['wrapper_data'] as $value) {
            if (strpos($value, 'HTTP') !== 0) {
                preg_match("|^([^:]+):\s?(.+)$|", $value, $matches);
                if (is_array($matches) && isset($matches[2])) {
                    $header_key = trim($matches[1]);
                    $header_value = trim($matches[2], " \t\n\r\0\x0B\"");
                    if (isset($headers[$header_key])) {
                        if (is_array($headers[$header_key])) {
                            $headers[$header_key][] = $header_value;
                        } else {
                            $previous_entry = $headers[$header_key];
                            $headers[$header_key] = array($previous_entry, $header_value);
                        }
                    } else {
                        $headers[$header_key] = $header_value;
                    }
                }
            }
        }
        return $headers;
    }

    /**
     * extracts the status code from the stream meta data
     * @param  array $meta
     * @return integer
     */
    protected function getStatusCode($meta)
    {
        $matches = array();

        // We need last http status
        $statusHeader = null;
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
            foreach (array_reverse($meta['wrapper_data']) as $header) {
                if (strpos($header, 'HTTP') === 0) {
                    $statusHeader = $header;
                    break;
                }
            }
        }

        $status = 0;
        preg_match("|\s(\d\d\d)\s?|", $statusHeader, $matches);
        if (is_array($matches) && isset($matches[1])) {
            $status = (int)trim($matches[1]);
        }
        return $status;
    }

    /**
     * Sends the HTTP request and retrieves/parses the response
     *
     * @param  string       $url
     * @param  string       $method
     * @param  array|string $querydata OPTIONAL
     * @param  array        $headers OPTIONAL
     * @param  array        $options OPTIONAL
     * @return array
     * @author Ed Finkler
     */
    public function sendRequest($url, $method = 'GET', $querydata = null, $headers = null, $options = null)
    {
        $resp = array();

        if ($this->base_url) {
            $url = $this->base_url.$url;
        }

        // we need to supply a default content-type
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        // by default, pass the header "Connection: close"
        if (!isset($headers['Connection'])) {
            $headers['Connection'] = 'close';
        }

        // if we have a username and password, use it
        if (isset($this->username) && isset($this->password) && !isset($headers['Authorization'])) {
            $this->log("{$this->username}:{$this->password}");
            $headers['Authorization'] = 'Basic '.base64_encode("{$this->username}:{$this->password}");
        }

        // if PATCH isn't supported, include it as header option.
        if ($method == 'PATCH' && !$this->supports_patch) {
            $headers['X-HTTP-Method-Override'] = $method;
            $method = 'POST';
        }

        // default timeout
        $timeout = isset($options['timeout']) ? $options['timeout'] : static::DEFAULT_TIMEOUT;

        // default max_redirects
        $max_redirects = isset($options['max_redirects']) ? $options['max_redirects'] : static::DEFAULT_MAX_REDIRECTS;

        $content = null;

        // if querydata is a string, just pass it as-is
        if (isset($querydata) && is_string($querydata)) {

            $content = $querydata;

        // else if it's an array, make an http query
        // or if it's an StdClass object, it will be cast to an array
        //   e.g. json_decode returns an object if not passed $assoc=true
        } elseif (isset($querydata) && (is_array($querydata) || $querydata instanceof \StdClass)) {

            $content = http_build_query((array)$querydata);

        }

        // create an array of header strings from the hash
        $headerarr = isset($headers) ? $this->buildHeadersArray($headers) : array();

        // GET and DELETE should use the URL to pass data
        $urlcontent = ('GET' === $method || 'DELETE' === $method);

        // if this is a GET or DELETE and we have some $content, append to URL
        if ($urlcontent && isset($content)) {
            $url .= '?'.$content;
        }


        $opts = array(
            'http'=>array(
                'timeout'=>$timeout,
                'method'=>$method,
                'content'=> (!$urlcontent) ? $content : null,
                'user_agent'=>$this->getUserAgent(),
                'header'=>$headerarr,
                'ignore_errors'=>1,
                'max_redirects'=>$max_redirects
            ),
            'ssl' => array(
                'verify_peer'=>false,
                'verify_peer_name'=>false
            )
        );

        $this->log('URL =================');
        $this->log($url);

        $this->log('METHOD =================');
        $this->log($method);

        $this->log('QUERYDATA =================');
        $this->log($querydata);

        $this->log('HEADERS =================');
        $this->log($headers);

        $this->log('OPTIONS =================');
        $this->log($options);

        $this->log('OPTS =================');
        $this->log($opts);

        $this->last_request = compact('url', 'method', 'querydata', 'headers', 'options', 'opts');
        // call custom req log callback
        if (!empty($this->callbacks['onRequestLog'])) {
            $this->callbacks['onRequestLog']($this->last_request);
        }


        $resp_data = $this->makeStreamRequest($url, $opts);

        $resp['meta'] = $resp_data['meta'];
        $resp['body'] = $resp_data['body'];
        $resp['error'] = $resp_data['error'];
        $resp['error_msg'] = $resp_data['error_msg'];
        $resp['status'] = $this->getStatusCode($resp['meta']);
        $resp['headers'] = $this->metaToHeaders($resp['meta']);
        $this->log($resp);

        $this->log("Processing response body...");
        $resp = $this->processResponseBody($resp);
        $this->log($resp['body']);

        $this->last_response = $resp;

        // call custom resp log callback
        if (!empty($this->callbacks['onResponseLog'])) {
            $this->callbacks['onResponseLog']($this->last_response);
        }

        return $resp;
    }

    /**
     * opens an http stream, sends the request, and returns result
     * @param  string $url
     * @param  array  $opts
     * @throws Exception If opening stream fails.
     * @return array
     */
    protected function makeStreamRequest($url, $opts)
    {

        $resp_data = array(
            'meta' => null,
            'body' => null,
            'error' => true,
            'error_msg' => null,
        );

        $context = stream_context_create($opts);

        $this->log("Sending...");
        $start_time = microtime(true);

        $this->log("Opening stream...");
        if ($this->silence_fopen_warning) {
            $stream = @fopen($url, 'r', false, $context);
        } else {
            $stream = fopen($url, 'r', false, $context);
        }

        if (!$stream) {

            $req_time = static::calcTimePassed($start_time);
            $opts_json = !empty($opts) ? json_encode($opts) : 'null';
            $msg = "Stream open failed for '{$url}'; req_time: {$req_time}; opts: {$opts_json}";
            $this->log($msg);

            if ($this->raise_fopen_exception) {
                throw new \Exception($msg);
            } else {
                $resp_data['error'] = true;
                $resp_data['error_msg'] = $msg;
            }

        } else {

            $this->log("Getting metadata...");
            $resp_data['meta'] = stream_get_meta_data($stream);

            $this->log("Getting response...");
            $resp_data['body'] = stream_get_contents($stream);

            $this->log("Closing stream...");
            fclose($stream);

        }


        if ($this->debug) {
            $req_time = static::calcTimePassed($start_time);
            $this->log(sprintf("Request time for \"%s %s\": %f", $opts['http']['method'], $url, $req_time));
        }

        return $resp_data;

    }

    /**
     * If we get back something that claims to be XML or JSON, parse it as such and assign to $resp['body']
     *
     * @param  string $resp
     * @return string|object
     * @see    Resty::$JSON_TYPES   the array of valid JSON contenty types
     * @see    Resty::$XML_TYPES    the array of valid XML contenty types
     */
    protected function processResponseBody($resp)
    {

        if ($this->parse_body === true) {

            $header_content_type = isset($resp['headers']['Content-Type']) ? $resp['headers']['Content-Type'] : null;

            // If redirected, we use last Content-Type
            if (is_array($header_content_type)) {
                $header_content_type = end($header_content_type);
            }

            $content_type = preg_split('/[;\s]+/', $header_content_type);
            $content_type = $content_type[0];

            if (in_array($content_type, static::$JSON_TYPES) || strpos($content_type, '+json') !== false) {

                $this->log("Response body is JSON");
                $resp['body_raw'] = $resp['body'];
                $resp['body'] = json_decode($resp['body'], $this->json_to_array);
                return $resp;

            } elseif (in_array($content_type, static::$XML_TYPES) || strpos($content_type, '+xml') !== false) {

                $this->log("Response body is XML");
                $resp['body_raw'] = $resp['body'];
                $resp['body'] = new \SimpleXMLElement($resp['body']);
                return $resp;

            }

        }

        $this->log("Response body not parsed");

        return $resp;

    }

    /**
     * calculate time passed in microtime
     * @param  float $start_time should be result of microtime(true)
     * @return float the diff between passed microtime and current microtime
     */
    protected static function calcTimePassed($start_time)
    {
        $stop_time = microtime(true);
        $req_time = $stop_time - $start_time;
        return $req_time;
    }

    /**
     * The wrapper method for logging. Either calls a custom logger or
     * Resty::defaultLogger()
     * @param  mixed $msg
     * @return array
     * @see    Resty::defaultLogger()   The default logging method
     */
    protected function log($msg)
    {
        if (!$this->debug) {
            return;
        }

        if (is_callable($this->logger)) {
            $logger = $this->logger;
            return $logger($msg);
        }

        return $this->defaultLogger($msg);
    }

    /**
     * logging helper
     *
     * @param  mixed $msg
     * @return array
     */
    protected function defaultLogger($msg)
    {

        $line = date(\DateTime::RFC822) . " :: ";

        if (is_string($msg)) {
            $line .= "{$msg}\n";
        } else {
            ob_start();
            var_dump($msg);
            $line = ob_get_clean();
            $line .= "\n";
        }

        if (PHP_SAPI !== 'cli') {
            $line = "<pre>$line</pre>\n";
        }

        return error_log($line);
    }
}
