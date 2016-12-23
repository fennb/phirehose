<?php
require_once '../lib/Phirehose.php';
require_once '../lib/OauthPhirehose.php';
require_once '../lib/SimilarityAlg.php';
require_once '../lib/SimilarityAlg2.php';
require_once 'twitter-auth-config.php';
require '../vendor/autoload.php';

/**
 * Example of using Phirehose to display a live filtered stream using track words
 */
class FilterTrackConsumer extends OauthPhirehose
{
    public $list = [];

    public $pushInterval = 1;

    private $similarityThreshold = 0.75;

    private $db;

    private $queueSize = 100;

    private $queueStartTime;

    private $resetQueueSeconds = 800;

    public function setSimilarityClass(SimilarityAlg2 $class)
    {
        $this->similarityIndex = $class;
    }

    public function fopen()
    {
        $this->fh = fopen('./data', 'a');
    }

    public function setFirebaseDb($db)
    {
        $this->db = $db;
    }

    /**
     * Enqueue each status
     *
     * @param string $status
     */
    public function enqueueStatus($status)
    {
        /*
        * You should NOT be processing tweets at this point in a real application, instead they should be being
        * enqueued and processed asyncronously from the collection process.
        */
        $data = json_decode($status, true);
        if (is_array($data) &&
            isset($data['user']['screen_name']) &&
            $data['lang'] == 'en' &&
            substr(
                $data['text'],
                0,
                4
            ) != 'RT @'
        ) {
            $text = $this->cleanupText($data['text']);
            if (strlen($text) > 30 && $this->testSim($text)) {
                $this->push($data['user']['screen_name'], $text);
//                print $data['user']['screen_name'] . ': ' . $text . "\n";
//                fwrite($this->fh, str_replace("\n", ',', $data['text']) . PHP_EOL);
            }
        }
    }

    /**
     * @param $text
     *
     * @return mixed
     */
    private function &cleanupText(&$text)
    {
        $text = str_replace("\n", ',', $text);
        $text = trim($text);

        return $text;
    }

    private function testSim($text)
    {
        $size = sizeof($this->list);

        $text = preg_replace('~(?:https?:\/\/t.co\/[a-zA-Z0-9]+\s?)~', '', urldecode($text));
        $text = preg_replace('~(?:@[a-zA-Z_]+\s?:?)|(?:#[a-zA-Z\s]+)~', '', $text);

        // do not print, store for checking
        if ($size < $this->queueSize) {

            print "::QSize:$size::$text\n";

            // add only unique messages, continue otherwise
            for ($i = $size; $i > 0; --$i) {
                if (!isset($this->list[$i])) {
                    continue;
                }
                $index = $this->similarityIndex->compareStrings($text, $this->list[$i]);
                if ($index >= $this->similarityThreshold) {
                    print "::In queue - skip:$i::$text\n";

                    return true;
                }
            }

            $this->list[] = $text;

            return true;
        }

        $this->resetQueue();

        // start filtering something sensible
        for ($i = $size; $i >= 0; --$i) {
            if (!isset($this->list[$i])) {
                continue;
            }
            $index = $this->similarityIndex->compareStrings($text, $this->list[$i]);
            // we want to keep the most common messages, as ranting is quite unique this will keep only valid sources
            if ($index >= $this->similarityThreshold) {
                // move to the last position
                $this->list[$i] = $this->list[$size];
                $this->list[$size] = $text;

                print "::Queue:$i::$text\n";

                return true;
            }
        }

        print ':::::DROP:::::::' . $text . "\n";

        return false;
    }

    protected function push($user, $data)
    {
        $doc = implode('-', $this->getTrack());
        $this->db->getReference($doc)
            ->push(
                [
                    'username' => $user,
                    'tweet' => $data,
                ]
            );
    }

    public function resetQueue()
    {
        // shuffle and reset 50% every 5 min
        if ($this->getQueueStartTime() < time() - $this->resetQueueSeconds) {
            $this->initStart();
            shuffle($this->list);
            $this->list = array_slice($this->list, floor(sizeof($this->list) / 2));
            print ':::Resetting queue...' . PHP_EOL;
        }
    }

    public function getQueueStartTime()
    {
        return $this->queueStartTime;
    }

    public function initStart()
    {
        $this->queueStartTime = time();
    }

    public function __destruct()
    {
        print 'GG&BB' . PHP_EOL;

        $this->push('Admin', 'Restarting........');
        $this->push('Admin', 'Restarting................');
        $this->push('Admin', 'Restarting......................');
        $this->push('Admin', 'Restarting..............................');
        $this->disconnect();
    }
}

$similarityIndex = new SimilarityAlg2();
//$index = $similarityIndex->compareStrings(
//    'Berlin market attack: Police searching for Tunisian man after finding ID in truck – ',
//    'Berlin attack: Police hunt Tunisian suspect after finding ID papers in truck - '
//);
//var_dump($index);
//exit;

//class test
//{
//    public $list = ['a', 'b', 'c', 'd'];
//
//    function tests()
//    {
//        shuffle($this->list);
//        $this->list = array_slice($this->list, floor(sizeof($this->list)/2));
//        var_dump($this->list);
//    }
//}
//
//(new test)->tests();

$firebase = Firebase::fromServiceAccount(__DIR__ . '/../news-test-01-8e00fcff2669.json');
$database = $firebase->getDatabase();

// Start streaming
$sc = new FilterTrackConsumer(OAUTH_TOKEN, OAUTH_SECRET, Phirehose::METHOD_FILTER);
$sc->setSimilarityClass($similarityIndex);
$sc->setFirebaseDb($database);
$sc->initStart();
$sc->fopen();
$sc->setTrack(['terror', 'attack'], FilterTrackConsumer::TRACK_OP_AND);
$sc->consume();
