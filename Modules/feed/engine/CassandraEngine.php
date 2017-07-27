<?php

class CassandraEngine
{
    protected $cluster;
    protected $session;
    protected $log;

    /**
     * Constructor.
     *
     * @api
    */
    public function __construct($settings)
    {
        $keyspace = isset($settings['keyspace']) ? $settings['keyspace'] : 'emoncms';

        $this->cluster = Cassandra::cluster()                 // connects to localhost by default
                         ->build();
        $this->session = $this->cluster->connect($keyspace);  // create session, optionally scoped to a keyspace

        $this->log = new EmonLogger(__FILE__);
    }


// #### \/ Below are required methods

    /**
     * Create feed
     *
     * @param integer $feedid The id of the feed to be created
     * @param array $options for the engine
    */
    public function create($feedid,$options)
    {
        $feedname = "feed_".trim($feedid)."";
        $this->execCQL("CREATE TABLE IF NOT EXISTS $feedname (feed_id int, day int, time bigint, data float, PRIMARY KEY ((feed_id,day), time)) WITH CLUSTERING ORDER BY (time ASC)");
        return true;
    }

    /**
     * Delete feed
     *
     * @param integer $feedid The id of the feed to be created
    */
    public function delete($feedid)
    {
        $this->execCQL("DROP TABLE feed_".$feedid);
    }

    /**
     * Gets engine metadata
     *
     * @param integer $feedid The id of the feed to be created
    */
    public function get_meta($feedid)
    {
        $meta = new stdClass();
        $meta->id = $feedid;
        $meta->start_time = 0;
        $meta->nlayers = 1;
        $meta->npoints = -1;
        $meta->interval = 1;
        return $meta;
    }

    /**
     * Returns engine occupied size in bytes
     *
     * @param integer $feedid The id of the feed to be created
    */
    public function get_feed_size($feedid)
    {
        $feedid = intval($feedid);
        $tablesize = 0; // FIXME
        return $tablesize;
    }

    /**
     * Adds a data point to the feed
     *
     * @param integer $feedid The id of the feed to add to
     * @param integer $time The unix timestamp of the data point, in seconds
     * @param float $value The value of the data point
     * @param arg $value optional padding mode argument
    */
    public function post($feedid,$time,$value,$arg=null)
    {
        $feedid = intval($feedid);
        $feedname = "feed_".trim($feedid)."";
        $day = $this->unixtoday($time);
        $this->execCQL("INSERT INTO $feedname(feed_id,day,time,data) VALUES($feedid,$day,$time,$value)");
    }

    /**
     * Updates a data point in the feed
     *
     * @param integer $feedid The id of the feed to add to
     * @param integer $time The unix timestamp of the data point, in seconds
     * @param float $value The value of the data point
    */
    public function update($feedid,$time,$value)
    {
        $feedid = (int) $feedid;
        $feedname = "feed_".trim($feedid)."";
        $day = $this->unixtoday($time);
        $this->execCQL("UPDATE $feedname SET data = $value WHERE feed_id = $feedid AND day = $day AND time = $time");
        return $value;
    }

    /**
     * Get array with last time and value from a feed
     *
     * @param integer $feedid The id of the feed
    */
    public function lastvalue($feedid)
    {
        $feedid = intval($feedid);
        $feedname = "feed_".trim($feedid)."";

        $result = $this->execCQL("SELECT max(day) AS max_day FROM $feedname WHERE feed_id=$feedid");
        if ($result && count($result)>0){
            $row=$result[0];
            $max_day=$row['max_day'];
            $result = $this->execCQL("SELECT time, data FROM $feedname WHERE feed_id=$feedid and day=$max_day ORDER BY time DESC LIMIT 1");
            if ($result && count($result)>0){
                $row=$result[0];
                if ($row['data'] !== null) $row['data'] = (float) $row['data'];
                return array('time'=>(int)$row['time'], 'value'=>$row['data']);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Return the data for the given timerange
     *
     * @param integer $feedid The id of the feed to fetch from
     * @param integer $start The unix timestamp in ms of the start of the data range
     * @param integer $end The unix timestamp in ms of the end of the data range
     * @param integer $interval The number os seconds for each data point to return (used by some engines)
     * @param integer $skipmissing Skip null values from returned data (used by some engines)
     * @param integer $limitinterval Limit datapoints returned to this value (used by some engines)
    */
    public function get_data($feedid,$start,$end,$interval,$skipmissing,$limitinterval)
    {
        $feedid = intval($feedid);
        $start = round($start/1000);
        $end = round($end/1000);
        $interval = intval($interval);
        $feedname = "feed_$feedid";
        $day_range = range($this->unixtoday($start), $this->unixtoday($end));
        $data = array();
        $result = $this->execCQL("SELECT time, data FROM $feedname WHERE feed_id=$feedid AND day IN (".implode($day_range,',').") AND time >= $start AND time <= $end");
        while($result) {
            foreach ($result as $row) {
                $dataValue = $row['data'];
                if ($dataValue!=NULL || $skipmissing===0) { // Remove this to show white space gaps in graph
                    $time = $row['time'] * 1000;
                    if ($dataValue !== null) $dataValue = (float) $dataValue ;
                    $data[] = array($time , $dataValue);
                }
            }
            $result = $result->nextPage();
        }
        return $data;
    }

    public function export($feedid,$start)
    {
        $this->log->info("export($feedid,$start)");
        // TODO implement
    }

    public function csv_export($feedid,$start,$end,$outinterval,$usertimezone)
    {
        $this->log->info("csv_export($feedid,$start,$end,$outinterval,$usertimezone)");
        // TODO implement
    }

// #### /\ Above are required methods

// #### \/ Below engine specific public methods

    public function delete_data_point($feedid,$time)
    {
        $feedid = intval($feedid);
        $time = intval($time);
        $day = $this->unixtoday($time);

        $feedname = "feed_".trim($feedid)."";
        $this->execCQL("DELETE FROM $feedname WHERE feed_id = $feedid AND day = $day AND time = $time");
    }

    public function deletedatarange($feedid,$start,$end)
    {
        $feedid = intval($feedid);
        $start = intval($start/1000.0);
        $end = intval($end/1000.0);
        $day_range = range($this->unixtoday($start), $this->unixtoday($end));

        $feedname = "feed_".trim($feedid)."";
        $this->execCQL("DELETE FROM $feedname WHERE feed_id=$feedid AND day IN (".implode($day_range,',').") AND time >= $start AND time <= $end");
        return true;
    }


// #### \/ Below are engine private methods    
    private function execCQL($cql)
    {
        $statement = new Cassandra\SimpleStatement($cql);
        $future    = $this->session->executeAsync($statement);  // fully asynchronous and easy parallel execution
        $result    = $future->get();                            // wait for the result, with an optional timeout
        return $result;
    }

    private function unixtoday($unixtime)
    {
        return floor($unixtime/86400);
    }

}