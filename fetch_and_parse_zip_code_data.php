<?php
error_reporting(0);

require_once '../config.php';
include "includes/Tau.php";
include "includes/modules/TauDb.php";

// Note to self, San Diego County doesn't use WGS84.
// This needed to be applied to the shape files to turn them to geojson
// ogr2ogr -f GeoJSON -t_srs "EPSG:4326" zipcodes.geojson f285f663-2fc3-43b8-910a-31df89a27bdf2020330-1-1a4uvc4.5829.shp

date_default_timezone_set('America/Los_Angeles');

$server = new TauDbServer($mydatabase, $myusername, $mypassword);
$server->host = $myhost; 
// $server->port = 3306; 
$db = TauDB::init('Mysqli', $server);

if (!$db) {
	$error[] = "Could not initialize database connection.\n";
}

// When we find new data, we save it.
function stash_in_database($db, $county_zip_data) {
	$field_names = [
		"zip",
		"case_count",
		"updatedate",
	];

	$stuff_to_insert = [];

	foreach ($county_zip_data as $index => $zip_count) {
		$zip_values = [
			$zip_count->attributes->ziptext,
			$zip_count->attributes->case_count,
			$zip_count->attributes->updatedate,
		];
			
		$the_row = array_combine($field_names, $zip_values);
		array_push($stuff_to_insert, $the_row);
	}

	$db->insertMulti("sd_zip_cases", $stuff_to_insert);
}

// There is such date shenanigans going on here that I do not understand.
// I am assuming that ArcGIS and ESRI are to blame.

// What is the time frame that we have in my database?
$sql = "SELECT MIN(updatedate) FROM `sd_zip_cases`";
$first_reported_unixtime = $db->fetchValue($sql) / 1000;

$sql = "SELECT MAX(updatedate) FROM `sd_zip_cases`";
$last_reported_unixtime = $db->fetchValue($sql) / 1000;

if (!$last_reported_unixtime) {
	$error[] = "Could not read time from database.\n";
}

$time_since_last_report = time() - $last_reported_unixtime;

// Want to get the where clause referring to things like
// updatedate > DATE '2020-04-24'
// See other PHP files in this project for more bitching about dates.
// This is the same stuff that I use to ping the county as little as possible

if ($time_since_last_report > 60 * 60 * (24 + 24 + 12)) {
	$where_clause_date = date("Y-m-d", $last_reported_unixtime);
	$query_url = "https://gis-public.sandiegocounty.gov/arcgis/rest/services/Hosted/COVID_19_Statistics__by_ZIP_Code/FeatureServer/0/query";
	$query_url .= "?where=updatedate+%3E+DATE+%27";
	$query_url .= $where_clause_date;
	$query_url .= "%27";
	$query_url .= "&outFields=ziptext%2C+case_count%2C+updatedate&returnGeometry=false&returnDistinctValues=false&returnIdsOnly=false&returnCountOnly=false&f=json";
// Maybe that should be pjson instead of json?

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
			return (($item->attributes->updatedate) > (1000 * $last_reported_unixtime));
		});
		if (count($new_data) > 0) {
			stash_in_database($db, $new_data);			
		}
	}	
}

// Now what is the most recent? 
$sql = "SELECT MAX(updatedate) FROM `sd_zip_cases`";
$last_reported_unixtime = $db->fetchValue($sql) / 1000;

$fudge_factor = ($last_reported_unixtime - $first_reported_unixtime) / (24 * 3600) + 1;

$sql = "
	SELECT `ZIP`, `object_id`, `population`, `community`, `case_counts`, `geometry` FROM `zip_shapes`
	LEFT JOIN (
			SELECT `ZIP`, GROUP_CONCAT(COALESCE(`case_count`, 0) ORDER BY `updatedate` ASC) AS case_counts
		FROM `sd_zip_cases`
		GROUP BY `ZIP`
	) the_counts
	USING (ZIP)
";

$all_the_data = $db->fetchAllObject($sql);

// Now we need to make this into GeoJSON yay oof.
$geo_json_features_array = [];

// Also need an ad hoc array for finding max new cases per 100,000 population
// Sorry about the long variable name; there are a lot of things with similar names.
// We don't know which day or which ZIP will have the most here, so we need to check all.
$find_max_new_cases_per_100k = [];

foreach ($all_the_data as $index=>$zip_data) {
	$zip_data->geometry = json_decode($zip_data->geometry);
	$zip_data->case_counts = explode(',', $zip_data->case_counts);
	$zip_data->case_counts = array_map('intval', $zip_data->case_counts);
	
	// Some early data was missing. I haven't yet fixed it in the database.
	while (count($zip_data->case_counts) < $fudge_factor) {
		array_unshift($zip_data->case_counts, 0);
	}

	// Giving it a name for easy access
	$today_count = $zip_data->case_counts;
	
	// Need a copy of the array of total cases to compute successive differences
	$yesterday_count = $today_count;
	array_unshift($yesterday_count, 0);

	
	$new_cases_per_100k = [];
	for ($i = 0; $i < count($today_count); $i++) {
		$per_capita = 100000.0 * floatval($today_count[$i] - $yesterday_count[$i]) / max(floatval($zip_data->population), 1.0);
		array_push($new_cases_per_100k, max($per_capita, 0));
		// We get some weird values from low-population locations
		if ($today_count[$i] > 4 && $zip_data->population > 10000 && $i > 6) {
			array_push($find_max_new_cases_per_100k, $per_capita);
		}
	}
	
	$properties = (object) [
		ZIP => $zip_data->ZIP,
		object_id => intval($zip_data->object_id),
		population => intval($zip_data->population),
		community => $zip_data->community,
		case_counts => $zip_data->case_counts,
		new_cases_per_100k => $new_cases_per_100k,
	];

	$the_feature = (object) [
		type => "Feature",
		properties => $properties,
		geometry => $zip_data->geometry,
	];

	array_push($geo_json_features_array, $the_feature);
}

$formatted_data = (object) [
	type => "FeatureCollection",
	name => "frankendata",
	crs => json_decode("{ \"type\": \"name\", \"properties\": { \"name\": \"urn:ogc:def:crs:OGC:1.3:CRS84\" } }"),
	features => $geo_json_features_array,
];

// Moment and the county both like the milliseconds; PHP and d3 do not.
$date_span = (object) [
	'min_date' => 1000 * $first_reported_unixtime,
	'max_date' => 1000 * $last_reported_unixtime,
];

$sql = "
	SELECT MAX(10000 * case_count / population)
	FROM `zip_shapes`
	LEFT JOIN `sd_zip_cases`
	USING (ZIP)
	WHERE case_count > 4
	AND population > 10000
";
$max_per_10k = $db->fetchValue($sql);

$max_new_per_100k = max($find_max_new_cases_per_100k);

$payload = (object) [
	'date_span' => $date_span,
	'max_per_10k' => $max_per_10k,
	'max_new_per_100k' => $max_new_per_100k,
	'zip_data' => $formatted_data,
];

// header('Content-type: application/json');
echo json_encode($payload);
