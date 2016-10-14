<?php
class TimeSince {
	// $hour must be 00-23 (in 24-hour clock)
	function time_of_day($hour)
		{
		switch($hour)
		{
		case 00:
		case 01:
		case 02:
		$tod = 'the wee hours';
		break;
		case 03:
		case 04:
		case 05:
		case 06:
		$tod = 'terribly early in the morning';
		break;
		case 07:
		case 08:
		case 09:
		$tod = 'early morning';
		break;
		case 10:
		$tod = 'mid-morning';
		break;
		case 11:
		$tod = 'late morning';
		break;
		case 12:
		case 13:
		$tod = 'lunch time';
		break;
		case 14:
		$tod = 'early afternoon';
		break;
		case 15:
		case 16:
		$tod = 'mid-afternoon';
		break;
		case 17:
		$tod = 'late afternoon';
		break;
		case 18:
		case 19:
		$tod = 'early evening';
		break;
		case 20:
		case 21:
		$tod = 'evening time';
		break;
		case 22:
		$tod = 'late evening';
		break;
		case 23:
		$tod = 'late at night';
		break;
		default:
		$tod = '';
		break;
		}
		return $tod;
	}


	// adapted from original code by Natalie Downe
	// http://blog.natbat.co.uk/archive/2003/Jun/14/time_since
	 
	// inputs must be unix timestamp (seconds)
	// $newer_date variable is optional
	public static function time_since($older_date, $newer_date = false)
	{
		// array of time period chunks
		$chunks = array(
		array(60 * 60 * 24 * 365 , 'year'),
		array(60 * 60 * 24 * 30 , 'month'),
		array(60 * 60 * 24 * 7, 'week'),
		array(60 * 60 * 24 , 'day'),
		array(60 * 60 , 'hour'),
		array(60 , 'minute'),
		);
		 
		// $newer_date will equal false if we want to know the time elapsed between a date and the current time
		// $newer_date will have a value if we want to work out time elapsed between two known dates
		$newer_date = ($newer_date == false) ? time() : $newer_date;
		 
		// difference in seconds
		$since = $newer_date - $older_date;
		
		if ($since < 60)
		{
			return "$since seconds";
		}
		 
		// we only want to output two chunks of time here, eg:
		// x years, xx months
		// x days, xx hours
		// so there's only two bits of calculation below:
		 
		// step one: the first chunk
		for ($i = 0, $j = count($chunks); $i < $j; $i++)
		{
		$seconds = $chunks[$i][0];
		$name = $chunks[$i][1];
		 
		// finding the biggest chunk (if the chunk fits, break)
		if (($count = floor($since / $seconds)) != 0)
		{
		break;
		}
		}
		 
		// set output var
		$output = ($count == 1) ? '1 '.$name : "$count {$name}s";
		 
		// step two: the second chunk
		if ($i + 1 < $j)
		{
		$seconds2 = $chunks[$i + 1][0];
		$name2 = $chunks[$i + 1][1];
		 
		if (($count2 = floor(($since - ($seconds * $count)) / $seconds2)) != 0)
		{
		// add to output var
		$output .= ($count2 == 1) ? ', 1 '.$name2 : ", $count2 {$name2}s";
		}
		}
		 
		return $output;
	}
}
?>