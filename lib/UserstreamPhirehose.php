<?php
require_once('OauthPhirehose.php');

/**
* This class just exists to have a different default method
*   (i.e. more for backwards-compatibility than anything)
*/
abstract class UserstreamPhirehose extends OauthPhirehose {

  /**
  * This function just exists to send a different default for $method
  *
  * @param String $username,$password The oauth token and oauth secret.
  * @param String $method Set to self::METHOD_USER for user streams, and to
  *           self::METHOD_SITE for site streams.
  */
  public function __construct($username, $password, $method = self::METHOD_USER, $format = self::FORMAT_JSON, $lang = FALSE) {
    parent::__construct($username, $password, $method, $format, $lang);
  }

}
