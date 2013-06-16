<?php
/**
 * A class that makes it easy to connect to and consume the Twitter stream via the Streaming API.
 *
 * Note: This is beta software - Please read the following carefully before using:
 *  - http://code.google.com/p/phirehose/wiki/Introduction
 *  - http://dev.twitter.com/pages/streaming_api
 * @author  Fenn Bailey <fenn.bailey@gmail.com>
 * @version 0.2.gitmaster
 */
abstract class Phirehose
{
  
  /**
   * Class constants
   */
  const URL_BASE         = 'https://stream.twitter.com/1.1/statuses/';
  const FORMAT_JSON      = 'json';
  const FORMAT_XML       = 'xml';
  const METHOD_FILTER    = 'filter';
  const METHOD_SAMPLE    = 'sample';
  const METHOD_RETWEET   = 'retweet';
  const METHOD_FIREHOSE  = 'firehose';
  const METHOD_LINKS     = 'links';
  const EARTH_RADIUS_KM  = 6371;
  
  
  /**
   * Member Attribs
   */
  protected $username;
  protected $password;
  protected $method;
  protected $format;
  protected $count; //Can be -150,000 to 150,000. @see http://dev.twitter.com/pages/streaming_api_methods#count
  protected $followIds;
  protected $trackWords;
  protected $locationBoxes;
  protected $conn;
  protected $fdrPool;
  protected $buff;
  // State vars
  protected $filterChanged;
  protected $reconnect;

  /**
  * The number of tweets received per second in previous minute; calculated fresh
  * just before each call to statusUpdate()
  * I.e. if fewer than 30 tweets in last minute then this will be zero; if 30 to 90 then it
  * will be 1, if 90 to 150 then 2, etc.
  *
  * @var integer
  */
  protected $statusRate;

  protected $lastErrorNo;
  protected $lastErrorMsg;

  /**
  * Number of tweets received.
  *
  * Note: by default this is the sum for last 60 seconds, and is therefore
  * reset every 60 seconds.
  * To change this behaviour write a custom statusUpdate() function.
  *
  * @var integer
  */
  protected $statusCount=0;

  /**
  * The number of calls to $this->checkFilterPredicates().
  *
  * By default it is called every 5 seconds, so if doing statusUpdates every
  * 60 seconds and then resetting it, this will usually be 12.
  *
  * @var integer
  */
  protected $filterCheckCount=0;

  /**
  * Total number of seconds (fractional) spent in the enqueueStatus() calls (i.e. the customized
  * function that handles each received tweet).
  *
  * @var float
  */
  protected $enqueueSpent=0;

  /**
  * Total number of seconds (fractional) spent in the checkFilterPredicates() calls
  *
  * @var float
  */
  protected $filterCheckSpent=0;

  /**
  * Number of seconds since the last tweet arrived (or the keep-alive newline)
  *
  * @var integer
  */
  protected $idlePeriod=0;

  /**
  * The maximum value $this->idlePeriod has reached.
  *
  * @var integer
  */
  protected $maxIdlePeriod=0;

  /**
  * Time spent on each call to enqueueStatus() (i.e. average time spent, in milliseconds,
  * spent processing received tweet).
  *
  * Simply: enqueueSpent divided by statusCount
  * Note: by default, calculated fresh for past 60 seconds, every 60 seconds.
  *
  * @var float
  */
  protected $enqueueTimeMS=0;

  /**
  * Like $enqueueTimeMS but for the checkFilterPredicates() function.
  * @var float
  */
  protected $filterCheckTimeMS=0;

  /**
  * Seconds since the last call to statusUpdate()
  *
  * Reset to zero after each call to statusUpdate()
  * Highest value it should ever reach is $this->avgPeriod
  *
  * @var integer
  */
  protected $avgElapsed=0;

  // Config type vars - override in subclass if desired
  protected $connectFailuresMax = 20;
  protected $connectTimeout = 5;
  protected $readTimeout = 5;
  protected $idleReconnectTimeout = 90;
  protected $avgPeriod = 60;
  protected $status_length_base = 10;
  protected $userAgent       = 'Phirehose/0.2.gitmaster +https://github.com/fennb/phirehose';
  protected $filterCheckMin = 5;
  protected $filterUpdMin   = 120;
  protected $tcpBackoff      = 1;
  protected $tcpBackoffMax  = 16;
  protected $httpBackoff  = 10;
  protected $httpBackoffMax  = 240;
  
  /**
   * Create a new Phirehose object attached to the appropriate twitter stream method.
   * Methods are: METHOD_FIREHOSE, METHOD_RETWEET, METHOD_SAMPLE, METHOD_FILTER, METHOD_LINKS
   * Formats are: FORMAT_JSON, FORMAT_XML
   * @see Phirehose::METHOD_SAMPLE
   * @see Phirehose::FORMAT_JSON
   *
   * @param string $username Any twitter username
   * @param string $password Any twitter password
   * @param string $method
   * @param string $format
   */
  public function __construct($username, $password, $method = Phirehose::METHOD_SAMPLE, $format = self::FORMAT_JSON, $lang = FALSE)
  {
    $this->username = $username;
    $this->password = $password;
    $this->method = $method;
    $this->format = $format;
    $this->lang = $lang;
  }
  
  /**
   * Returns public statuses from or in reply to a set of users. Mentions ("Hello @user!") and implicit replies
   * ("@user Hello!" created without pressing the reply button) are not matched. It is up to you to find the integer
   * IDs of each twitter user.
   * Applies to: METHOD_FILTER
   *
   * @param array $userIds Array of Twitter integer userIDs
   */
  public function setFollow($userIds)
  {
    $userIds = ($userIds === NULL) ? array() : $userIds;
    sort($userIds); // Non-optimal but necessary
    if ($this->followIds != $userIds) {
      $this->filterChanged = TRUE;
    }
    $this->followIds = $userIds;
  }
  
  /**
   * Returns an array of followed Twitter userIds (integers)
   *
   * @return array
   */
  public function getFollow()
  {
    return $this->followIds;
  }
  
  /**
   * Specifies keywords to track. Track keywords are case-insensitive logical ORs. Terms are exact-matched, ignoring
   * punctuation. Phrases, keywords with spaces, are not supported. Queries are subject to Track Limitations.
   * Applies to: METHOD_FILTER
   *
   * See: http://apiwiki.twitter.com/Streaming-API-Documentation#TrackLimiting
   *
   * @param array $trackWords
   */
  public function setTrack(array $trackWords)
  {
    $trackWords = ($trackWords === NULL) ? array() : $trackWords;
    sort($trackWords); // Non-optimal, but necessary
    if ($this->trackWords != $trackWords) {
      $this->filterChanged = TRUE;
    }
    $this->trackWords = $trackWords;
  }
  
  /**
   * Returns an array of keywords being tracked
   *
   * @return array
   */
  public function getTrack()
  {
    return $this->trackWords;
  }
  
  /**
   * Specifies a set of bounding boxes to track as an array of 4 element lon/lat pairs denoting <south-west point>,
   * <north-east point>. Only tweets that are both created using the Geotagging API and are placed from within a tracked
   * bounding box will be included in the stream. The user's location field is not used to filter tweets. Bounding boxes
   * are logical ORs and must be less than or equal to 1 degree per side. A locations parameter may be combined with
   * track parameters, but note that all terms are logically ORd.
   *
   * NOTE: The argument order is Longitude/Latitude (to match the Twitter API and GeoJSON specifications).
   *
   * Applies to: METHOD_FILTER
   *
   * See: http://apiwiki.twitter.com/Streaming-API-Documentation#locations
   *
   * Eg:
   *  setLocations(array(
   *      array(-122.75, 36.8, -121.75, 37.8), // San Francisco
   *      array(-74, 40, -73, 41),             // New York
   *  ));
   *
   * @param array $boundingBoxes
   */
  public function setLocations($boundingBoxes)
  {
    $boundingBoxes = ($boundingBoxes === NULL) ? array() : $boundingBoxes;
    sort($boundingBoxes); // Non-optimal, but necessary
    // Flatten to single dimensional array
    $locationBoxes = array();
    foreach ($boundingBoxes as $boundingBox) {
      // Sanity check
      if (count($boundingBox) != 4) {
        // Invalid - Not much we can do here but log error
        $this->log('Invalid location bounding box: [' . implode(', ', $boundingBox) . ']','error');
        return FALSE;
      }
      // Append this lat/lon pairs to flattened array
      $locationBoxes = array_merge($locationBoxes, $boundingBox);
    }
    // If it's changed, make note
    if ($this->locationBoxes != $locationBoxes) {
      $this->filterChanged = TRUE;
    }
    // Set flattened value
    $this->locationBoxes = $locationBoxes;
  }
  
  /**
   * Returns an array of 4 element arrays that denote the monitored location bounding boxes for tweets using the
   * Geotagging API.
   *
   * @see setLocations()
   * @return array
   */
  public function getLocations() {
    if ($this->locationBoxes == NULL) {
      return NULL;
    }
    $locationBoxes = $this->locationBoxes; // Copy array
    $ret = array();
    while (count($locationBoxes) >= 4) {
      $ret[] = array_splice($locationBoxes, 0, 4); // Append to ret array in blocks of 4
    }
    return $ret;
  }
  
  /**
   * Convenience method that sets location bounding boxes by an array of lon/lat/radius sets, rather than manually
   * specified bounding boxes. Each array element should contain 3 element subarray containing a latitude, longitude and
   * radius. Radius is specified in kilometers and is approximate (as boxes are square).
   *
   * NOTE: The argument order is Longitude/Latitude (to match the Twitter API and GeoJSON specifications).
   *
   * Eg:
   *  setLocationsByCircle(array(
   *      array(144.9631, -37.8142, 30), // Melbourne, 3km radius
   *      array(-0.1262, 51.5001, 25),   // London 10km radius
   *  ));
   *
   *
   * @see setLocations()
   * @param array
   */
  public function setLocationsByCircle($locations) {
    $boundingBoxes = array();
    foreach ($locations as $locTriplet) {
      // Sanity check
      if (count($locTriplet) != 3) {
        // Invalid - Not much we can do here but log error
        $this->log('Invalid location triplet for ' . __METHOD__ . ': [' . implode(', ', $locTriplet) . ']','error');
        return FALSE;
      }
      list($lon, $lat, $radius) = $locTriplet;

      // Calc bounding boxes
      $maxLat = round($lat + rad2deg($radius / self::EARTH_RADIUS_KM), 2);
      $minLat = round($lat - rad2deg($radius / self::EARTH_RADIUS_KM), 2);
      // Compensate for degrees longitude getting smaller with increasing latitude
      $maxLon = round($lon + rad2deg($radius / self::EARTH_RADIUS_KM / cos(deg2rad($lat))), 2);
      $minLon = round($lon - rad2deg($radius / self::EARTH_RADIUS_KM / cos(deg2rad($lat))), 2);
      // Add to bounding box array
      $boundingBoxes[] = array($minLon, $minLat, $maxLon, $maxLat);
      // Debugging is handy
      $this->log('Resolved location circle [' . $lon . ', ' . $lat . ', r: ' . $radius . '] -> bbox: [' . $minLon .
        ', ' . $minLat . ', ' . $maxLon . ', ' . $maxLat . ']');
    }
    // Set by bounding boxes
    $this->setLocations($boundingBoxes);
  }
  
  /**
   * Sets the number of previous statuses to stream before transitioning to the live stream. Applies only to firehose
   * and filter + track methods. This is generally used internally and should not be needed by client applications.
   * Applies to: METHOD_FILTER, METHOD_FIREHOSE, METHOD_LINKS
   *
   * @param integer $count
   */
  public function setCount($count)
  {
    $this->count = $count;
  }

  /**
   * Restricts tweets to the given language, given by an ISO 639-1 code (http://en.wikipedia.org/wiki/List_of_ISO_639-1_codes).
   *
   * @param string $lang
   */
  public function setLang($lang)
  {
    $this->lang = $lang;
  }

  /**
   * Returns the ISO 639-1 code formatted language string of the current setting. (http://en.wikipedia.org/wiki/List_of_ISO_639-1_codes).
   *
   * @param string $lang
   */
  public function getLang()
  {
    return $this->lang;
  }
  
  /**
   * Connects to the stream API and consumes the stream. Each status update in the stream will cause a call to the
   * handleStatus() method.
   *
   * @see handleStatus()
   * @param boolean $reconnect Reconnects as per recommended
   * @throws ErrorException
   */
  public function consume($reconnect = TRUE)
  {
    // Persist connection?
    $this->reconnect = $reconnect;
    
    // Loop indefinitely based on reconnect
    do {
      
      // (Re)connect
      $this->reconnect();
    
      // Init state
      $lastAverage = $lastFilterCheck = $lastFilterUpd = $lastStreamActivity = time();
      $fdw = $fde = NULL; // Placeholder write/error file descriptors for stream_select
      
      // We use a blocking-select with timeout, to allow us to continue processing on idle streams
      while ($this->conn !== NULL && !feof($this->conn) && ($numChanged = stream_select($this->fdrPool, $fdw, $fde, $this->readTimeout)) !== FALSE) {
        /* Unfortunately, we need to do a safety check for dead twitter streams - This seems to be able to happen where
         * you end up with a valid connection, but NO tweets coming along the wire (or keep alives). The below guards
         * against this.
         */
        if ((time() - $lastStreamActivity) > $this->idleReconnectTimeout) {
          $this->log('Idle timeout: No stream activity for > ' . $this->idleReconnectTimeout . ' seconds. ' . 
           ' Reconnecting.','info');
          $this->reconnect();
          $lastStreamActivity = time();
          continue;
        }
        // Process stream/buffer
        $this->fdrPool = array($this->conn); // Must reassign for stream_select()
        $this->buff .= fread($this->conn, 6); // Small non-blocking to get delimiter text
        if (($eol = strpos($this->buff, "\r\n")) === FALSE) {
          continue; // We need a newline
        }
        // Track maximum idle period
        $this->idlePeriod = (time() - $lastStreamActivity);
        $this->maxIdlePeriod = ($this->idlePeriod > $this->maxIdlePeriod) ? $this->idlePeriod : $this->maxIdlePeriod;
        // We got a newline, this is stream activity
        $lastStreamActivity = time();
        // Read status length delimiter
        $delimiter = substr($this->buff, 0, $eol);
        $this->buff = substr($this->buff, $eol + 2); // consume off buffer, + 2 = "\r\n"
        $statusLength = intval($delimiter, $this->status_length_base);
        if ($statusLength > 0) {
          // Read status bytes and enqueue
          $bytesLeft = $statusLength - strlen($this->buff);
          while ( $bytesLeft > 0 
                  && $this->conn !== NULL 
                  && !feof($this->conn) 
                  && ($numChanged = stream_select($this->fdrPool, $fdw, $fde, 0, 20000)) !== FALSE 
                  && (time() - $lastStreamActivity) <= $this->idleReconnectTimeout) {  
            $this->fdrPool = array($this->conn); // Reassign
            $this->buff .= fread($this->conn, $bytesLeft); // Read until all bytes are read into buffer
            $bytesLeft = ($statusLength - strlen($this->buff));
          }
          // Accrue/enqueue and track time spent enqueing
          $enqueueStart = microtime(TRUE);
          $this->enqueueStatus($this->buff);
          $this->enqueueSpent += (microtime(TRUE) - $enqueueStart);
          $this->statusCount++;
        } else {
          // Timeout/no data after readTimeout seconds
          
        }
        // Calc counter averages
        $this->avgElapsed = time() - $lastAverage;
        if ($this->avgElapsed >= $this->avgPeriod) {
          $this->statusRate = round($this->statusCount / $this->avgElapsed, 0);          // Calc tweets-per-second
          // Calc time spent per enqueue in ms
          $this->enqueueTimeMS = ($this->statusCount > 0) ? round($this->enqueueSpent / $this->statusCount * 1000, 2) : 0;
          // Calc time spent total in filter predicate checking
          $this->filterCheckTimeMS = ($this->filterCheckCount > 0) ? round($this->filterCheckSpent / $this->filterCheckCount * 1000, 2) : 0;

          $this->heartbeat();
          $this->statusUpdate();
          $lastAverage = time();
        }
        // Check if we're ready to check filter predicates
        if ($this->method == self::METHOD_FILTER && (time() - $lastFilterCheck) >= $this->filterCheckMin) {
          $this->filterCheckCount++;
          $lastFilterCheck = time();
          $filterCheckStart = microtime(TRUE);
          $this->checkFilterPredicates(); // This should be implemented in subclass if required
          $this->filterCheckSpent +=  (microtime(TRUE) - $filterCheckStart);
        }
        // Check if filter is ready + allowed to be updated (reconnect)
        if ($this->filterChanged == TRUE && (time() - $lastFilterUpd) >= $this->filterUpdMin) {
          $this->log('Reconnecting due to changed filter predicates.','info');
          $this->reconnect();
          $lastFilterUpd = time();
        }
        
      } // End while-stream-activity

      if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
      }

      // Some sort of socket error has occured
      $this->lastErrorNo = is_resource($this->conn) ? @socket_last_error($this->conn) : NULL;
      $this->lastErrorMsg = ($this->lastErrorNo > 0) ? @socket_strerror($this->lastErrorNo) : 'Socket disconnected';
      $this->log('Phirehose connection error occured: ' . $this->lastErrorMsg,'error');

      // Reconnect
    } while ($this->reconnect);

    // Exit
    $this->log('Exiting.');
    
  }


  /**
   * Called every $this->avgPeriod (default=60) seconds, and this default implementation
   * calculates some rates, logs them, and resets the counters.
   */
  protected function statusUpdate()
  {
      $this->log('Consume rate: ' . $this->statusRate . ' status/sec (' . $this->statusCount . ' total), avg ' . 
        'enqueueStatus(): ' . $this->enqueueTimeMS . 'ms, avg checkFilterPredicates(): ' . $this->filterCheckTimeMS . 'ms (' . 
        $this->filterCheckCount . ' total) over ' . $this->avgElapsed . ' seconds, max stream idle period: ' . 
          $this->maxIdlePeriod . ' seconds.');
      // Reset
        $this->statusCount = $this->filterCheckCount = $this->enqueueSpent = 0;
        $this->filterCheckSpent = $this->idlePeriod = $this->maxIdlePeriod = 0;
  }
  
  /**
   * Returns the last error message (TCP or HTTP) that occured with the streaming API or client. State is cleared upon
   * successful reconnect
   * @return string
   */
  public function getLastErrorMsg()
  {
    return $this->lastErrorMsg;
  }
  
  /**
   * Returns the last error number that occured with the streaming API or client. Numbers correspond to either the
   * fsockopen() error states (in the case of TCP errors) or HTTP error codes from Twitter (in the case of HTTP errors).
   *
   * State is cleared upon successful reconnect.
   *
   * @return string
   */
  public function getLastErrorNo()
  {
    return $this->lastErrorNo;
  }
  
  
  /**
   * Connects to the stream URL using the configured method.
   * @throws ErrorException
   */
  protected function connect()
  {

    // Init state
    $connectFailures = 0;
    $tcpRetry = $this->tcpBackoff / 2;
    $httpRetry = $this->httpBackoff / 2;

    // Keep trying until connected (or max connect failures exceeded)
    do {

      // Check filter predicates for every connect (for filter method)
      if ($this->method == self::METHOD_FILTER) {
        $this->checkFilterPredicates();
      }
      
      // Construct URL/HTTP bits
      $url = self::URL_BASE . $this->method . '.' . $this->format;
      $urlParts = parse_url($url);
      
      // Setup params appropriately
      $requestParams = array('delimited' => 'length');

      // Setup the language of the stream
      if($this->lang) {
        $requestParams['language'] = $this->lang;
      }
      
      // Filter takes additional parameters
      if ($this->method == self::METHOD_FILTER && count($this->trackWords) > 0) {
        $requestParams['track'] = implode(',', $this->trackWords);
      }
      if ($this->method == self::METHOD_FILTER && count($this->followIds) > 0) {
        $requestParams['follow'] = implode(',', $this->followIds);
      }
      if ($this->method == self::METHOD_FILTER && count($this->locationBoxes) > 0) {
        $requestParams['locations'] = implode(',', $this->locationBoxes);
      }
      if ($this->count <> 0) {
        $requestParams['count'] = $this->count;    
      }
  
      // Debugging is useful
      $this->log('Connecting to twitter stream: ' . $url . ' with params: ' . str_replace("\n", '',
        var_export($requestParams, TRUE)));
      
      /**
       * Open socket connection to make POST request. It'd be nice to use stream_context_create with the native
       * HTTP transport but it hides/abstracts too many required bits (like HTTP error responses).
       */
      $errNo = $errStr = NULL;
      $scheme = ($urlParts['scheme'] == 'https') ? 'ssl://' : 'tcp://';
      $port = ($urlParts['scheme'] == 'https') ? 443 : 80;
      
      /**
       * We must perform manual host resolution here as Twitter's IP regularly rotates (ie: DNS TTL of 60 seconds) and
       * PHP appears to cache it the result if in a long running process (as per Phirehose).
       */
      $streamIPs = gethostbynamel($urlParts['host']);
      if(empty($streamIPs)) {
        throw new PhirehoseNetworkException("Unable to resolve hostname: '" . $urlParts['host'] . '"');
      }
      
      // Choose one randomly (if more than one)
      $this->log('Resolved host ' . $urlParts['host'] . ' to ' . implode(', ', $streamIPs));
      $streamIP = $streamIPs[rand(0, (count($streamIPs) - 1))];
      $this->log("Connecting to {$scheme}{$streamIP}, port={$port}, connectTimeout={$this->connectTimeout}");
      
      @$this->conn = fsockopen($scheme . $streamIP, $port, $errNo, $errStr, $this->connectTimeout);
  
      // No go - handle errors/backoff
      if (!$this->conn || !is_resource($this->conn)) {
        $this->lastErrorMsg = $errStr;
        $this->lastErrorNo = $errNo;
        $connectFailures++;
        if ($connectFailures > $this->connectFailuresMax) {
          $msg = 'TCP failure limit exceeded with ' . $connectFailures . ' failures. Last error: ' . $errStr;
          $this->log($msg,'error');
          throw new PhirehoseConnectLimitExceeded($msg, $errNo); // Throw an exception for other code to handle
        }
        // Increase retry/backoff up to max
        $tcpRetry = ($tcpRetry < $this->tcpBackoffMax) ? $tcpRetry * 2 : $this->tcpBackoffMax;
        $this->log('TCP failure ' . $connectFailures . ' of ' . $this->connectFailuresMax . ' connecting to stream: ' .
          $errStr . ' (' . $errNo . '). Sleeping for ' . $tcpRetry . ' seconds.','info');
        sleep($tcpRetry);
        continue;
      }
      
      // TCP connect OK, clear last error (if present)
      $this->log('Connection established to ' . $streamIP);
      $this->lastErrorMsg = NULL;
      $this->lastErrorNo = NULL;
      
      // If we have a socket connection, we can attempt a HTTP request - Ensure blocking read for the moment
      stream_set_blocking($this->conn, 1);
  
      // Encode request data
      $postData = http_build_query($requestParams, NULL, '&');
      $postData = str_replace('+','%20',$postData); //Change it from RFC1738 to RFC3986 (see
            //enc_type parameter in http://php.net/http_build_query and note that enc_type is
            //not available as of php 5.3)
      $authCredentials = $this->getAuthorizationHeader();
      
      // Do it
      fwrite($this->conn, "POST " . $urlParts['path'] . " HTTP/1.0\r\n");
      fwrite($this->conn, "Host: " . $urlParts['host'] . ':' . $port . "\r\n");
      fwrite($this->conn, "Content-type: application/x-www-form-urlencoded\r\n");
      fwrite($this->conn, "Content-length: " . strlen($postData) . "\r\n");
      fwrite($this->conn, "Accept: */*\r\n");
      fwrite($this->conn, 'Authorization: ' . $authCredentials . "\r\n");
      fwrite($this->conn, 'User-Agent: ' . $this->userAgent . "\r\n");
      fwrite($this->conn, "\r\n");
      fwrite($this->conn, $postData . "\r\n");
      fwrite($this->conn, "\r\n");
      
      $this->log("POST " . $urlParts['path'] . " HTTP/1.0\r\n");
      $this->log("Host: " . $urlParts['host'] . ':' . $port . "\r\n");
      $this->log("Content-type: application/x-www-form-urlencoded\r\n");
      $this->log("Content-length: " . strlen($postData) . "\r\n");
      $this->log("Accept: */*\r\n");
      $this->log('Authorization: ' . $authCredentials . "\r\n");
      $this->log('User-Agent: ' . $this->userAgent . "\r\n");
      $this->log("\r\n");
      $this->log($postData . "\r\n");
      $this->log("\r\n");
      
      // First line is response
      list($httpVer, $httpCode, $httpMessage) = preg_split('/\s+/', trim(fgets($this->conn, 1024)), 3);
      
      // Response buffers
      $respHeaders = $respBody = '';

      // Consume each header response line until we get to body
      while ($hLine = trim(fgets($this->conn, 4096))) {
        $respHeaders .= $hLine;
      }
      
      // If we got a non-200 response, we need to backoff and retry
      if ($httpCode != 200) {
        $connectFailures++;
        
        // Twitter will disconnect on error, but we want to consume the rest of the response body (which is useful)
        while ($bLine = trim(fgets($this->conn, 4096))) {
          $respBody .= $bLine;
        }
        
        // Construct error
        $errStr = 'HTTP ERROR ' . $httpCode . ': ' . $httpMessage . ' (' . $respBody . ')';
        
        // Set last error state
        $this->lastErrorMsg = $errStr;
        $this->lastErrorNo = $httpCode;
        
        // Have we exceeded maximum failures?
        if ($connectFailures > $this->connectFailuresMax) {
          $msg = 'Connection failure limit exceeded with ' . $connectFailures . ' failures. Last error: ' . $errStr;
          $this->log($msg,'error');
          throw new PhirehoseConnectLimitExceeded($msg, $httpCode); // We eventually throw an exception for other code to handle          
        }
        // Increase retry/backoff up to max
        $httpRetry = ($httpRetry < $this->httpBackoffMax) ? $httpRetry * 2 : $this->httpBackoffMax;
        $this->log('HTTP failure ' . $connectFailures . ' of ' . $this->connectFailuresMax . ' connecting to stream: ' . 
          $errStr . '. Sleeping for ' . $httpRetry . ' seconds.','info');
        sleep($httpRetry);
        continue;
        
      } // End if not http 200
      
      // Loop until connected OK
    } while (!is_resource($this->conn) || $httpCode != 200);
    
    // Connected OK, reset connect failures
    $connectFailures = 0;
    $this->lastErrorMsg = NULL;
    $this->lastErrorNo = NULL;
    
    // Switch to non-blocking to consume the stream (important)
    stream_set_blocking($this->conn, 0);
    
    // Connect always causes the filterChanged status to be cleared
    $this->filterChanged = FALSE;
    
    // Flush stream buffer & (re)assign fdrPool (for reconnect)
    $this->fdrPool = array($this->conn);
    $this->buff = '';
    
  }

	protected function getAuthorizationHeader()
	{
		$authCredentials = base64_encode($this->username . ':' . $this->password);
		return "Basic: ".$authCredentials;
	}
  
  /**
   * Method called as frequently as practical (every 5+ seconds) that is responsible for checking if filter predicates
   * (ie: track words or follow IDs) have changed. If they have, they should be set using the setTrack() and setFollow()
   * methods respectively within the overridden implementation.
   *
   * Note that even if predicates are changed every 5 seconds, an actual reconnect will not happen more frequently than
   * every 2 minutes (as per Twitter Streaming API documentation).
   *
   * Note also that this method is called upon every connect attempt, so if your predicates are causing connection
   * errors, they should be checked here and corrected.
   *
   * This should be implemented/overridden in any subclass implementing the FILTER method.
   *
   * @see setTrack()
   * @see setFollow()
   * @see Phirehose::METHOD_FILTER
   */
  protected function checkFilterPredicates()
  {
    // Override in subclass
  }
  
  /**
   * Basic log function that outputs logging to the standard error_log() handler. This should generally be overridden
   * to suit the application environment.
   *
   * @see error_log()
   * @param string $messages
   * @param String $level 'error', 'info', 'notice'. Defaults to 'notice', so you should set this
   *     parameter on the more important error messages.
   *     'info' is used for problems that the class should be able to recover from automatically.
   *     'error' is for exceptional conditions that may need human intervention. (For instance, emailing
   *          them to a system administrator may make sense.)
   */
  protected function log($message,$level='notice')
  {
    @error_log('Phirehose: ' . $message, 0);
  }

  /**
   * Performs forcible disconnect from stream (if connected) and cleanup.
   */
  protected function disconnect()
  {
    if (is_resource($this->conn)) {
      $this->log('Closing Phirehose connection.');
      fclose($this->conn);
    }
    $this->conn = NULL;
    $this->reconnect = FALSE;
  }
  
  /**
   * Reconnects as quickly as possible. Should be called whenever a reconnect is required rather that connect/disconnect
   * to preserve streams reconnect state
   */
  private function reconnect()
  {
    $reconnect = $this->reconnect;
    $this->disconnect(); // Implicitly sets reconnect to FALSE
    $this->reconnect = $reconnect; // Restore state to prev
    $this->connect();
  }
  
  /**
   * This is the one and only method that must be implemented additionally. As per the streaming API documentation,
   * statuses should NOT be processed within the same process that is performing collection
   *
   * @param string $status
   */
  abstract public function enqueueStatus($status);

  /**
   * Reports a periodic heartbeat. Keep execution time minimal.
   *
   * @return NULL
   */
  public function heartbeat() {}

} // End of class

class PhirehoseException extends Exception {}
class PhirehoseNetworkException extends PhirehoseException {}
class PhirehoseConnectLimitExceeded extends PhirehoseException {}
