<?php

// Set environment variables for database connection and tsheets & slack APIs
// Also set an array of possible channel name overrides.
require('env.php');

// Enable error output. Since this is running as a cron job, there's no danger
// of exposing errors to the outside internet.
error_reporting(E_ALL);
ini_set('display_errors', 1);
require("tsheets.inc.php");

// Configure the database parameters
$dsn = 'mysql:host=mysql;dbname='.$database;
$options = array(
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'
);

// Connect to the database
$dbh = new PDO($dsn, $username, $password, $options);

// Set our TSheets API info and the group ID for our technicians. Create a new
// TSheet REST object as well. This is necessary to communicate with the TSheets
// API.
$tsheet_url     = "https://rest.tsheets.com/api/v1";
$sheets         = new TSheetsRestClient(1, $tsheet_token);

// Pull the current timestamp.
$cur_timestamp  = date(DATE_ISO8601);

// postMessage allows us to post an arbitrary message to an arbitrary channel
// using our slack token.
function postMessage($text, $channel, $token){
        $slack_url              = "https://slack.com/api/chat.postMessage";
        $auth                   = "Authorization: Bearer ".$token;
        $post = '{
                "token": "'.$token.'",
                "channel": "'.$channel.'",
                "text": "'.$text.'"
        }';
        $slack = curl_init($slack_url);
        $options = array(
        CURLOPT_HTTPHEADER      => array('Content-Type: application/json; charset=utf-8;Cache-Control: no-cache,must-revalidate', $auth),
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POSTFIELDS      => 1,
        CURLOPT_POSTFIELDS      => $post,
        CURLOPT_FOLLOWLOCATION  => 1,
        CURLOPT_FRESH_CONNECT   => true
        );
        curl_setopt_array($slack, $options);
        $result = curl_exec($slack);
        curl_close($slack);
        return (json_decode($result));
}

// Set up the tsheet options using the tech_groups defined in the env file
// We want all clock statuses so we can compare.
$tsheet_options = array(
        'group_ids' => $tech_groups,
        'on_the_clock' => 'both'
);

// Get our current timesheet status from the Tsheets API
$totals = $sheets->get_report(ReportType::CurrentTotals, $tsheet_options);

// Get our last snapshot of clock statuses from the database
// Put it into the query array.
$sql_read = $dbh->prepare("SELECT * FROM state");
$sql_read->execute();
$query = $sql_read->fetchAll(PDO::FETCH_ASSOC);

// Initialize the db_state...
$db_state = Array();

// ...and iterate over our sql result to build the db_state array.
// The array is just a key -> value digest of each employees tsheets
// user_id as well as their current "on the clock" status.
foreach($query as $k => $v){
        $db_state[$v["user_id"]] = $v["state"];
}

// Initialize the api_state. We'll use this to perform comparisons with the db_state
$api_state = Array();

// Initialize a blank string for sql insert query later on.
$insertion = "";

// Iterate through the tsheets API output to get our users' first name and
// last name. From this, we can extrapolate the full name as well as the
// user's slack channel as long as it follows a strict firstname_lastname
// schema.
foreach($totals["results"]["current_totals"] as $key => $val){
        $name = $totals["supplemental_data"]["users"][$key]["first_name"];
        $name .= " ";
        $name .= $totals["supplemental_data"]["users"][$key]["last_name"];
        $chn = $totals["supplemental_data"]["users"][$key]["first_name"];
        $chn .= "_";
        $chn .= $totals["supplemental_data"]["users"][$key]["last_name"];
        // Make sure the channel is lowercase as channel names are case-sensitive.
        $chn = strtolower($chn);

        // Initialize the message as "off the clock."
        $clock = "off the clock.";

        // Change it to "on the clock." if they are in fact on the clock.
        if($val["on_the_clock"]){
                $clock = "on the clock.";
                
                // And change it to "on break." if they're on the break "jobcode"
                if($val["jobcode_id"] == "6439931"){
                        $clock = "on break.";
                }
        }

        // Build the message using the full name and the clock status.
        $msg = $name." is now ".$clock;
        
        // Now we set the clock status as the value relative to our user id key
        $api_state[$key] = $clock;

        // Compare the db_state to the api_state. If there's a difference, we know
        // the clock status changed and we need to 1) post a new message to both the
        // test channel and the tech channel and 2) we need to update our database
        // with the new clock status
        if($db_state[$key] != $api_state[$key]){
                postMessage($msg, "testforalex", $slack_token);
                echo $msg."\n";

                // Add this record to our database update query 
                $insertion .= "('".$key."', '".$clock."'),";
                sleep(1);

                // In case the slack channel does not follow the proper naming convention,
                // we check to see if the name has a designated override. This is set up
                // in the env file.
                if(array_key_exists($name, $chan_override)) {
                        print_r(postMessage($msg, $chan_override[$name], $slack_token));
                        sleep(1);
                }
                else {
                        print_r(postMessage($msg, $chn, $slack_token));
                        sleep(1);
                }
        }
}

// If we have records to insert, insert them into the database now.
if($insertion){
        $insertion = substr($insertion, 0, -1);
        $query = "REPLACE INTO state (user_id, state) VALUES ".$insertion;
        echo $query."\n";
        $sql_write = $dbh->prepare($query);
        $sql_write->execute();
}

echo $cur_timestamp."\n";

?>
