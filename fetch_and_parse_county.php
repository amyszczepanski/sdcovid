<?php
// Moving average over how many days?
$moving_days = intval(htmlspecialchars($_GET["days"]));

// Do something different in error cases
if ($moving_days < 1) { 
	$moving_days = 1;
}

// Set a constant here for testing.
// $moving_days = 7;


// Get data from the County and sort
$response = file_get_contents("https://gis-public.sandiegocounty.gov/arcgis/rest/services/Hosted/COVID_19_Statistics_San_Diego_County/FeatureServer/0/query?where=1%3D1&outFields=objectid,date,tests,positives,newtests,newcases&returnGeometry=false&outSR=&f=json");
$response = json_decode($response)->features;
usort($response, function($a, $b) {return $a->attributes->date > $b->attributes->date;});

if ($moving_days > count($response)) {
	$moving_days = 1;
}

// Big picture data for the top of the page
$recent_data = $response[count($response)-1]->attributes;
$total_cases = $recent_data->positives;
$total_tests = $recent_data->tests;
$new_cases = $recent_data->newcases;
$reporting_date = $recent_data->date;
$big_picture = (object) [
	'date' => $reporting_date,
	'total_cases' => $total_cases,
	'total_tests' => $total_tests,
	'new_cases' => $new_cases,
	'averaged_over' => $moving_days,
];


// Somewhere to store our time series data
$averaged_positive_rate = [];

// Calculate moving average
foreach($response as $index=>$day_data) { 
	if ($index > $moving_days - 1) {
		$current_tests = $day_data->attributes->tests;
		$past_tests = $response[$index - $moving_days]->attributes->tests;
		$current_positives = $day_data->attributes->positives;
		$past_positives = $response[$index - $moving_days]->attributes->positives;
		$test_count_valid = $current_tests && $past_tests && $current_tests !== $past_tests;
		$moving_average = $test_count_valid ?  100 * ($current_positives - $past_positives) / ($current_tests - $past_tests) : null;

		// Saving as an object
		$today_data = (object) [
			'date' => $day_data->attributes->date,
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
];

// header('Content-type: application/json');
echo json_encode($payload);
