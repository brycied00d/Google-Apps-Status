<?php
// Depends on Google Latitude Badge (http://www.google.com/latitude/apps/badge)
// being enabled. You can get the USER parameter from the Public JSON feed URL,
// after 'user=' and before '&type=json'

// Some very basic parameters
define(USER, '');	//Google's user ID for you

// Fetch the JSON data, strip off Google's silly prefix and suffix, and stuff the parsed results away
$json = file_get_contents("http://www.google.com/latitude/apps/badge/api?user=".USER."&type=json");
$latitudefeed=json_decode($json,true);
$location=$latitudefeed['features'][0];

echo "Your current position: {$location['geometry']['coordinates'][0]}, {$location['geometry']['coordinates'][1]} - {$location['properties']['reverseGeocode']}\n";
echo "Data last updated ".(time() - $location['properties']['timeStamp'])." seconds ago, accurate to {$location['properties']['accuracyInMeters']} metres.\n";
echo "Formatted Coordinates: ".sprintf('%.3f°, %.3f°', $location['geometry']['coordinates'][0], $location['geometry']['coordinates'][1])."\n";
?>
