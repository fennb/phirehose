<?php
require_once '../lib/Phirehose.php';
require_once '../lib/OauthPhirehose.php';
require_once '../lib/SimilarityAlg.php';
require_once '../lib/SimilarityAlg2.php';
require_once 'twitter-auth-config.php';

/**
 * Example of using Phirehose to display a live filtered stream using track words
 */
class FilterTrackConsumer extends OauthPhirehose
{
    public $list = [];

    public function setSimilarityClass(SimilarityAlg2 $class)
    {
        $this->similarityIndex = $class;
    }

    /**
     * Enqueue each status
     *
     * @param string $status
     */
    public function enqueueStatus($status)
    {
        /*
       * In this simple example, we will just display to STDOUT rather than enqueue.
       * NOTE: You should NOT be processing tweets at this point in a real application, instead they should be being
       *       enqueued and processed asyncronously from the collection process.
       */
        $data = json_decode($status, true);
        if (is_array($data) && isset($data['user']['screen_name']) && substr($data['text'], 0, 4) != 'RT @') {
            if ($this->testSim($data['text'])) {
                print $data['user']['screen_name'] . ': ' . urldecode($data['text']) . "\n";
            }
        }
    }

    public function testSim($text)
    {
        foreach ($this->list as &$line) {
            $index = $this->similarityIndex->compareStrings($text, $line);
            if ($index >= 0.75) {
                return false;
            }
        }

        if (sizeof($this->list) > 100) {
            array_shift($this->list);
        }

        $this->list[] = $text;

        return true;
    }
}

$similarityIndex = new SimilarityAlg2();
//$index = $similarityIndex->compareStrings(
//    'Berlin market attack: Police searching for Tunisian man after finding ID in truck – ',
//    'Berlin attack: Police hunt Tunisian suspect after finding ID papers in truck - '
//);
//var_dump($index);
//exit;

// Start streaming
$sc = new FilterTrackConsumer(OAUTH_TOKEN, OAUTH_SECRET, Phirehose::METHOD_FILTER);
$sc->setSimilarityClass($similarityIndex);
$sc->setTrack(['berlin', 'attack'], FilterTrackConsumer::TRACK_OP_AND);
$sc->consume();
