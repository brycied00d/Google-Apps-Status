#!/usr/local/bin/php
<?php
// Some very basic parameters
define(COL_1, 28);				//Width of the first column ("Services") Don't touch this.
define(COL_2, 8);				//Width of the second column ("Issue?") Don't touch this.
define(DATE_FMT, 'r');			//Output format used for date() functions Don't touch this.
define(DATE_FMT_SHORT, 'n/j G:i');	//Output format used for date() functions Don't touch this.
define(TZ, 'America/Phoenix');	//Timezone to print all times in
define(LCDDHOST, '192.168.1.215');	//LCDd host to connect to
define(LCDDPORT, '13666');		//LCDd port to connect on
define(CACHE_EXPIRE, 300);	//Default is to expire data after 5 minutes
define(CACHE_FILE, '/tmp/GoogleAppStatus.cache');	//Don't touch this
define(GOOGLE_DATAURL, "http://www.google.com/appsstatus/json/en");	//Don't douch this.
define(DEBUG, 3);	// 1: Generic Debug Messages, INFO
					// 2: Protocol-level debug messages
					// 3: Protocol-level debug with verbose messages


// Internals... Don't touch, dummy
$google_issues=array();	// Array, indexed by service ID, containing most recent message
ini_set('date.timezone', TZ);
putenv('TZ='.TZ);

$basemenu = '
menu_add_item "" _refresh action "Refresh Data"';

$googledata_timestamp=0;
$menuToDelete=array();	// Array of menu/item pairs to be deleted when we return to the main menu

// Connect and initialize
lcd_connect(LCDDHOST, LCDDPORT);
lcd_write('hello');
lcd_write('client_set name "GoogleAppsStatus"');
// Menu stuff
lcd_write($basemenu);
//lcd_write('menu_set_main ""');
// Screen stuff
lcd_write('screen_add GoogleAppsStatusIssues');
lcd_write('widget_add GoogleAppsStatusIssues _titleBar title');
lcd_write('widget_add GoogleAppsStatusIssues _issues frame');
lcd_write('screen_set GoogleAppsStatusIssues -name "Google Apps Issues" -priority "info"');
lcd_write('widget_set GoogleAppsStatusIssues _titleBar "Google Apps Issues"');
lcd_write('screen_add GoogleAppsStatusOverview');
lcd_write('widget_add GoogleAppsStatusOverview _titleBar title');
lcd_write('widget_add GoogleAppsStatusOverview _services frame');
lcd_write('screen_set GoogleAppsStatusOverview -name "Google Apps Status Overview" -priority "info"');
lcd_write('widget_set GoogleAppsStatusOverview _titleBar "Google Apps Status Overview"');

/**************************************************************************************************/
/*                                           Main Loop                                            */
/**************************************************************************************************/
while(!feof($link))
{
	$info=stream_get_meta_data($link);
	$line=lcd_read();
	
	if($line === "")	// If we iterated due to a time out, check the timer and skip the rest
	{
		continue;
/**************************************************************************************************/
/*                                      Main Menu Processing                                      */
/**************************************************************************************************/
	}elseif($line === "menuevent enter _client_menu_")	// User entered our main menu
	{
		if(DEBUG >= 1) echo ">< User has returned to the main menu.\n";
		
		if(sizeof($menuToDelete) > 0)	// A previous menu left things for us to clean up
		{
			if(DEBUG >= 1) echo ">< A previous menu left behind some menus. Cleaning up...\n";
			$menutemp = "";
			foreach($menuToDelete as $idx => $del)
			{
				if(DEBUG >= 1) echo ">< Deleting '{$del['item_id']}' from '{$del['menu_id']}'\n";
				$menutemp .= 'menu_del_item "'.$del['menu_id'].'" "'.$del['item_id'].'"'."\n";
				unset($menuToDelete[$idx]);
			}
			lcd_write($menutemp);
			$menutemp = "";	// Be sure to blank this, just to be clean
			if(DEBUG >= 1) echo ">< Menu cleanup complete.\n";
		}
		
	}elseif($line === "menuevent leave _client_menu_")	// User entered our main menu
	{
		if(DEBUG >= 1) echo ">< User left the main menu\n";
		
/**************************************************************************************************/
/*                                      Refresh Google Data                                       */
/**************************************************************************************************/
	}elseif($line === "menuevent select _refresh")
	{
		if(DEBUG >= 1) echo ">< User requested to force a refresh of the data.\n";
		$menutemp = "";
		$menutemp .= 'menu_add_item "" _refresh_inprogress menu "Refreshing data..." -is_hidden true'."\n";
		$menutemp .= 'menu_add_item "_refresh_inprogress" _refresh_inprogress_status action "Please wait..." -next "_none_"'."\n";
		$menutemp .= 'menu_goto _refresh_inprogress';
		lcd_write($menutemp);
		$menutemp = "";	// Be sure to blank this, just to be clean
		
		refresh_google_data(true);	//Force re-read instead of cache
		
		$menutemp = "";
		$menutemp .= 'menu_set_item "_refresh_inprogress" _refresh_inprogress_status -text "DONE!" -next "_close_"'."\n";
		lcd_write($menutemp);
		$menutemp = "";	// Be sure to blank this, just to be clean
		
	}elseif($line === "menuevent leave _refresh_inprogress")
	{
		if(DEBUG >= 1) echo ">< User left _refresh_inprogress, tagging menu for deletion.\n";
		$menuToDelete[]=array('menu_id' => '_refresh_inprogress', 'item_id' => '_refresh_inprogress_status');
		$menuToDelete[]=array('menu_id' => '', 'item_id' => '_refresh_inprogress');
		
/**************************************************************************************************/
/*                                     Screen Widget (Issues)                                     */
/**************************************************************************************************/
	}elseif($line === "listen GoogleAppsStatusIssues")
	{
		if(DEBUG >= 1) echo ">< User is viewing the GoogleAppsStatusIssues screen.\n";
		refresh_google_data(false);	//Use of cached value is fine
		if(DEBUG >= 1) print_google_data();
		
		//XXX Move this outside
		//Check if we have to upgrade the screen's priority
		$menutemp = "";
		if(check_google_for_alerts())	//Returns true if there's something important
			$menutemp .= 'screen_set GoogleAppsStatusIssues -priority "alert"';
		else
			$menutemp .= 'screen_set GoogleAppsStatusIssues -priority "info"';
		//lcd_write($menutemp);
		$menutemp = "";	// Be sure to blank this, just to be clean
		
		
		$screentemp = "";
		// Delete the frame and re-add it. Easy way to clear our last run
		$screentemp .= 'widget_del GoogleAppsStatusIssues _issues'."\n";
		$screentemp .= 'widget_add GoogleAppsStatusIssues _issues frame'."\n";
		
		if(check_google_for_alerts())	//Issues to display
		{
			$y=1;
			foreach($google_issues as $svcid => $issue)
			{
				if(!$issue['resolved'])
				{
					// One-line approach
					//$screentemp .= 'widget_add GoogleAppsStatusIssues _issues'.$svcid.' string -in _issues'."\n";
					//$screentemp .= 'widget_set GoogleAppsStatusIssues _issues'.$svcid.' 1 '.$y.' "'.($google_services[$svcid]['name']." @ ".date(DATE_FMT, ($issue['time']/1000))."    ".$issue['message']).'"'."\n";
					// Two-line approach
					//$screentemp .= 'widget_add GoogleAppsStatusIssues _issues'.$svcid.'l string -in _issues'."\n";
					//$screentemp .= 'widget_set GoogleAppsStatusIssues _issues'.$svcid.'l 1 '.$y.' "'.($google_services[$svcid]['name']." @ ".date(DATE_FMT, ($issue['time']/1000))).'"'."\n";
					//$y++;
					//$screentemp .= 'widget_add GoogleAppsStatusIssues _issues'.$svcid.'m string -in _issues'."\n";
					//$screentemp .= 'widget_set GoogleAppsStatusIssues _issues'.$svcid.'m 1 '.$y.' "'.$issue['message'].'"'."\n";
					//$y++;
					// N-line approach
					$screentemp .= 'widget_add GoogleAppsStatusIssues _issues'.$svcid.'l string -in _issues'."\n";
					$screentemp .= 'widget_set GoogleAppsStatusIssues _issues'.$svcid.'l 1 '.$y.' "'.($google_services[$svcid]['name']." @ ".date(DATE_FMT, ($issue['time']/1000))).'"'."\n";
					$y++;
					$messagetemp = wordwrap($issue['message'], DISP_WIDTH);
					if(DEBUG >=1) echo ">< Message broken apart to this:\n$messagetemp\n";
					$messagetemp = explode("\n", $messagetemp);
					foreach($messagetemp as $line)
					{
						$screentemp .= 'widget_add GoogleAppsStatusIssues _issues'.$svcid.'m'.$y.' string -in _issues'."\n";
						$screentemp .= 'widget_set GoogleAppsStatusIssues _issues'.$svcid.'m'.$y.' 1 '.$y.' "'.$line.'"'."\n";
						$y++;
					}
					$screentemp .= 'widget_add GoogleAppsStatusIssues _issues'.$svcid.'b string -in _issues'."\n";
					$screentemp .= 'widget_set GoogleAppsStatusIssues _issues'.$svcid.'b 1 '.$y.' "'.str_pad("BREAK", DISP_WIDTH, "=", STR_PAD_BOTH).'"'."\n";
					$y++;
				}
			}
			
		} else	//No issues :)
		{
			$screentemp .= 'widget_add GoogleAppsStatusIssues _issues0 string -in _issues'."\n";
			$screentemp .= 'widget_set GoogleAppsStatusIssues _issues0 1 1 "There are presently no issues."'."\n";
			$y=2;
		}
		
		//Adjust the frame to fit y rows
		// left top right bottom width height direction speed
		$screentemp .= 'widget_set GoogleAppsStatusIssues _issues 1 2 '.DISP_WIDTH.' '.DISP_HEIGHT.' '.DISP_WIDTH.' '.($y-1).' v 12'."\n";	// 12 = 1.5s
		
		
		lcd_write($screentemp);
		$screentemp = "";	// Be sure to blank this, just to be clean
		
	}elseif($line === "ignore GoogleAppsStatusIssues")
	{
		if(DEBUG >= 1) echo ">< User has left the GoogleAppsStatusIssues screen.\n";
		
/**************************************************************************************************/
/*                                    Screen Widget (Overview)                                    */
/**************************************************************************************************/
	}elseif($line === "listen GoogleAppsStatusOverview")
	{
		if(DEBUG >= 1) echo ">< User is viewing the GoogleAppsStatusOverview screen.\n";
		refresh_google_data(false);	//Use of cached value is fine
		if(DEBUG >= 1) print_google_data();
		
		$screentemp = "";
		// Delete the frame and re-add it. Easy way to clear our last run
		$screentemp .= 'widget_del GoogleAppsStatusOverview _services'."\n";
		$screentemp .= 'widget_add GoogleAppsStatusOverview _services frame'."\n";
		
		$y=1;
		foreach($google_issues as $svcid => $issue)
		{
		/*
			echo	str_pad($google_services[$svcid]['name'], COL_1).
					str_pad(($issue['resolved'] ? "No" : "YES"), COL_2).
					date(DATE_FMT, ($issue['time']/1000))."\n";
		*/
			$screentemp .= 'widget_add GoogleAppsStatusOverview _services'.$svcid.' string -in _services'."\n";
			$screentemp .= 'widget_set GoogleAppsStatusOverview _services'.$svcid.' 1 '.$y.' "'.
				substr(str_pad(($google_services[$svcid]['name']." @ ".date(DATE_FMT_SHORT, ($issue['time']/1000))), (DISP_WIDTH-($issue['resolved'] ? 2 : 5))), 0, (DISP_WIDTH-($issue['resolved'] ? 2 : 5))).($issue['resolved'] ? "OK" : "ERROR").'"'."\n";
			$y++;
		}
		
		//Adjust the frame to fit y rows
		// left top right bottom width height direction speed
		$screentemp .= 'widget_set GoogleAppsStatusOverview _services 1 2 '.DISP_WIDTH.' '.DISP_HEIGHT.' '.DISP_WIDTH.' '.($y-1).' v 12'."\n";	// 12 = 1.5s
		
		lcd_write($screentemp);
		$screentemp = "";	// Be sure to blank this, just to be clean
		
	}elseif($line === "ignore GoogleAppsStatusOverview")
	{
		if(DEBUG >= 1) echo ">< User has left the GoogleAppsStatusOverview screen.\n";
		
/**************************************************************************************************/
/*                                    Server Message Handling                                     */
/**************************************************************************************************/
	}elseif(substr($line, 0, 7) === "connect")
	{
		$serverInfo=substr($line, 8);
		if(DEBUG >= 1) echo ">< Server connected, info: $serverInfo.\n";
		//LCDproc 0.5.2 protocol 0.3 lcd wid 40 hgt 4 cellwid 5 cellhgt 8
		//Extract the width for future use "wid 40"
		if(preg_match('/wid (\d+)/', $serverInfo, $matches))
		{
			define(DISP_WIDTH, $matches[1]);
			if(DEBUG >= 1) echo ">< Display width: ".DISP_WIDTH."\n";
		} else	//No match??
		{
			define(DISP_WIDTH, 40);	//Default to 40
			if(DEBUG >= 1) echo ">< Display width set to 40 (default)\n";
		}
		$matches=null;
		//Extract the height for future use "hgt 4"
		if(preg_match('/hgt (\d+)/', $serverInfo, $matches))
		{
			define(DISP_HEIGHT, $matches[1]);
			if(DEBUG >= 1) echo ">< Display height: ".DISP_HEIGHT."\n";
		} else	//No match??
		{
			define(DISP_HEIGHT, 4);	//Default to 4
			if(DEBUG >= 1) echo ">< Display height set to 4 (default)\n";
		}
	}elseif($line === "bye")
	{
		if(DEBUG >= 1) echo ">< Server is shutting down, we'll follow suit.\n";
		break;
		
	}elseif(substr($line, 0, 4) === "huh?")
	{
		$error=substr($line, 5);
		if(DEBUG >= 1) echo ">< Server said we had an error: $error\n";
		
	}
}

//We only get out of the while() loop if the server disconnects us
lcd_disconnect();

function lcd_connect($ip, $port)
{
	global $link;
	if(DEBUG >= 1) echo ">< Connecting to $ip:$port\n";
	$link = fsockopen($ip, $port, $errno, $errstr, 30)
		or die("Unable to connect ($ip:$port) : $errstr ($errno)\n");
	if(DEBUG >= 1) echo ">< Connected!\n";
	stream_set_timeout($link, 2);
}

function lcd_write($buf)
{
	global $link;
	if($link)
	{
		$buf=trim($buf);
		if(DEBUG >= 2) foreach(explode("\n", $buf) as $line) echo " > $line\n";
		fwrite($link, "$buf\n");
	} else die("No connection to LCDd. Exiting.\n");
}

function lcd_read()
{
	global $link;
	if($link)
	{
		$line=fgets($link);
		//$line=fread($link, 128);
		if(DEBUG >= 3) $info=stream_get_meta_data($link);
		$line=trim($line);
		if(DEBUG >= 3) echo " < $line".($info['timed_out'] ? " «read timed out»" : "")."\n";
		elseif(DEBUG >= 2 && $line !== "") echo " < $line\n";
		return $line;
	} else die("No connection to LCDd. Exiting.\n");
}

function lcd_disconnect()
{
	global $link;
	if(DEBUG >= 1) echo ">< Disconnecting from LCDd\n";
	lcd_write('bye');
	fclose($link);
	if(DEBUG >= 1) echo ">< Disconnected!\n";
}

function lcd_dimmer_enable()
{
	global $dimmerState;
	if($dimmerState === false)
	{
		if(DEBUG >= 1) echo ">< Enabling the OSLI dimmer.\n";
		$dimmerState=true;
		lcd_write("output 18");
		usleep(300000);	// 300ms, make sure the command makes it to the display
	}else
		if(DEBUG >= 1) echo ">< OSLI dimmer already enabled.\n";
}

function lcd_dimmer_disable()
{
	global $dimmerState;
	if($dimmerState === true)
	{
		if(DEBUG >= 1) echo ">< Disabling the OSLI dimmer.\n";
		$dimmerState=false;
		lcd_write("output 19");
		usleep(300000);	// 300ms, make sure the command makes it to the display
	}else
		if(DEBUG >= 1) echo ">< OSLI dimmer already disabled.\n";
}

function lcd_clear_screen()
{
	if(DEBUG >= 1) echo ">< Issuing a hardware display clear.\n";
	lcd_write('output 15');
	usleep(300000);	// 300ms, make sure the command makes it to the display
}


function refresh_google_data($force=false)
{
	global $googledata_timestamp, $google_issues, $google_services;
	// Fetch the JSON data, strip off Google's silly prefix and suffix, and stuff the parsed results away
	if($force || (time() - $googledata_timestamp) > CACHE_EXPIRE)
	{
		if(DEBUG >= 1) echo ">< Updating Google App Status data cache (\$force=".($force ? "true" : "false").").\n";
		
		$json = file_get_contents(GOOGLE_DATAURL);
		$json = substr($json, 16, -2);
		$appstatus=json_decode($json,true);
		
		$google_services=$appstatus['services'];
		$messages=$appstatus['messages'];
		
		//Re-factor the services list to index by 'id'
		foreach($google_services as $svc)
			$newServices[$svc['id']]=$svc;
		$google_services=$newServices;
		
		//Re-factor the messages list as issues, indexed by service ID, containing the most recent message
		foreach($messages as $msg)
		{
			if(!isset($google_issues[$msg['service']]) || $google_issues[$msg['service']]['time'] < $msg['time'])
				$google_issues[$msg['service']]=$msg;
		}
		ksort($google_issues);	//Sort the list of issues by service ID
		
		$cache=array('issues' => $google_issues, 'services' => $google_services);
		file_put_contents(CACHE_FILE, serialize($cache));
		$googledata_timestamp=time();
	} else
	{
		if(DEBUG >= 1) echo ">< Reading Google App Status from the data cache.\n";
		$cache = unserialize(file_get_contents(CACHE_FILE));
		if($cache)
		{
			$google_services = $cache['services'];
			$google_issues = $cache['issues'];
		} else
			if(DEBUG >= 1) echo ">< There was an error while reading back the cache.\n";
	}
	if(DEBUG >= 1) echo ">< Refresh of Google App Status data cache is complete.\n";
}

// Simple function to look through all the issues and return true as soon as it finds the first "issue"
function check_google_for_alerts()
{
	global $googledata_timestamp, $google_issues, $google_services;
	foreach($google_issues as $svcid => $issue)
	{
		if(!$issue['resolved'])
		{
			if(DEBUG >= 1) echo ">< There is an active issue in the Google Apps Status screen.\n";
			return true;
		}
	}
	if(DEBUG >= 1) echo ">< There are no issues in the Google Apps Status screen.\n";
	return false;
}

function print_google_data()
{
	global $googledata_timestamp, $google_issues, $google_services;
	//Print a summary overview of all services
	/*
	Service				Issue?	Last
	Google Docs List	No		04/16/09 15:13:00
	Google Mail			Yes		04/16/09 16:17:37
	*/
	echo str_pad("Service", COL_1).str_pad("Issue?", COL_2)."Last Report"."\n";
	foreach($google_issues as $svcid => $issue)
		echo str_pad($google_services[$svcid]['name'], COL_1).str_pad(($issue['resolved'] ? "No" : "YES"), COL_2).date(DATE_FMT, ($issue['time']/1000))."\n";
	echo "\n";
	
	//Print a list of the affected services
	/*
	Issues:
	Google Mail @ April 16, 2009 3:29:00 PM PDT
		 Google Mail service has already been restored for some users, and we expect a resolution for all users within the next 1 hours. Please note this time frame is an estimate and may change.
	
	next....
	*/
	echo "Issues:\n";
	foreach($google_issues as $svcid => $issue)
	{
		if(!$issue['resolved'])
		{
			echo $google_services[$svcid]['name']." @ ".date(DATE_FMT, ($issue['time']/1000))."\n";
			echo "    ".$issue['message']."\n";
		}
	}
	echo "\n";
}
?>
