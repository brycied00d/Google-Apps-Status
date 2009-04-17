<?php
// Some very basic parameters
define(COL_1, 28);				//Width of the first column ("Services")
define(COL_2, 8);				//Width of the second column ("Issue?")
define(DATE_FMT, 'r');			//Output format used for date() functions
define(TZ, 'America/Phoenix');	//Timezone to print all times in


// Internals... Don't touch, dummy
$issues=array();	// Array, indexed by service ID, containing most recent message
ini_set('date.timezone', TZ);
putenv('TZ='.TZ);

// Fetch the JSON data, strip off Google's silly prefix and suffix, and stuff the parsed results away
$json = file_get_contents("http://www.google.com/appsstatus/json/en");
$json = substr($json, 16, -2);
$appstatus=json_decode($json,true);
$services=$appstatus['services'];
$messages=$appstatus['messages'];

//Re-factor the services list to index by 'id'
foreach($services as $svc)
	$newServices[$svc['id']]=$svc;
$services=$newServices;

//Re-factor the messages list as issues, indexed by service ID, containing the most recent message
/*
    [1]=>
    array(9) {
      ["service"]=>
      int(1)
      ["time"]=>
      float(1239920940000)
      ["pst"]=>
      string(29) "April 16, 2009 3:29:00 PM PDT"
      ["message"]=>
      string(186) "Google Mail service has already been restored for some users, and we expect a resolution for all users within the next 1 hours. Please note this time frame is an estimate and may change."
      ["type"]=>
      int(1)
      ["resolved"]=>
      bool(false)
      ["premier"]=>
      bool(false)
      ["additionals"]=>
      array(1) {
        [0]=>
        string(0) ""
      }
      ["workarounds"]=>
      array(1) {
        [0]=>
        string(0) ""
      }
    }
*/
foreach($messages as $msg)
{
	if(!isset($issues[$msg['service']]) || $issues[$msg['service']]['time'] < $msg['time'])
		$issues[$msg['service']]=$msg;
}
ksort($issues);	//Sort the list of issues by service ID




//Print a summary overview of all services
/*
Service				Issue?	Last
Google Docs List	No		04/16/09 15:13:00
Google Mail			Yes		04/16/09 16:17:37
*/
echo str_pad("Service", COL_1).str_pad("Issue?", COL_2)."Last Report"."\n";
foreach($issues as $svcid => $issue)
	echo str_pad($services[$svcid]['name'], COL_1).str_pad(($issue['resolved'] ? "No" : "YES"), COL_2).date(DATE_FMT, ($issue['time']/1000))."\n";
echo "\n";

//Print a list of the affected services
/*
Issues:
Google Mail @ April 16, 2009 3:29:00 PM PDT
     Google Mail service has already been restored for some users, and we expect a resolution for all users within the next 1 hours. Please note this time frame is an estimate and may change.

next....
*/
echo "Issues:\n";
foreach($issues as $svcid => $issue)
{
	if(!$issue['resolved'])
	{
		echo $services[$svcid]['name']." @ ".date(DATE_FMT, ($issue['time']/1000))."\n";
		echo "    ".$issue['message']."\n";
	}
}
echo "\n";

?>
