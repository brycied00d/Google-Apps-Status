#!/usr/local/bin/php
<?php
// Depends on Google Latitude Badge (http://www.google.com/latitude/apps/badge)
// being enabled. You can get the USER parameter from the Public JSON feed URL,
// after 'user=' and before '&type=json'

// Some very basic parameters
define(USER, '');	//Google's user ID for you
define(LCDDHOST, '');	//LCDd host to connect to
define(LCDDPORT, '13666');		//LCDd port to connect on
define(CACHE_EXPIRE, 60);	//Default is to expire data after 5 minutes
define(DEBUG, 0);	// 1: Generic Debug Messages, INFO
					// 2: Protocol-level debug messages
					// 3: Protocol-level debug with verbose messages


// Internals... Don't touch, dummy
define(CACHE_FILE, '/tmp/GoogleLatitude.cache');	//Don't touch this
define(GOOGLE_DATAURL, "http://www.google.com/latitude/apps/badge/api?user=".USER."&type=json");	//Don't douch this.
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
lcd_write('client_set name "GoogleLatitude"');
// Menu stuff
lcd_write($basemenu);
// Screen stuff
lcd_write('screen_add GoogleLatitude');
lcd_write('widget_add GoogleLatitude _titleBar title');
lcd_write('widget_add GoogleLatitude _issues frame');
lcd_write('screen_set GoogleLatitude -name "Google Latitude" -priority "info"');
lcd_write('widget_set GoogleLatitude _titleBar "Google Latitude"');

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
/*                                         Screen Widget                                          */
/**************************************************************************************************/
	}elseif($line === "listen GoogleLatitude")
	{
		if(DEBUG >= 1) echo ">< User is viewing the GoogleLatitude screen.\n";
		refresh_google_data(false);	//Use of cached value is fine
		if(DEBUG >= 1) print_google_data();
		

	echo "Your current position: {$location['geometry']['coordinates'][0]}, {$location['geometry']['coordinates'][1]} - {$location['properties']['reverseGeocode']}\n";
	echo "Data last updated ".(time() - $location['properties']['timeStamp'])." seconds ago, accurate to {$location['properties']['accuracyInMeters']} metres.\n";

		$screentemp = "";
		if(DISP_HEIGHT==2)
		{
			// Loc: X.123, Y.123 - CITY - Acc: Zm N mins ago
			$screentemp .= 'widget_add GoogleLatitude _line1 scroller'."\n";
			$screentemp .= 'widget_set GoogleLatitude _line1 1 2 '.DISP_WIDTH.' 2 m 4 "Loc: '.sprintf('%.3f, %.3f', $location['geometry']['coordinates'][0], $location['geometry']['coordinates'][1]).' - '.$location['properties']['reverseGeocode'].' - Acc: '.$location['properties']['accuracyInMeters'].'m '.(int)((time() - $location['properties']['timeStamp'])/60).' mins ago - "'."\n";
		} elseif(DISP_HEIGHT==4)
		{
			// Loc: X, Y - CITY
			// Accuracy: Z metres
			// Updated N minutes ago
			if(DISP_WIDTH <= 20)	// Not too wide, use scrollers here too
			{
				$screentemp .= 'widget_add GoogleLatitude _line1 scroller'."\n";
				$screentemp .= 'widget_add GoogleLatitude _line2 scroller'."\n";
				$screentemp .= 'widget_add GoogleLatitude _line3 scroller'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line1 1 2 20 2 m 6 "Loc: '.sprintf('%.3f, %.3f', $location['geometry']['coordinates'][0], $location['geometry']['coordinates'][1]).' - '.$location['properties']['reverseGeocode'].' - "'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line2 1 3 20 2 m 6 "Accu: '.$location['properties']['accuracyInMeters'].' metres"  '."\n";
				$screentemp .= 'widget_set GoogleLatitude _line3 1 4 20 2 m 6 "Update: '.(int)((time() - $location['properties']['timeStamp'])/60).' mins ago"  '."\n";
			} else	//Wide display :)
			{
				$screentemp .= 'widget_add GoogleLatitude _line1 string'."\n";
				$screentemp .= 'widget_add GoogleLatitude _line2 string'."\n";
				$screentemp .= 'widget_add GoogleLatitude _line3 string'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line1 1 2 "Loc: '.$location['geometry']['coordinates'][0].'°, '.$location['geometry']['coordinates'][1].'°  "'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line2 1 3 "Accuracy: '.$location['properties']['accuracyInMeters'].' metres - '.$location['properties']['reverseGeocode'].'  "'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line3 1 4 "Updated '.(int)((time() - $location['properties']['timeStamp'])/60).' mins ago"'."\n";
			}
		} else	// Don't know what it is, so just go big.
		{
			// Location: X, Y
			// City: CITY
			// Accuracy: Within Z metres
			// Last Update: N minutes ago
				$screentemp .= 'widget_add GoogleLatitude _line1 string'."\n";
				$screentemp .= 'widget_add GoogleLatitude _line2 string'."\n";
				$screentemp .= 'widget_add GoogleLatitude _line3 string'."\n";
				$screentemp .= 'widget_add GoogleLatitude _line4 string'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line1 1 2 "Location: '.$location['geometry']['coordinates'][0].'°, '.$location['geometry']['coordinates'][1].'°  "'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line2 1 3 "City: '.$location['properties']['reverseGeocode'].'"'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line3 1 4 "Accuracy: Within '.$location['properties']['accuracyInMeters'].' metres"'."\n";
				$screentemp .= 'widget_set GoogleLatitude _line4 1 5 "Last Update: '.(int)((time() - $location['properties']['timeStamp'])/60).' minutes ago"'."\n";
		}
		
		lcd_write($screentemp);
		$screentemp = "";	// Be sure to blank this, just to be clean
		
	}elseif($line === "ignore GoogleLatitude")
	{
		if(DEBUG >= 1) echo ">< User has left the GoogleLatitude screen.\n";
		
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
	global $googledata_timestamp, $location;
	// Fetch the JSON data, strip off Google's silly prefix and suffix, and stuff the parsed results away
	if($force || (time() - $googledata_timestamp) > CACHE_EXPIRE)
	{
		if(DEBUG >= 1) echo ">< Updating Google Latitude data cache (\$force=".($force ? "true" : "false").").\n";
		
		$json = file_get_contents(GOOGLE_DATAURL);
		$latitudefeed=json_decode($json,true);
		$location=$latitudefeed['features'][0];
		
		file_put_contents(CACHE_FILE, serialize($location));
		$googledata_timestamp=time();
	} else
	{
		if(DEBUG >= 1) echo ">< Reading Google Latitude from the data cache.\n";
		$cache = unserialize(file_get_contents(CACHE_FILE));
		if($cache)
			$location = $cache;
		else
			if(DEBUG >= 1) echo ">< There was an error while reading back the cache.\n";
	}
	if(DEBUG >= 1) echo ">< Refresh of Google Latitude data cache is complete.\n";
}

function print_google_data()
{
	global $googledata_timestamp, $location;
	
	echo "Your current position: {$location['geometry']['coordinates'][0]}, {$location['geometry']['coordinates'][1]} - {$location['properties']['reverseGeocode']}\n";
	echo "Data last updated ".(time() - $location['properties']['timeStamp'])." seconds ago, accurate to {$location['properties']['accuracyInMeters']} metres.\n";
}
?>
