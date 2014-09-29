# Phirehose #
A PHP interface to the Twitter Streaming API (firehose, etc). This library makes it easy to connect to and consume the Twitter stream via the Streaming API.

See:
  * https://github.com/fennb/phirehose/wiki/Introduction and 
  * https://dev.twitter.com/streaming/overview

## Goals ##
  * Provide a simple interface to the Twitter Streaming API for PHP applications
  * Comply to Streaming API recommendations for error handling, reconnection, etc
  * Encourage well-behaved streaming API clients
  * Operate independently of PHP extensions (ie: shared memory, PCNTL, etc)

## What this library does do ##
  * Handles connection/authentication to the twitter streaming API
  * Consumes the stream handing off each status to be enqueued by a method of your choice
  * Handles graceful reconnection/back-off on connection and API errors
  * Monitors/reports performance metrics and errors

## What this library doesn't do ##
  * Decode/process tweets
  * Provide any sort of queueing mechanism for asynchronous processing (though some examples are included)
  * Provide any sort of inter-process communication
  * Provide any non-streaming API functionality (ie: user profile info, search, etc)

## How To Use ##

See the example subdirectory for example usage. In each example file you will need to insert your own oauth token/secret, and the key/secret for the Twitter app you have created.

  * filter-oauth.php shows how to follow certain keywords.
  * sample.php shows how to get a small random sample of all public statuses.
  * userstream-alternative.php shows how to get user streams. (All activity for one user.)
  * sitestream.php shows to how to get site streams. (All activity for multiple users.)

Please see the wiki for [documentation](https://github.com/fennb/phirehose/wiki/Introduction).

If you have any additional questions, head over to the Phirehose Users group [http://groups.google.com/group/phirehose-users]

It's recommended that you join (or at least regularly check) this group if you're actively using Phirehose so I can let you know when I release new versions.

Additionally, if you'd like to contact me directly, I'm [@fennb](http://twitter.com/fennb) on twitter.
