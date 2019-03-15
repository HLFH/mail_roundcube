<?php
/**
 * Use a PHP script to perform a login to the Roundcube mail system.
 *
 * @author Martin Reinhardt and David Jaedke and Philipp Heckel
 * @copyright 2012 Martin Reinhardt contact@martinreinhardt-online.de
 *
 * SCRIPT VERSION
 *   Version 3 (April 2012)
 *
 * REQUIREMENTS
 *   - A Roundcube installation (tested with 0.7.2)
 *    (older versions work with 0.2-beta, 0.3.x, 0.4-beta, 0.5, 0.5.1)
 *
 *   - Set the "check_ip"/"ip_check" in the config/main.inc.php file to FALSE
 *     Why? The server will perform the login, not the client (= two different IP addresses)
 *
 * INSTALLATION
 *   - Install RC on your server so that it can be accessed via the browser,
 *     e.g. at www.example.com/roundcube/
 *
 *   - Download this script and remove all spaces and new lines
 *     before "<?php" and after "?>"
 *
 *   - Include the class in your very own script and use it.
 *
 * USAGE
 *   The class provides four public methods:
 *
 *   - login($username, $password)
 *         Perform a login to the Roundcube mail system.
 *
 *         Note: If the client is already logged in, the script will re-login the user (logout/login).
 *               To prevent this behaviour, use the isLoggedIn()-function.
 *
 *         Returns: TRUE if the login suceeds, FALSE if the user/pass-combination is wrong
 *         Throws:  May throw a MailLoginException if Roundcube sends an unexpected answer
 *                  (that might happen if a new Roundcube version behaves different).
 *
 *   - isLoggedIn()
 *         Checks whether the client/browser is logged in and has a valid Roundcube session.
 *
 *         Returns: TRUE if the user is logged in, FALSE otherwise.
 *         Throws:  May also throw a MailLoginException (see above).
 *
 *   - logout()
 *         Performs a logout on the current Roundcube session.
 *
 *         Returns: TRUE if the logout was a success, FALSE otherwise.
 *         Throws:  May also throw a MailLoginException (see above).
 *
 *   - redirect()
 *         Simply redirects to Roundcube.
 *
 *
 *
 * AUTHOR/LICENSE/VERSION
 *   - Written by Philipp Heckel; Find a corresponding blog-post at
 *     http://blog.philippheckel.com/2008/05/16/roundcube-login-via-php-script/
 *
 *   - Updated April 2012, tested with Ubuntu/Firefox 3
 *     No license. Feel free to use it :-)
 *
 *   - The updated script has been tested with Roundcube 0.7.2.
 *     Older versions of the script work with Roundcube 0.2, 0.3, 0.4-beta
 *     and 0.5.1 (see blog post above)
 *
 */
namespace OCA\RoundCube;

class Login
{
    const CONNECTION_ESTABLISHED = "HTTP/1.0 200 Connection established\r\n\r\n";
    const FORM_URLENCODED = 'application/x-www-form-urlencoded';

    /**
     * Full address (URL) of RoundCube server
     *
     * Can be set via the first argument in the constructor.
     *
     * @var string
     */
    private $rcAddress;

    /**
     * Roundcube session ID
     *
     * RC sends its session ID in the answer. If the first attempt doesn't
     * work, the login-function retries it with the session ID. This does
     * work most of the times.
     *
     * @var string
     */
    private $rcSessionID;

    /**
     * No idea what this is .
     *
     *
     *
     *
     *
     */
    private $rcSessionAuth;

    /**
     * Last header value
     */
    private $lastHeaderResponse;

    /**
     * Location redirect, needed to detect successful login.
     */
    private $rcLocation;

    /**
     * Save the current status of the Roundcube session.
     * 0 = unkown, 1 = logged in, -1 = not logged in.
     *
     * @var int
     */
    private $rcLoginStatus = - 1;

    /**
     * Save the number of logins
     *
     * @var int
     */
    private $rcLoginCount = 0;

    /**
     * Roundcube 0.5.1 adds a request token for 'security'.
     * This variable
     * saves the last token and sends it with login and logout requests.
     *
     * @var string
     */
    private $lastToken;

    private $login;

    private $rcUrlResource;

    /**
     * Debugging can be enabled by setting the 5th argument
     * in the constructor to TRUE.
     *
     * @var bool
     */
    private $debugEnabled;

    /**
     * Trace can be enabled by setting the 6th argument
     * in the constructor to TRUE.
     *
     * @var bool
     */
    private $traceEnabled;

    /**
     * SSL Verification can be disabled by setting the 4th argument
     * in the constructor to TRUE.
     *
     * @var bool
     */
    private $sslVerifyDisabled;

    /**
     * Create a new RoundcubeLogin class.
     *
     * @param
     *            string server address, including protocol, port number and path
     *            e.g. http://example.com:81/mail/
     * @param
     *            bool Enable debugging, - shows the full POST and the response
     * @param
     *            bool disable SSL certificate verification
     */
    public function __construct($webmailAddress, $disableSSLverify = false, $enableDebug = false, $enableVerbose = false) {
        $this->debugEnabled = $enableDebug;

        $this->addDebug("__construct", "Creating new RoundCubeLogin instance");
        $this->addDebug("pre_construct", "Used Parameters:");
        $this->addDebug("pre_construct", "webmailAddress: " . $webmailAddress);
        $this->addDebug("pre_construct", "enableDebug: " . $enableDebug);
        $this->addDebug("pre_construct", "disableSSLverify: " . $disableSSLverify);

        $this->debugStack = array();
        $this->rcAddress = $webmailAddress;
        $this->rcLoginStatus = 0;
        $this->lastHeaderResponse = array();
        $this->rcLocation = false;
        $this->sslVerifyDisabled = $disableSSLverify;
        $this->traceEnabled = $enableVerbose;

        $this->addDebug("post_construct", "Created new RoundCubeLogin instance:");
        $this->addDebug("post_construct", "rcAddress: " . $this->rcAddress);
        $this->addDebug("pre_construct", "enableDebug: " . $enableDebug);
        $this->addDebug("pre_construct", "disableSSLverify: " . $disableSSLverify);
    }

    /**
     * Login to Roundcube using the IMAP username/password
     *
     * Note: If the function detects that we're already logged in,
     * it performs a re-login, i.e. a logout/login-combination to ensure
     * that the specified user is logged in.
     *
     * If you don't want this, use the isLoggedIn()-function
     * the RC without calling login().
     *
     * @param
     *            string IMAP username
     * @param
     *            string IMAP password (plain text)
     * @return string RoundCube session ID, otherwise '1'
     * @throws MailNetworkingxception
     * @throws MailLoginException
     *
     */
    public function login($username, $password) {
        $this->addDebug("login", "Logging in with " . $username);

        // If already logged in, perform a re-login (logout first)
        if ($this->isLoggedIn($this->rcSessionID, $this->rcSessionAuth)) {
            $this->logout();
        }

        $login = $username;
        // Try login
        $data = array(
            "_task" => "login",
            "_action" => "login",
// CCT edit
            // "_timezone" => "1", // what is this?  // CCT edit out
            // "_dstactive" => "1",  // CCT edit out
            "_timezone" => "America/Argentina/Buenos_Aires",  // CCT edit
            "_dstactive" => "0",  // CCT edit
// fin CCT edit
            "_url" => "",
            "_user" => urlencode($username),
            "_pass" => urlencode($password)
        );
        if ($this->lastToken) {
            $data["_token"] = $this->lastToken;
        }
        $response = $this->sendRequest($data);

        $this->rcLoginStatus = 0;

        // Login successful! A redirection to ./?_task=... is a success!
        if (preg_match('/.+_task=/mi', $this->rcLocation)) {
            $this->addDebug("login", "Login successfull. RC sent a redirection to ./?_task=..., that means we did it!");
            $this->rcLoginStatus = 1;
        } else
            foreach ($this->lastHeaderResponse as $headers) {

                if (!is_array($headers)) {
                    // Don't know why this should be the case ...
                    continue;
                }

                foreach ($headers as $header) {
                    // Login failure detected! If the login failed, RC sends the cookie
                    // "sessauth=-del-"
                    if (preg_match('/.+sessauth=-del-;/mi', $header)) {
                        $this->addDebug("login", "Login failed. RC sent 'sessauth=-del-'; User/Pass combination wrong.");
                        $this->rcLoginStatus = - 1;
                        break;
                    }
                }
            }

        if ($this->rcLoginStatus === 0) {
            // Unknown, neither failure nor success.
            // This maybe the case if no session ID was sent
            $this->addDebug("login", "Login status unkown. Neither failure nor success. This maybe the case if no session ID was sent");
            throw new MailLoginException("Unable to determine login-status due to technical problems.");
        }
        return $this->isLoggedIn();
    }

    /**
     * Returns whether there is an active Roundcube session.
     *
     * @return bool Return TRUE if a user is logged in, FALSE otherwise
     * @throws MailLoginException
     */
    public function isLoggedIn() {
        $loggedIn = false;
        try {
            $this->updateLoginStatus();
            if (!$this->rcLoginStatus) {
                $this->addDebug("isLoggedIn", "Could not determine login status. Unkown state received.");
            }
            if ($this->rcLoginStatus > 0) {
                $loggedIn = true;
            }
        } catch (Exception $e) {
            $this->addDebug("isLoggedIn", "Unkown error received during checking for already logged in user");
        }
        return $loggedIn;
    }

    /**
     * Logout from Roundcube
     *
     * @return bool Returns TRUE if the login was successful, FALSE otherwise
     */
    public function logout() {
        $data = array(
            "_action" => "logout",
            "_task" => "logout"
        );
        if ($this->lastToken) {
            $data["_token"] = $this->lastToken;
        }
        $this->sendRequest($data);
        // remove cookies
        return ! $this->isLoggedIn();
    }

    /**
     * Gets the current login status and the session cookie.
     *
     * It updates the private variables rcSessionID and rcLoginStatus by
     * sending a request to the main page and parsing the result for the login
     * form.
     */
    private function updateLoginStatus($forceUpdate = false) {
        if ($this->rcSessionID && $this->rcLoginStatus === 1 && !$forceUpdate) {
            \OCP\Util::writeLog('roundcube', __METHOD__ . ": Won't update: SesstionID={$this->rcSessionID}; LoginStatus={$this->rcLoginStatus}", \OCP\Util::DEBUG);
            return;
        }
        // Send request and maybe receive new session ID
        $response = $this->sendRequest();
        // Request token (since Roundcube 0.5.1)
        if (preg_match('/"request_token":"([^"]+)"/mi', $response, $m)) {
            $this->lastToken = $m[1];
        }
        if (preg_match('/<input[^>]+name="_token"[^>]+value="([^"]+)"/mi', $response, $m)) {
            $this->lastToken = $m[1];
        }
        // Login form available?
        if (preg_match('/<input[^>]+name="_pass"/mi', $response)) {
            $this->addDebug("updateLoginStatus", "Detected that we're NOT logged in.");
            $this->rcLoginStatus = -1;
        } elseif (preg_match('/<a class="button-logout"[^>]+href="\.\/\?_task=logout"/mi', $response)) {
            $this->addDebug("updateLoginStatus", "Detected that we're logged in.");
            $this->rcLoginStatus = 1;
        } elseif (preg_match('/<div[^>]+id="message(toolbar)?"/mi', $response)) {
            // Changed html since Roundcube 1.0: messagetoolbar instead of message
            $this->addDebug("updateLoginStatus", "Detected that we're logged in.");
            $this->rcLoginStatus = 1;
        } else {
            $this->addDebug("updateLoginStatus", "Unable to determine the login status. Did you change the RC version?");
            throw new MailLoginException("Unable to determine the login status. Unable to continue due to technical problems.");
        }
        // If no session ID is available now, throw an exception
        if (!$this->rcSessionID) {
            $this->addDebug("NO SESSION ID", "No session ID received. RC version changed?");
            throw new MailLoginException("No session ID received. Unable to continue due to technical problems.");
        }
    }

    /**
     * Send a POST/GET request to the Roundcube login-script
     * to simulate the login.
     *
     * If the second parameter $postData is set, the function will
     * use the POST method, otherwise a GET will be sent.
     *
     * Ensures that all cookies are sent and parses all response headers
     * for a new Roundcube session ID. If a new SID is found, rcSessionId is set.
     *
     * @param
     *            string Optional POST data in urlencoded form (param1=value1&...)
     * @return string Returns the complete request response with all headers.
     */
    private function sendRequest($postData = false) {
        $method = (!$postData) ? "GET" : "POST";

        $this->addDebug('sendRequest', 'Trying to connect via "' . $method . '" to URL "' . $this->rcAddress . '"');
        $responsObj = $this->openUrlConnection($this->rcAddress, $method, $postData);
        if (!$responsObj) {
            $this->addDebug("sendRequest", "Network connection failed. Please check your path for roundcube with url " . $this->rcAddress);
            throw new MailNetworkingException("Unable to determine network-status due to technical problems.");
        } else {
            // Read response and set received cookies
            $response = $responsObj->getContent();
            // Check for success. $http_response_header may not be set on failures
            if ($responsObj === false) {
                $this->addDebug("sendRequest", "Network connection failed while reading. Please check your path for roundcube with url {$this->rcAddress}");
                throw new MailNetworkingException("Unable to determine network-status due to technical problems.");
            }
            $responseHdr = $responsObj->getHeader();
            $authHeaders = array();
            $this->lastHeaderResponse = $responseHdr;

            foreach ($responseHdr as $key => $headers) {
                if (!is_array($headers)) {
                    // Don't know why this should be the case ...
                    continue;
                }

                if ($key === 'set-cookie') {
                    // Got session ID!
                    foreach ($headers as $header) {
                        $setCookie = preg_replace('/\s+/', ' ', trim($header));
                        $this->addDebug("sendRequest", "Got the following Set-Cookie Value: $setCookie");

                        // Got sessid
                        if (preg_match_all('/^(.*)\s*(roundcube_sessid=([^;]+);)(.*)\s*/i', $setCookie, $match)) {
                            $this->addDebug("sendRequest", "Got the following session ID: " . $match[3][0]);
                            $this->rcSessionID = $match[3][0];
                            $authHeaders[] = "Set-Cookie: $setCookie";
                        }
                        // Got sessauth
                        if (preg_match_all('/^(.*)\s*(roundcube_sessauth=([^;]+);)(.*)\s*/i', $setCookie, $match)) {
                            $this->addDebug("sendRequest", "Got the following session auth: " . $match[3][0]);
                            $this->rcSessionAuth = $match[3][0];
                            $authHeaders[] = "Set-Cookie: $setCookie";
                        }
                    }
                } elseif ($key === 'location') {
                    // Location header
                    foreach ($headers as $header) {
                        $this->rcLocation = $header;
                    }
                }
            }
            // Request token (since Roundcube 0.5.1)
            // if (preg_match('/"request_token":"([^"]+)",/mi', $response, $m)) { // CCT edit out
            if (preg_match('/"request_token":"([^"]+)"/mi', $response, $m)) { // CCT edit
                $this->lastToken = $m[1];
            }
            if (preg_match('/<input [^>]*name="_token"[^>]+value="([^"]+)"/mi', $response, $m)) {
                // override previous token (if this one exists!)
                $this->lastToken = $m[1];
            }
            if ($this->lastToken) {
                $this->addDebug("sendRequest", "Got the following token: " . $this->lastToken);
            }

            $this->emitAuthHeaders($authHeaders);
            // refresh cookies
        }
        return $response;
    }

    /**
     * open url connection
     *
     * @param string $pURL
     *            to use
     * @param array $pHeader
     *            resource contect
     * @param string $pMethod
     *            POST or GET request
     * @param string $pData
     *            data to send
     * @return response object
     */
    function openUrlConnection($pURL, $pMethod, $pData) {
        $this->addDebug("openUrlConnection", "url: " . $pURL);
        $this->addDebug("openUrlConnection", "method: " . $pMethod);
        $response = false;
        try {
            $curl = curl_init();
            // set URL
            curl_setopt($curl, CURLOPT_URL, $pURL);
            // general settings
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            if ($pMethod === 'POST') {
                if ($pData) {
                    curl_setopt($curl, CURLOPT_POST, true);
                    // url-ify the data for the POST
                    $postData = '';
                    foreach ($pData as $key => $value) {
                        $postData .= $key . '=' . $value . '&';
                    }
                    rtrim($postData, '&');
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
                    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                    // construct header
                    $headers = array();
                    $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
                    $headers[] = 'Accept-Encoding: identity';
                    $headers[] = 'Content-Type: ' . self::FORM_URLENCODED;
                    $headers[] = 'Content-Length:' . strlen($postData);
                    $headers[] = 'Cache-Control: no-cache';
                    $headers[] = 'Pragma: no-cache';

                    $this->addDebug("openUrlConnection", 'strlen($postData)' . strlen($postData));
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                }
            } else {
                curl_setopt($curl, CURLOPT_HTTPGET, true);
            }

            $cookie = '';
            if (isset($this->rcSessionID)) {
                $cookie .= "roundcube_sessid={$this->rcSessionID};";
            }
            if (isset($this->rcSessionAuth)) {
                $cookie .= "roundcube_sessauth={$this->rcSessionAuth};";
            }
            // append cookie values
            $this->addDebug("openUrlConnection", "cookie: $cookie");
            curl_setopt($curl, CURLOPT_COOKIE, $cookie);

            if ($this->sslVerifyDisabled) {
                $this->addDebug("openUrlConnection", "Disabling SSL verification.");
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            }

            if ($this->traceEnabled) {
                curl_setopt($curl, CURLOPT_VERBOSE, true);
            }
            curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);

            // run cURL
            $cUrlResponse = curl_exec($curl);

            // error handling
            $curlErrorNum = curl_errno($curl);
            $curlError = curl_error($curl);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $respHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $this->addDebug("openUrlConnection", "Got the following HTTP Status Code: ($respHttpCode) $curlError");
            if ($curlErrorNum !== CURLE_OK) {
                $this->addError("openUrlConnection", "Opening url $pURL failed with $curlError");
            } else {
                list ($responseHeaders, $responseBody) = $this->parseHttpResponse($cUrlResponse, $headerSize);
                $response = new Response($responseHeaders, $responseBody);
            }
            curl_close($curl);
        } catch (Exception $e) {
            $this->addError("openUrlConnection", "URL (url: $pURL) open failed.");
        }
        return $response;
    }

    /**
     *
     * @param
     *            $respData
     * @param
     *            $headerSize
     * @return array
     */
    public function parseHttpResponse($respData, $headerSize) {
        if (stripos($respData, self::CONNECTION_ESTABLISHED) !== false) {
            $respData = str_ireplace(self::CONNECTION_ESTABLISHED, '', $respData);
        }
        if ($headerSize) {
            $responseHeaders = substr($respData, 0, $headerSize);
            $responseBody = substr($respData, $headerSize);
        } else {
            list ($responseHeaders, $responseBody) = explode("\r\n\r\n", $respData, 2);
        }
        $responseHeaders = self::parseResponseHeaders($responseHeaders);
        $parsedResponse = array(
            $responseHeaders,
            $responseBody
        );
        return $parsedResponse;
    }

    public function parseResponseHeaders($rawHeaders) {
        $responseHeaders = array();
        $responseHeaderLines = explode("\r\n", $rawHeaders);
        foreach ($responseHeaderLines as $headerLine) {
            if ($headerLine && is_string($headerLine) && strpos($headerLine, ':') !== false) {
                list ($header, $value) = explode(': ', $headerLine, 2);
                $header = strtolower($header);
                if (isset($responseHeaders[$header])) {
                    // $responseHeaders[$header] .= "\n" . $value;
                    $responseHeaders[$header][] = $value;
                } else {
                    $responseHeaders[$header] = array(
                        $value
                    );
                }
            }
        }
        return $responseHeaders;
    }

    /**
     * Send authentication headers previously aquired
     */
    function emitAuthHeaders($pHeaders) {
        foreach ($pHeaders as $header) {
            $this->addDebug("emitAuthHeaders", $header);
            header($header, false /* replace or not??? */);
        }
    }

    /**
     * Print a error message.
     *
     * @param
     *            string Short action message
     * @param
     *            string Output data
     */
    private function addError($action, $data) {
        \OCP\Util::writeLog('roundcube', __CLASS__ . ":: $action:\n $data", \OCP\Util::ERROR);
    }

    /**
     * Print a debug message if debugging is enabled.
     *
     * @param
     *            string Short action message
     * @param
     *            string Output data
     */
    private function addDebug($action, $data) {
        if ($this->debugEnabled) {
            \OCP\Util::writeLog('roundcube', __CLASS__ . ":: $action:\n $data", \OCP\Util::DEBUG);
        }
    }

    /**
     * Get roundcube session ID
     */
    public function getSessionID() {
        return $this->rcSessionID;
    }

    /**
     * Set roundcube session ID
     *
     * @param
     *            sessionID to use $pSessionID
     */
    public function setSessionID($pSessionID) {
        $this->rcSessionID = $pSessionID;
    }

    /**
     * Get roundcube session Auth
     */
    public function getSessionAuth() {
        return $this->rcSessionAuth;
    }

    /**
     * Set roundcube session Auth
     *
     * @param
     *            sessionAuth to use $pSessionAuth
     */
    public function setSessionAuth($pSessionAuth) {
        $this->rcSessionAuth = $pSessionAuth;
    }
}

/**
 * Simple response wrapper class
 *
 * @author mreinhardt
 *
 */
class Response
{
    private $responseHeaders;
    private $content;

    public function __construct($pHeader, $pContent) {
        $this->responseHeaders = $pHeader;
        $this->content = $pContent;
    }

    /**
     *
     * @return http response header ($http_response_header)
     */
    public function getHeader() {
        return $this->responseHeaders;
    }

    /**
     *
     * @return response content
     */
    public function getContent() {
        return $this->content;
    }
}
