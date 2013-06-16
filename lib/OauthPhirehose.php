<?php

abstract class OauthPhirehose extends Phirehose
{

	protected $auth_method;

    /**
    * The Twitter consumer key. Get it from the application's page on Twitter.
    * If not set then the global define TWITTER_CONSUMER_KEY is used instead.
    */
    public $consumerKey=null;

    /**
    * The Twitter consumer secret. Get it from the application's page on Twitter.
    * If not set then the global define TWITTER_CONSUMER_SECRET is used instead.
    */
    public $consumerSecret=null;


    /**
    */
	protected function prepareParameters($method = null, $url = null,
		array $params)
	{
		if (empty($method) || empty($url))
			return false;

		$oauth['oauth_consumer_key'] = $this->consumerKey?$this->consumerKey:TWITTER_CONSUMER_KEY;
		$oauth['oauth_nonce'] = md5(uniqid(rand(), true));
		$oauth['oauth_signature_method'] = 'HMAC-SHA1';
		$oauth['oauth_timestamp'] = time();
		$oauth['oauth_version'] = '1.0';
		$oauth['oauth_token'] = $this->username;
		if (isset($params['oauth_verifier']))
		{
			$oauth['oauth_verifier'] = $params['oauth_verifier'];
			unset($params['oauth_verifier']);
		}
		// encode all oauth values
		foreach ($oauth as $k => $v)
			$oauth[$k] = $this->encode_rfc3986($v);

		// encode all non '@' params
		// keep sigParams for signature generation (exclude '@' params)
		// rename '@key' to 'key'
		$sigParams = array();
		$hasFile = false;
		if (is_array($params))
		{
			foreach ($params as $k => $v)
			{
				if (strncmp('@', $k, 1) !== 0)
				{
					$sigParams[$k] = $this->encode_rfc3986($v);
					$params[$k] = $this->encode_rfc3986($v);
				}
				else
				{
					$params[substr($k, 1)] = $v;
					unset($params[$k]);
					$hasFile = true;
				}
			}

			if ($hasFile === true)
				$sigParams = array();
		}

		$sigParams = array_merge($oauth, (array) $sigParams);

		// sorting
		ksort($sigParams);

		// signing
		$oauth['oauth_signature'] = $this->encode_rfc3986($this->generateSignature($method, $url, $sigParams));
		return array('request' => $params, 'oauth' => $oauth);
	}

	protected function encode_rfc3986($string)
	{
		return str_replace('+', ' ', str_replace('%7E', '~', rawurlencode(($string))));
	}

	protected function generateSignature($method = null, $url = null,
		$params = null)
	{
		if (empty($method) || empty($url))
			return false;

		// concatenating and encode
		$concat = '';
		foreach ((array) $params as $key => $value)
			$concat .= "{$key}={$value}&";
		$concat = substr($concat, 0, -1);
		$concatenatedParams = $this->encode_rfc3986($concat);

		// normalize url
		$urlParts = parse_url($url);
		$scheme = strtolower($urlParts['scheme']);
		$host = strtolower($urlParts['host']);
		$port = isset($urlParts['port']) ? intval($urlParts['port']) : 0;
		$retval = strtolower($scheme) . '://' . strtolower($host);
		if (!empty($port) && (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443)))
			$retval .= ":{$port}";

		$retval .= $urlParts['path'];
		if (!empty($urlParts['query']))
			$retval .= "?{$urlParts['query']}";

		$normalizedUrl = $this->encode_rfc3986($retval);
		$method = $this->encode_rfc3986($method); // don't need this but why not?

		$signatureBaseString = "{$method}&{$normalizedUrl}&{$concatenatedParams}";

		# sign the signature string
		$key = $this->encode_rfc3986($this->consumerSecret?$this->consumerSecret:TWITTER_CONSUMER_SECRET) . '&' . $this->encode_rfc3986($this->password);
		return base64_encode(hash_hmac('sha1', $signatureBaseString, $key, true));
	}

	protected function getOAuthHeader($method, $url, $params = array())
	{
		$params = $this->prepareParameters($method, $url, $params);
		$oauthHeaders = $params['oauth'];

		$urlParts = parse_url($url);
		$oauth = 'OAuth realm="",';
		foreach ($oauthHeaders as $name => $value)
		{
			$oauth .= "{$name}=\"{$value}\",";
		}
		$oauth = substr($oauth, 0, -1);

		return $oauth;
	}

	protected function getAuthorizationHeader()
	{
		$url = self::URL_BASE . $this->method . '.' . $this->format;
		$urlParts = parse_url($url);

		// Setup params appropriately
		$requestParams = array('delimited' => 'length');

		// Setup the language of the stream
		if($this->lang) {
			$requestParams['language'] = $this->lang;
		}

		// Filter takes additional parameters
		if (count($this->trackWords) > 0)
		{
			$requestParams['track'] = implode(',', $this->trackWords);
		}
		if (count($this->followIds) > 0)
		{
			$requestParams['follow'] = implode(',', $this->followIds);
		}
		if (count($this->locationBoxes) > 0)
		{
			$requestParams['locations'] = implode(',', $this->locationBoxes);
		}
		if (count($this->count) <> 0) {
			$requestParams['count'] = $this->count;
		}

		return $this->getOAuthHeader('POST', $url, $requestParams);
	}
}
