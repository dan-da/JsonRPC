<?php

namespace JsonRPC;

use Closure;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ConnectionFailureException;
use JsonRPC\Exception\ResponseException;
use JsonRPC\Exception\ServerErrorException;
use JsonRPC\Exception\HttpErrorException;


/**
 * HTTP Client
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Goetz <cpuidle@gmx.de>
 * @author     Dan Libby <dan@osc.co.cr>  - modified to fit into jsonrpc lib
 *                                          and to improve debug capabilities.
 *
 *
 * This class replaces the HttpClient class from fguillot/json-rpc.
 * This was done in order to use fsockopen() for http requests instead
 * of file_get_contents() or curl().   The primary benefit of fsockopen is that
 * we can log the raw http request, headers, and server response.
 * This was the main reason for forking this repo.
 * 
 * TODO: This class should be refactored to conform to the same
 * interface as the orig HttpClass and throw the same exceptions.
 * Only a minimal effort has been made in this direction so far and
 * expected behavior of error conditions is basically undefined.
 * 
 */


/**
 * This class implements a basic HTTP client
 *
 * It supports POST and GET, Proxy usage, basic authentication,
 * handles cookies and referers. It is based upon the httpclient
 * function from the VideoDB project.
 *
 * @link   http://www.splitbrain.org/go/videodb
 * @author Andreas Goetz <cpuidle@gmx.de>
 * @author Andreas Gohr <andi@splitbrain.org>
 */
class HttpClient {
    //set these if you like
    var $agent;         // User agent
    var $http;          // HTTP version defaults to 1.0
    var $timeout;       // read timeout (seconds)
    var $cookies;
    var $referer;
    var $max_redirect;
    var $max_bodysize;
    var $max_bodysize_abort = true;  // if set, abort if the response body is bigger than max_bodysize
    var $header_regexp; // if set this RE must match against the headers, else abort
    var $headers;
    var $debug;
    var $start = 0; // for timings

    // don't set these, read on error
    var $redirect_count;

    // read these after a successful request
    var $resp_status;
    var $resp_body;
    var $resp_headers;

    // set these to do basic authentication
    var $user;
    var $pass;

    // set these if you need to use a proxy
    var $proxy_host;
    var $proxy_port;
    var $proxy_user;
    var $proxy_pass;
    var $proxy_ssl; //boolean set to true if your proxy needs SSL
    
    var $url;
    
    var $request_fh;    // file-handle to write request to.
    var $response_fh;   // file-handle to write response to.
    
    var $debug_log = null;   // set this to empty array for a log.

    // what we use as boundary on multipart/form-data posts
    var $boundary = '---DokuWikiHTTPClient--4523452351';
    
    const HTTP_NL = "\r\n";
    
    const level_info = 'info';
    const level_warn = 'warn';
    const level_debug = 'debug';

    /**
     * Constructor.
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function __construct($url=null){
        $this->url          = $url;
        $this->agent        = 'Mozilla/4.0 (compatible; jsonrpc-cli HTTP Client; '.PHP_OS.')';
        $this->timeout      = 15;
        $this->cookies      = array();
        $this->referer      = '';
        $this->max_redirect = 3;
        $this->redirect_count = 0;
        $this->status       = 0;
        $this->headers      = array();
        $this->http         = '1.0';
        $this->debug        = false;
        $this->max_bodysize = 0;
        $this->header_regexp= '';
//        if(extension_loaded('zlib')) $this->headers['Accept-encoding'] = 'gzip';
        $this->headers['Accept'] = 'text/xml,application/xml,application/xhtml+xml,'.
                                   'text/html,text/plain,image/png,image/jpeg,image/gif,*/*';
        $this->headers['Accept-Language'] = 'en-us';
    }
    
    function setTimeout($timeout) {
        $this->timeout = $timeout;
    }


    /**
     * Simple function to do a GET request
     *
     * Returns the wanted page or false on an error;
     *
     * @param  string $url       The URL to fetch
     * @param  bool   $sloppy304 Return body on 304 not modified
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function get($sloppy304=false){
        if(!$this->sendRequest()) return false;
        if($this->status == 304 && $sloppy304) return $this->resp_body;
        if($this->status < 200 || $this->status > 206) return false;
        return $this->resp_body;
    }

    /**
     * Simple function to do a POST request
     *
     * Returns the resulting page or false on an error;
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function post($data){
        $url = $this->url;
        if(!$this->sendRequest($data,'POST')) {
            return false;
        }
        return $this->resp_body;
    }
    
    public function execute($payload, array $headers = []) {
        return json_decode($this->post($payload), true);
    }

    /**
     * Send an HTTP request
     *
     * This method handles the whole HTTP communication. It respects set proxy settings,
     * builds the request headers, follows redirects and parses the response.
     *
     * Post data should be passed as associative array. When passed as string it will be
     * sent as is. You will need to setup your own Content-Type header then.
     *
     * @param  string $url    - the complete URL
     * @param  mixed  $data   - the post data either as array or raw data
     * @param  string $method - HTTP Method usually GET or POST.
     * @return bool - true on success
     * @author Andreas Goetz <cpuidle@gmx.de>
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function sendRequest($data='',$method='GET'){
        $url = $this->url;
        $this->start  = $this->_time();
        $this->status = 0;

        // don't accept gzip if truncated bodies might occur
        if($this->max_bodysize &&
           !$this->max_bodysize_abort &&
           $this->headers['Accept-encoding'] == 'gzip'){
            unset($this->headers['Accept-encoding']);
        }

        // parse URL into bits
        $uri = parse_url($url);
        $server = @$uri['host'];
        $path   = @$uri['path'];
        if(empty($path)) $path = '/';
        if(!empty($uri['query'])) $path .= '?'.$uri['query'];
        $port = @$uri['port'];
        if(isset($uri['user'])) $this->user = $uri['user'];
        if(isset($uri['pass'])) $this->pass = $uri['pass'];

        // proxy setup
        if($this->proxy_host){
            $request_url = $url;
            $server      = $this->proxy_host;
            $port        = $this->proxy_port;
            if (empty($port)) $port = 8080;
        }else{
            $request_url = $path;
            $server      = $server;
            if (empty($port)) $port = (@$uri['scheme'] == 'https') ? 443 : 80;
        }

        // add SSL stream prefix if needed - needs SSL support in PHP
        if($port == 443 || $this->proxy_ssl) $server = 'ssl://'.$server;

        // prepare headers
        $headers               = $this->headers;
        $headers['Host']       = @$uri['host'];
        $headers['User-Agent'] = $this->agent;
        $headers['Referer']    = $this->referer;
        $headers['Connection'] = 'Close';
        if($method == 'POST'){
            if(is_array($data)){
                if($headers['Content-Type'] == 'multipart/form-data'){
                    $headers['Content-Type']   = 'multipart/form-data; boundary='.$this->boundary;
                    $data = $this->_postMultipartEncode($data);
                }else{
                    $headers['Content-Type']   = 'application/x-www-form-urlencoded';
                    $data = $this->_postEncode($data);
                }
            }
            $headers['Content-Length'] = strlen($data);
            $rmethod = 'POST';
        }elseif($method == 'GET'){
            $data = ''; //no data allowed on GET requests
        }
        if($this->user) {
            $headers['Authorization'] = 'Basic '.base64_encode($this->user.':'.$this->pass);
        }
        if($this->proxy_user) {
            $headers['Proxy-Authorization'] = 'Basic '.base64_encode($this->proxy_user.':'.$this->proxy_pass);
        }

        // stop time
        $start = time();

        // open socket
        $socket = @fsockopen($server,$port,$errno, $errstr, $this->timeout);
        if (!$socket){
            $msg = "Could not connect to $server:$port\n$errstr ($errno)";
            throw new ConnectionFailureException($msg);
        }
        //set non blocking
        stream_set_blocking($socket,0);

        // build request
        $request  = "$method $request_url HTTP/".$this->http. self::HTTP_NL;
        $request .= $this->_buildHeaders($headers);
        $request .= $this->_getCookies();
        $request .= self::HTTP_NL;
        $request .= $data;

        if( $this->request_fh ) {
            fwrite($this->request_fh, $request);
        }
        $this->_debug("sending request to $url", null, self::level_info);
        $this->_debug('request',$request, self::level_debug);

        // send request
        $towrite = strlen($request);
        $written = 0;
        while($written < $towrite){
            $ret = fwrite($socket, substr($request,$written));
            if($ret === false){
                $this->status = -100;
                $msg = 'Failed writing to socket';
                throw new ConnectionFailureException($msg);
            }
            $written += $ret;
        }
        $this->_debug('request finished sending.', null, self::level_info);

        // read headers from socket
        $r_headers = '';
        do{
            if(time()-$start > $this->timeout){
                $msg = sprintf('Timeout waiting for response headers (%.3fs)',$this->_time() - $this->start);
                throw new ResponseException($msg);
            }
            if(feof($socket)){
                $msg = 'Premature End of File (socket)';
                throw new ResponseException($msg);
            }
            $r_headers .= fgets($socket,1024);
        }while(!preg_match('/\r?\n\r?\n$/',$r_headers));

        if( $this->response_fh ) {
            if($this->response_fh == $this->request_fh) {
                fwrite($this->response_fh, "\n\n");
            }
            
            fwrite($this->response_fh, $r_headers);
        }
        
        $this->_debug('response headers',$r_headers, self::level_debug);
        
        // check if expected body size exceeds allowance
        if($this->max_bodysize && preg_match('/\r?\nContent-Length:\s*(\d+)\r?\n/i',$r_headers,$match)){
            if($match[1] > $this->max_bodysize){
                if ($this->max_bodysize_abort) {
                    $msg = 'Reported content length exceeds allowed response size';
                    throw new ResponseException($msg);
                }
            }
        }

        // get Status
        if (!preg_match('/^HTTP\/(\d\.\d)\s*(\d+).*?\n/', $r_headers, $m)) {
            $msg = 'Server returned bad answer';
            throw new ResponseException($msg);
        }
        $this->status = $m[2];
        $header_status = $m[0];

        $this->handleExceptions($this->status, $header_status);
        
        // handle headers and cookies
        $this->resp_headers = $this->_parseHeaders($r_headers);
        if(isset($this->resp_headers['set-cookie'])){
            foreach ((array) $this->resp_headers['set-cookie'] as $cookie){
                list($cookie)   = explode(';',$cookie,2);
                list($key,$val) = explode('=',$cookie,2);
                $key = trim($key);
                if($val == 'deleted'){
                    if(isset($this->cookies[$key])){
                        unset($this->cookies[$key]);
                    }
                }elseif($key){
                    $this->cookies[$key] = $val;
                }
            }
        }

        // $this->_debug('Object headers',$this->resp_headers);

        // check server status code to follow redirect
        if($this->status == 301 || $this->status == 302 ){
            if (empty($this->resp_headers['location'])){
                $msg = 'Redirect but no Location Header found';
                $this->_debug($msg, null, self::level_warn);
                throw new ResponseException($msg);
            }elseif($this->redirect_count == $this->max_redirect){
                $msg = 'Maximum number of redirects exceeded';
                $this->_debug($msg, null, self::level_warn);
                throw new ResponseException($msg);
            }else{
                $this->redirect_count++;
                $this->referer = $url;
                // handle non-RFC-compliant relative redirects
                if (!preg_match('/^http/i', $this->resp_headers['location'])){
                    if($this->resp_headers['location'][0] != '/'){
                        $this->resp_headers['location'] = $uri['scheme'].'://'.$uri['host'].':'.$uri['port'].
                                                          dirname($uri['path']).'/'.$this->resp_headers['location'];
                    }else{
                        $this->resp_headers['location'] = $uri['scheme'].'://'.$uri['host'].':'.$uri['port'].
                                                          $this->resp_headers['location'];
                    }
                }
                // perform redirected request, always via GET (required by RFC)
                return $this->sendRequest($this->resp_headers['location'],array(),'GET');
            }
        }

        // check if headers are as expected
        if($this->header_regexp && !preg_match($this->header_regexp,$r_headers)){
            $msg = 'The received headers did not match the given regexp';
            $this->_debug($msg, null, self::level_warn);
            throw new ResponseException($msg);
        }

        //read body (with chunked encoding if needed)
        $r_body    = '';
        if(preg_match('/transfer\-(en)?coding:\s*chunked\r\n/i',$r_headers)){
            do {
                unset($chunk_size);
                do {
                    if(feof($socket)){
                        $msg = 'Premature End of File (socket)';
                        $this->_debug($msg, null, self::level_warn);
                        throw new ResponseException($msg);
                    }
                    if(time()-$start > $this->timeout){
                        $msg = sprintf('Timeout while reading chunk (%.3fs)',$this->_time() - $this->start);
                        $this->_debug($msg, null, self::level_warn);
                        throw new ResponseException($msg);
                    }
                    $byte = fread($socket,1);
                    $chunk_size .= $byte;
                } while (preg_match('/[a-zA-Z0-9]/',$byte)); // read chunksize including \r

                $byte = fread($socket,1);     // readtrailing \n
                $chunk_size = hexdec($chunk_size);
                if ($chunk_size) {
                    $this_chunk = fread($socket,$chunk_size);
                    $r_body    .= $this_chunk;
                    $byte = fread($socket,2); // read trailing \r\n
                }

                if($this->max_bodysize && strlen($r_body) > $this->max_bodysize){
                    $msg = 'Allowed response size exceeded';
                    $this->_debug($msg, null, self::level_warn);
                    if ($this->max_bodysize_abort)
                        throw new ResponseException($msg);
                    else
                        break;
                }
            } while ($chunk_size);
        }else{
            // read entire socket
            while (!feof($socket)) {
                if(time()-$start > $this->timeout){
                    $this->status = -100;
                    $msg = sprintf('Timeout while reading response (%.3fs)',$this->_time() - $this->start);
                    $this->_debug($msg, null, self::level_warn);
                    throw new ResponseException($msg);
                }
                $r_body .= fread($socket,4096);
                $r_size = strlen($r_body);
                if($this->max_bodysize && $r_size > $this->max_bodysize){
                    $msg = 'Allowed response size exceeded';
                    $this->_debug($msg, null, self::level_warn);
                    if ($this->max_bodysize_abort) {
                        throw new ResponseException($msg);
                    }
                    else {
                        break;
                    }
                }
                if(isset($this->resp_headers['content-length']) &&
                   !isset($this->resp_headers['transfer-encoding']) &&
                   $this->resp_headers['content-length'] == $r_size){
                    // we read the content-length, finish here
                    break;
                }
            }
        }

        // close socket
        $status = socket_get_status($socket);
        fclose($socket);

        $this->_debug('Response received.', null, self::level_info);

        
        // decode gzip if needed
        if(isset($this->resp_headers['content-encoding']) &&
           $this->resp_headers['content-encoding'] == 'gzip' &&
           strlen($r_body) > 10 && substr($r_body,0,3)=="\x1f\x8b\x08"){
            $this->resp_body = @gzinflate(substr($r_body, 10));
        }else{
            $this->resp_body = $r_body;
        }
        
        if( $this->response_fh ) {
            fwrite($this->response_fh, $this->resp_body);
        }
        
        $this->_debug('Response body',$this->resp_body, self::level_debug);
        $this->redirect_count = 0;
        return true;
    }
    
    function withUsername($user) {
        $this->user = $user;
        return $this;
    }
    
    function withPassword($password) {
        $this->pass = $password;
        return $this;
    }
    
    function withHeaders($headers) {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }
    
    function withDebug($flag = true) {
        $this->debug = $flag;
        return $this;
    }
    

    /**
     * print debug info
     *
     * $level = info,debug
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _debug($info,$var=null, $level=null){
        if(!$this->debug && !is_array($this->debug_log)) {
            return;
        }
        $level = $level ?: self::level_info;
        
        $buf = $info;
        if(!is_null($var)){
            $buf .= " -->\n" . print_r($var, true);
        }
        
        if( is_array($this->debug_log )) {
            $this->debug_log[] = ['time' => microtime(true),
                                  'level' => $level,
                                  'msg'  => $buf ];
        }
        
        if( $this->debug ) {
            echo $info . ' ' . ($this->_time() - $this->start).'s -- ' . "\n" . $buf . "\n";
        }
    }

    /**
     * Return current timestamp in microsecond resolution
     */
    function _time(){
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * convert given header string to Header array
     *
     * All Keys are lowercased.
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _parseHeaders($string){
        $headers = array();
        $lines = explode("\n",$string);
        foreach($lines as $line){
            @list($key,$val) = @explode(':',$line,2);
            $key = strtolower(trim($key));
            $val = trim($val);
            if(empty($val)) continue;
            if(isset($headers[$key])){
                if(is_array($headers[$key])){
                    $headers[$key][] = $val;
                }else{
                    $headers[$key] = array($headers[$key],$val);
                }
            }else{
                $headers[$key] = $val;
            }
        }
        return $headers;
    }

    /**
     * convert given header array to header string
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _buildHeaders($headers){
        $string = '';
        foreach($headers as $key => $value){
            if(empty($value)) continue;
            $string .= $key.': '.$value. self::HTTP_NL;
        }
        return $string;
    }

    /**
     * get cookies as http header string
     *
     * @author Andreas Goetz <cpuidle@gmx.de>
     */
    function _getCookies(){
        $headers = '';
        foreach ($this->cookies as $key => $val){
            $headers .= "$key=$val; ";
        }
        $headers = substr($headers, 0, -2);
        if ($headers !== '') $headers = "Cookie: $headers". self::HTTP_NL;
        return $headers;
    }

    /**
     * Encode data for posting
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _postEncode($data){
        foreach($data as $key => $val){
            if($url) $url .= '&';
            $url .= urlencode($key).'='.urlencode($val);
        }
        return $url;
    }

    /**
     * Encode data for posting using multipart encoding
     *
     * @fixme use of urlencode might be wrong here
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function _postMultipartEncode($data){
        $boundary = '--'.$this->boundary;
        $out = '';
        foreach($data as $key => $val){
            $out .= $boundary. self::HTTP_NL;
            if(!is_array($val)){
                $out .= 'Content-Disposition: form-data; name="'.urlencode($key).'"'. self::HTTP_NL;
                $out .= self::HTTP_NL; // end of headers
                $out .= $val;
                $out .= self::HTTP_NL;
            }else{
                $out .= 'Content-Disposition: form-data; name="'.urlencode($key).'"';
                if($val['filename']) $out .= '; filename="'.urlencode($val['filename']).'"';
                $out .= self::HTTP_NL;
                if($val['mimetype']) $out .= 'Content-Type: '.$val['mimetype']. self::HTTP_NL;
                $out .= self::HTTP_NL; // end of headers
                $out .= $val['body'];
                $out .= self::HTTP_NL;
            }
        }
        $out .= "$boundary--". self::HTTP_NL;
        return $out;
    }

    
    /**
     * Throw an exception according the HTTP response
     *
     * @param string $status
     *
     * @throws AccessDeniedException
     * @throws ConnectionFailureException
     * @throws ServerErrorException
     */
    public function handleExceptions($code, $response)
    {
        $exceptions = [
            '401' => '\JsonRPC\Exception\AuthenticationFailureException',
            '403' => '\JsonRPC\Exception\AccessDeniedException',
            '404' => '\JsonRPC\Exception\NotFoundException',
            '500' => '\JsonRPC\Exception\ServerErrorException',
        ];
        
        $code = (string)$code;
        $response = trim($response);
        
        $exception = @$exceptions[$code];
        if( $exception ) {
            throw new $exception($response, $code);
        }
        else if( $code != 200) {
            throw new HttpErrorException($response, $code);
        }
    }
    
    /**
     * Assign a callback before the request
     *
     * @param  Closure $closure
     *
     * @return $this
     */
    public function withBeforeRequestCallback(Closure $closure)
    {
        $this->beforeRequest = $closure;

        return $this;
    }

    /**
     * Assign a callback after the request
     *
     * @param  Closure $closure
     *
     * @return $this
     */
    public function withAfterRequestCallback(Closure $closure)
    {
        $this->afterRequest = $closure;

        return $this;
    }
    
    
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
