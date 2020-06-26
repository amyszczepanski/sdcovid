<?php
error_reporting(0);

require_once '../config.php';
include "includes/Tau.php";
include "includes/modules/TauDb.php";

function stash_in_database($db, $county_data) {
	$field_names = [
		"county_date",
		"tests",
		"positives",
		"hospitalized",
		"icu",
		"deaths",
		"new_cases",
		"new_tests",
		"county_id"
	];

	$stuff_to_insert = [];

	foreach ($county_data as $index => $day_data) {
		$county_values = [
			$day_data->attributes->date,
			$day_data->attributes->tests,
			$day_data->attributes->positives,
			$day_data->attributes->hospitalized,
			$day_data->attributes->icu,
			$day_data->attributes->deaths,
			$day_data->attributes->newcases,
			$day_data->attributes->newtests,		
			$day_data->attributes->objectid,		
		];
	
		$the_row = array_combine($field_names, $county_values);
		array_push($stuff_to_insert, $the_row);
	}
	$db->insertMulti("sd_daily_cases", $stuff_to_insert);
}

$server = new TauDbServer($mydatabase, $myusername, $mypassword);
$server->host = $myhost; 
// $server->port = 3306; 
$db = TauDB::init('Mysqli', $server);

if (!$db) {
	$error[] = "Could not initialize database connection.\n";
}

// Get new stuff from county. Since the API has a limit, only get the new stuff.
// How much stuff do we have?
$sql = "SELECT MAX(county_date) FROM `sd_daily_cases`";
$last_reported_unixtime = $db->fetchValue($sql) / 1000;

// Catching errors now; will deal with handling them later.

if (!$last_reported_unixtime) {
	$error[] = "Could not read time from database.\n";
}

$time_since_last_report = time() - $last_reported_unixtime;

// The county's times are timestamped midnight here (but standard time), or 0800 UTC
// But they describe the situation from the previous day
// Example: The data timestamped 2020-05-18 0800 was posted on 2020-05-19 (local time)
// or roughly 2020-05-20 0000 in UTC. Super confusing!
if ($time_since_last_report > 60 * 60 * (24 + 24 + 12)) {
	$where_clause_date = date("Y-m-d", $last_reported_unixtime);
	$query_url = "https://gis-public.sandiegocounty.gov/arcgis/rest/services/Hosted/COVID_19_Statistics_San_Diego_County/FeatureServer/0/query";
	$query_url .= "?where=date+%3E+DATE+%27";
	$query_url .= $where_clause_date;
	$query_url .= "%27";
	$query_url .= "&outFields=objectid,date,tests,positives,hospitalized,icu,deaths,newcases,globalid,newtests&outSR=4326&f=json";

	$api_data = file_get_contents($query_url);
	if ($api_data) {
		$api_data = json_decode($api_data)->features;
	} else {
		$error[] = "No response from County API.\n";
	}
	
	if (count($api_data) > 0) {
		// filter this to only keep rows where the date field is larger than $last_reported_unixtime
		// if there is anything new
		$new_data = array_filter($api_data, function($item) use($last_reported_unixtime){
			return (($item->attributes->date) > (1000 * $last_reported_unixtime));
		});
		if (count($new_data) > 0) {
			stash_in_database($db, $new_data);			
		}
	}	
}

// Get everything that we have, old and new. Let the database take care of it all.
$sql = "SELECT * FROM `sd_daily_cases` WHERE `tests` IS NOT NULL AND FROM_UNIXTIME(county_date * 0.001) > NOW() - INTERVAL '90' DAY ORDER BY `sd_daily_cases`.`county_date` ASC";
$all_the_data = $db->fetchAllObject($sql);

// Now what to do with all this data that we are keeping track of?
// Moving average over how many days?
$moving_days = intval(htmlspecialchars($_GET["days"]));

// Pick a default value for error cases.
if ($moving_days < 1) { 
	$moving_days = 1;
}

if ($moving_days > count($all_the_data)) {
	$moving_days = 1;
}

// Big picture data for the top of the page
$recent_data = $all_the_data[count($all_the_data)-1];
$big_picture = (object) [
	'date' => $recent_data->county_date,
	'total_deaths' => $recent_data->deaths,
	'total_icu' => $recent_data->icu,
	'total_hospitalized' => $recent_data->hospitalized,
	'total_cases' => $recent_data->positives,
	'total_tests' => $recent_data->tests,
	'new_cases' => $recent_data->new_cases,
	'averaged_over' => $moving_days,
];

// Somewhere to store our time series data
$averaged_positive_rate = [];
$new_and_changed = [];

// Calculate moving average + other stuff by iterating
// Yes, I should have done this just once, but plans evolved.
foreach($all_the_data as $index=>$day_data) { 
	if ($index > 0) {
		$hospitalized = $day_data->hospitalized;
		$new_hospitalized = $hospitalized - $all_the_data[$index - 1]->hospitalized;
		$icu = $day_data->icu;
		$new_icu = $icu - $all_the_data[$index - 1]->icu;
		$deaths = $day_data->deaths;
		$new_deaths = $deaths - $all_the_data[$index - 1]->deaths;

		$non_test_data = (object) [
			'date' => $day_data->county_date,
			'total_positives' => $day_data->positives,
			'new_cases' => $day_data->new_cases,
			'hospitalized' => $hospitalized,
			'new_hospitalized' => $new_hospitalized,
			'icu' => $icu,
			'new_icu' => $new_icu,
			'deaths' => $deaths,
			'new_deaths' => $new_deaths,
		];
	
		$new_and_changed[] = $non_test_data;
	}
	
	if ($index > $moving_days - 1) {
		$current_tests = $day_data->tests;
		$past_tests = $all_the_data[$index - $moving_days]->tests;
		$current_positives = $day_data->positives;
		$past_positives = $all_the_data[$index - $moving_days]->positives;
		$test_count_valid = $current_tests && $past_tests && $current_tests !== $past_tests;
		$moving_average = $test_count_valid ?  100 * ($current_positives - $past_positives) / ($current_tests - $past_tests) : null;

		$today_data = (object) [
			'date' => $day_data->county_date,
			'moving_average' => $moving_average,
			'time_frame_positive' => ($current_positives - $past_positives),
			'time_frame_tests' => $test_count_valid ? ($current_tests - $past_tests) : null,
			'total_positives' => $current_positives,
		];
		
		$averaged_positive_rate[] = $today_data;
	}
} 

$payload = (object) [
	'big_picture' => $big_picture,
	'average_positive_rate' => $averaged_positive_rate,
	'non_test_data' => $new_and_changed,
];

// header('Content-type: application/json');
echo json_encode($payload);
