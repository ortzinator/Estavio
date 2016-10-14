<?php

if ($argc != 2 || in_array($argv[1], array('--help', '-help', '-h', '-?')))
{

?>
This is a command line PHP script with one option.

  Usage:
  <?php echo $argv[0]; ?> <servername>

  <servername> is the name of an irc network
  as defined in server.ini
  
  With the --help, -help, -h,
  or -? options, you can get this help.
<?php
    exit;
}

require ('Services/Weather.php');
require ('evalmath.class.php');
require ('TimeSince.class.php');


/* Variables that determine server, channel, etc */
$config_temp = parse_ini_file("server.ini", 1);
$network = trim($argv[1]);

if ($network == "gamesurge")
{
    $gamesurge = true;
}
else
{
    $gamesurge = false;
}
//echo ($network . "\n");

// verify config file has everything
check_config($config_temp, $network);

$CONFIG = array();
$CONFIG['server'] = $config_temp[$network]["server"];
$CONFIG['nick'] = 'Estavio';// nick (i.e. demonbot
$CONFIG['port'] = $config_temp[$network]["port"];
$CONFIG['channels'] = explode(",", $config_temp[$network]["channels"]);
$CONFIG['name'] = "I'm a bot. Contact: Ortzinator";// bot name (i.e. demonbot)
$CONFIG['admin_pass'] = 'password';// admin pass (to change settings remotely)
$CONFIG['network'] = $network;

if (!$gamesurge)
{
    $CONFIG['identpass'] = $config_temp[$network]["identpass"];
}
//echo ($network . "\n");

$acl = "access.txt";

/* Let it run forever (no timeouts) */
set_time_limit(0);

/* The connection */
$con = array();

$weather_cache = array();
$forecast_cache = array();

/* start the bot... */
init();

function init()
{
    global $con, $CONFIG, $gamesurge, $reconnect_time, $channels;
    unset($channels);
    /* We need this to see if we need to JOIN (the channel) during
    the first iteration of the main loop */
    $firstTime = true;

    /* Connect to the irc server */
    $con['socket'] = @fsockopen($CONFIG['server'], $CONFIG['port']);

    /* Check that we have connected */
    if (!$con['socket'])
    {
        print ("Could not connect to: " . $CONFIG['server'] . " on port " . $CONFIG['port'] .
            "\n");
    }
    else
    {
        /* Send the username and nick */
        cmd_send("USER " . $CONFIG['nick'] . " ortz.org ortz.org :" . $CONFIG['name']);
        cmd_send("NICK " . $CONFIG['nick'] . " ortz.org");

        reset_timeout();

        /* Here is the loop. Read the incoming data (from the socket connection) */
        while (check_con())
        {
            /* Think of $con['buffer']['all'] as a line of chat messages.
            We are getting a 'line' and getting rid of whitespace around it. */
            $con['buffer']['all'] = trim(fgets($con['socket'], 4096));

            /* Pring the line/buffer to the console
            I used <- to identify incoming data, -> for outgoing. This is so that
            you can identify messages that appear in the console. */

            print date("[d/m @ H:i]") . "<- " . $con['buffer']['all'] . "\n";
            //print date("[h:i]")." [".$buffer['username']."] ".

            $buf_expl = explode(" ", $con['buffer']['all'], 4);

            /* If the server is PINGing, then PONG. This is to tell the server that
            we are still here, and have not lost the connection */
            if (substr($con['buffer']['all'], 0, 6) == 'PING :')
            {

                /* PONG : is followed by the line that the server
                sent us when PINGing */
                cmd_send('PONG :' . substr($con['buffer']['all'], 6));

                reset_timeout();
            }

            if (($buf_expl[1] == "376") || ($buf_expl[1] == "422"))
            {
                /* If this is the first time we have reached this point,
                then JOIN the channel(s) */
                if ($firstTime == true)
                {

                    if ($gamesurge)
                    {
                        cmd_send('PRIVMSG AuthServ@Services.GameSurge.net :auth Estavio obYcm1sM');
                        cmd_send('MODE Estavio +iwx');
                    }
                    else
                    {
                        cmd_send('PRIVMSG nickserv :IDENTIFY ' . $CONFIG['identpass']);
                    }
                    sleep(2);

                    if (is_array($CONFIG['channels']))
                    {
                        foreach ($CONFIG['channels'] as $channel)
                        {
                            cmd_send("JOIN " . $channel);
                        }
                        /* The next time we get here, it will NOT be the firstTime */
                        $firstTime = false;
                    }
                    else
                    {
                        cmd_send("JOIN " . $CONFIG['channels']);
                        /* The next time we get here, it will NOT be the firstTime */
                        $firstTime = false;
                    }
                }
                /* Make sure that we have a NEW line of chats to analyse. If we don't,
                there is no need to parse the data again */
            }
            elseif ($old_buffer != $con['buffer']['all'])
            {
                /* Determine the patterns to be passed
                to parse_buffer(). buffer is in the form:
                :username!~identd@hostname JOIN :#php
                :username!~identd@hostname PRIVMSG #PHP :action text
                :username!~identd@hostname command channel :text */

                /*
                Right now this bot does nothing. But this is where you would
                add some conditions, or see what is being said in the chat, and then 
                respond. Before you try doing that you should become familiar with
                how commands are send over IRC. Just read the console when you run this
                script, and then you will see the patterns in chats, i.e. where the username
                occurs, where the hostmask is, etc. All you need is functions such as
                preg_replace_callback(), or perhaps your own function that checks for patterns
                in the text.
                
                Good Luck.
                */
                // log the buffer to "log.txt" (file must have
                // already been created).

                // make sense of the buffer
                parse_buffer();

                ctcp_catcher();

            }
            $old_buffer = $con['buffer']['all'];
        }
        init();
    }
}

function check_con()
{
    global $con, $reconnect_time;

    if (!$con['socket'])
    {
        echo ("socket is false\n");
        return false;
    }
    if (feof($con['socket']))
    {
        echo ("EOF detected\n");
        return false;
    }

    if (time() > ($reconnect_time + (5 * 60)))
    {
        cmd_send(say("Ortzinator", "I'm an idiot!!!!!"));
        cmd_send(say("Ortzinator", "thetime= " . time()));
        cmd_send(say("Ortzinator", "reconnect_time= " . $reconnect_time));

        return false;
    }

    return true;
}

function reset_timeout()
{
    global $reconnect_time;
    $reconnect_time = time();
}

/* Accepts the command as an argument, sends the command
to the server, and then displays the command in the console
for debugging */
function cmd_send($command)
{
    global $con, $time, $CONFIG;
    /* Send the command. Think of it as writing to a file. */
    fputs($con['socket'], $command . "\n\r");
    /* Display the command locally, for the sole purpose
    of checking output. (line is not actually not needed) */
    print (date("[d/m @ H:i]") . "-> " . $command . "\n\r");

}

function log_to_file($data)
{
    global $CONFIG;
    $net = $CONFIG['network'];

    $filename = "log.$net.txt";
    $data .= "\n";
    // open the log file
    if ($fp = fopen($filename, "ab"))
    {
        // now write to the file
        if ((fwrite($fp, $data) === false))
        {
            echo "Could not write to file.<br />";
        }
    }
    else
    {
        echo "File could not be opened.<br />";
    }
}

function get_posts($user)
{
    $db_hostname = "hl2land.net";
    $db_username = "ortz_ortz";
    $db_password = "password";
    $db_name = "ortz_main";

    $conn = @mysql_connect($db_hostname, $db_username, $db_password);
    @mysql_select_db($db_name, $conn);

    if (!mysql_select_db || !$conn)
    {
        echo "Could not connect to the database.";
        return "Could not connect to the database.";
    }

    $query = mysql_query("SELECT num_posts FROM punbb_users WHERE username = '$user'");

    if (!query)
    {
        echo "Query failed.";
        return "Query failed.";
    }


    if (mysql_num_rows($query) == 0)
    {
        echo "The user $user could not be found";
        return "The user $user could not be found";
    }
    else
    {
        $row = mysql_fetch_assoc($query);
        return $user . " has " . $row['num_posts'] .
            " posts on the forum. (http://forums.ortz.org/)";
    }
}

function process_commands($text, $user, $chan, $is_pm = false)
{
    global $con, $CONFIG, $acl, $channels;

    $commands = array("", "", "");


    $args = explode(" ", $text);
    if (check_access($user))
    {// ADMIN COMMANDS
        switch (strtoupper($args[0]))
        {
            case ".NICK":
                if (count($args) < 2)
                    cmd_send(say($chan, $user['name'] . ": Please specify a new nick"));
                else
                {
                    cmd_send("NICK " . $args[1]);
                    $CONFIG['name'] = $args[1];
                }
                break;
            case ".JOIN":
                if (count($args) < 2)
                    cmd_send(say($chan, $user['name'] . ": Please specify a channel to join"));
                elseif (in_channel($args[1]))
                {
                    notice($chan, "I'm already in that channel.");
                }
                else
                {
                    cmd_send("JOIN " . $args[1]);
                }
                break;
            case ".PART":
                if (count($args) < 2)
                {
                    cmd_send(say($chan, $user['name'] . ": Please specify a channel to part"));
                }
                else
                {
                    part($args[1]);
                }
                break;
            case '.NOOB':
                $name = (!empty($args[1])) ? $args[1]:"beginner";
                cmd_send(say($chan, "Welcome, " . $name .
                    ", to Lua! Some links: http://gmwiki.garry.tv/index.php/Lua, http://www.lua.org/manual/5.1/"));
                break;
            case '.PM':
                cmd_send(say($chan, "Please do not send PMs to ops/peons unless you have asked first."));
                break;
            case '.QUIT':
                cmd_send(say($chan, "Okay, seeya..."));
                cmd_send('QUIT');
                fclose($con['socket']);
                echo ("TERMINATED BY: " . $user['name'] . "\n");
                exit;
                break;
            case '.EMO':
                cmd_send(say($chan, "\1ACTION cuts himself\1"));
                break;
            case '.BODYGUARD':
                cmd_send(say($chan, "\1ACTION defends " . $user['name'] . "\1"));
                break;
            case '.POSTS':
                cmd_send(say($chan, (get_posts($args[1]))));
                break;
            case '.STAB':
                cmd_send(say($chan, "\1ACTION stabs " . $args[1] . "\1"));
                break;
            case '.SAY':
                $say_pos = strpos($text, $args[2]);
                $msg = substr($text, $say_pos);
                cmd_send(say($args[1], $msg));
                break;
            case '.DO':
                $say_pos = strpos($text, $args[2]);
                $msg = substr($text, $say_pos);
                cmd_send(action($args[1], $msg));
                break;
            case '.TALLY':

                if (count($args) < 2)
                {
                    cmd_send(say($chan, count($channels[$chan]['users'])));
                    var_dump($channels[$chan]['users']);
                }
                else
                {
                    if (!in_channel($args[1]))
                    {
                        cmd_send(say($chan, "I'm not in that channel."));
                        break;
                    }
                    cmd_send(say($chan, count($channels[$args[1]]['users'])));
                    print_r($channels[$args[1]]['users']);
                }
                break;
            case '.RAW':
                $say_pos = strpos($text, $args[1]);
                $msg = substr($text, $say_pos);
                cmd_send($msg);
                break;
            case '.ISOP':
                cmd_send(say($chan, is_op($args[1], $chan)));
                break;
			case '.LOC':
				get_loc($args[1]);
				break;
        }
    }

    if (is_op($user['name'], $chan) || check_access($user))
    {//OP-only commands
        switch (strtoupper($args[0]))
        {
            case '.K':

                if (count($args) == 2)
                {
                    kick($args[1], $chan);
                }
                elseif (count($args) >= 3)
                {
                    $say_pos = strpos($text, $args[2]);
                    $msg = substr($text, $say_pos);
                    kick($args[1], $chan, $msg);
                }
                break;
        }
    }

    // Anyone commands
    switch (strtoupper($args[0]))
    {
    case '.TIME':
        notice($user['name'], date("F j, Y, g:i a T", time()));
        break;
    case '.UNIXTIME':
        notice($user['name'], time());
        break;
	//case '.COMMANDS':
	//        $cmd_list = implode(", ", $user_commands);
	//        cmd_send(say($chan, "Commands: " . $cmd_list));
	//        break;
    case '.WEATHER':
        $say_pos = strpos($text, $args[1]);
        $msg = substr($text, $say_pos);
		
		$loc = get_loc($msg);
		if(!$loc)
		{
			notice($user['name'], "Location not found");
			break;
		}

        $weather = get_weather($loc, 's');

        if (!$weather)
        {
            notice($user['name'], "Could not get your weather.");
			break;
        }
            notice($user['name'], $weather);
        break;
	case '.WEATHERM':
        $say_pos = strpos($text, $args[1]);
        $msg = substr($text, $say_pos);
		
		$loc = get_loc($msg);
		if(!$loc)
		{
			notice($user['name'], "Location not found");
			break;
		}

        $weather = get_weather($loc, 'm');

        if (!$weather)
        {
            notice($user['name'], "Could not get your weather.");
			break;
        }
            notice($user['name'], $weather);
        break;
	case '.FORECAST':
		$say_pos = strpos($text, $args[1]);
        $msg = substr($text, $say_pos);
		
		$loc = get_loc($msg);
		if(!$loc)
		{
			notice($user['name'], "Location not found");
			break;
		}

        $fc = get_forecast($loc, 3, 's');
		
		if (!$fc)
        {
            notice($user['name'], "Could not get your weather.");
        }
		else
		{
            notice($user['name'], $fc);
		}
		break;
    case '.SEEN':
        if (count($args) < 2)
        {
            break;
        }
        if ($args[1] == $CONFIG['nick'])
        {
            notice($user['name'], "That's me silly.");
            break;
        }
        if ($args[1] == $user['name'])
        {
            notice($user['name'], "I see you just fine. :)");
            break;
        }

        $seen = get_last_seen($args[1]);
        if (!$seen)
        {
            notice($user['name'], "I haven't seen anyone by the name \"" . $args[1] . "\"");
            break;
        }



        if ($seen['action'] == "join")
        {
            cmd_send(say($chan, $seen['nick'] . " was last seen joining " . $seen['channel'] .
                " " . TimeSince::time_since($seen['datetime']) . " ago"));
        }
        elseif ($seen['action'] == "quit")
        {
            cmd_send(say($chan, $seen['nick'] . " was last seen quitting " . TimeSince::
                time_since($seen['datetime']) . ", stating \"" . $seen['message'] . "\""));
        }
        elseif ($seen['action'] == "msg")
        {
            cmd_send(say($chan, $seen['nick'] . " was last seen chatting in " . $seen['channel'] .
                " " . TimeSince::time_since($seen['datetime']) . " ago"));
        }
        elseif ($seen['action'] == "part")
        {
            cmd_send(say($chan, $seen['nick'] . " was last seen parting " . $seen['channel'] .
                " " . TimeSince::time_since($seen['datetime']) . " ago"));
        }
        elseif ($seen['action'] == "nick")
        {
            cmd_send(say($chan, $seen['nick'] . " was last seen changing nicks to " . $seen['newnick'] .
                " " . TimeSince::time_since($seen['datetime']) . " ago"));
        }
		elseif ($seen['action'] == "nickfrom")
		{
			cmd_send(say($chan, $seen['nick'] . " was last seen changing nicks from " . $seen['newnick'] .
                " " . TimeSince::time_since($seen['datetime']) . " ago"));
        }
        elseif ($seen['action'] == "kick")
        {
            cmd_send(say($chan, $seen['nick'] . " was last seen getting kicked from " . $seen['channel'] .
                " " . TimeSince::time_since($seen['datetime']) . " ago"));
        }

        var_dump(get_last_seen($args[1]));
        break;
    case '.EVAL':
        if (count($args) < 2)
        {
            break;
        }
        $say_pos = strpos($text, $args[1]);
        $msg = substr($text, $say_pos);
        cmd_send(say($chan, $user['name'] . ": " . eval_math($msg)));
        break;
    /* case '.LOC':
        if (count($args) < 2)
        {
            break;
        }
        if (preg_match("(\d\d\d\d\d)", $args[1]))
        {
            $units = "s";
            $zipcode = $args[1];
        }
        else
        {
            notice($user['name'], "Only US ZIP codes are supported at the moment.");
        }
        break; */
    case '.LUAC':
        $say_pos = strpos($text, $args[1]);
        $msg = substr($text, $say_pos);
        cmd_send(say($chan, func_find_client($msg)));
        break;
    case '.LUAS':
        $say_pos = strpos($text, $args[1]);
        $msg = substr($text, $say_pos);
        cmd_send(say($chan, func_find_server($msg)));
        break;
    case '??':
        if ($chan != "#luahelp")
        {
            break;
        }
        switch (strtoupper($args[1]))
        {
        case 'PASTEBIN':
            cmd_send(say($chan, "Please do not paste in the channel. Use a pastebin like http://pastebin.com or http://pastebin.ca and give us the link."));
                break;
        case 'ASK':
            cmd_send(say($chan, "Don't ask to ask. Don't ask if anyone's around. Just ask and wait."));
            break;
        }
        break;

        //HELP COMMANDS
        //case ".HELP":
        //        if (count($args) < 2)
        //        {
        //            cmd_send(say($chan, "Please specify a command you need help with. (For a list of commands, type .commands)"));
        //        }
        //        else
        //        {
        //            switch (strtoupper($args[1]))
        //            {
        //                case "NICK":
        //                    cmd_send(say($chan, "[NICK] - Changes the bot's nick. (.nick newnick) ADMIN ONLY"));
        //                    break;
        //                case "JOIN":
        //                    cmd_send(say($chan, "[JOIN] - Tells the bot to join the channel specified (.join #channel) ADMIN ONLY"));
        //                    break;
        //                case "PART":
        //                    cmd_send(say($chan, "[PART] - Tells the bot to leave the channel specified (.part #channel) ADMIN ONLY"));
        //                    break;
        //                case "TIME":
        //                    cmd_send(say($chan, "[TIME] - Tells the time."));
        //                    break;
        //                case "UNIXTIME":
        //                    cmd_send(say($chan, "[UNIXTIME] - Prints the time as a unix timestamp."));
        //                    break;
        //                    //case "NOOB":
        //                    //	cmd_send(say($chan, "[NOOB] - Gives info for beginners to Lua."));
        //                    //	break;
        //                    //case "PM":
        //                    //	cmd_send(say($chan, "[PM] - Reminds visitors not to PM ops or peons."));
        //                    //	break;
        //                case "QUIT":
        //                    cmd_send(say($chan, "[QUIT] - Tells the bot to go away. ADMIN ONLY"));
        //                    break;
        //                case "COMMANDS":
        //                    cmd_send(say($chan, "[COMMANDS] - Displays a list of commands."));
        //                    break;
        //                case "WEATHER":
        //                    cmd_send(say($chan, "[WEATHER] - Displays weather conditions for your zipcode in standard units. (.weather zipcode) (only US zipcodes supported atm)"));
        //                    break;
        //                case "WEATHERM":
        //                    cmd_send(say($chan, "[WEATHERM] - Displays weather conditions for your zipcode in metric units. (.weather zipcode) (only US zipcodes supported atm)"));
        //                    break;
        //            }
        //        }
        //        break;
    }

    if (!$is_pm) //channel-only
    {
        switch (strtoupper($args[0]))
        {

        }
    }
}

function url_title_grab($url)
{
    $not_echo = array("65.191.79.97", );

    if (($hostname != "Anders.lunix-lover.gamesurge") && (!strstr($con['buffer']['text'],
        "anders1.org")))
    {
        if (!strstr($con['buffer']['text'], "localhost"))
        {
            $max_url_len = 60;

            $url = explode(" ", $url);
            $title = get_title($url[0]);
            if (strlen($title) >= $max_url_len)
            {
                $title = trim(substr($title, 0, ($max_url_len - 5))) . ' ...';
            }

            if ($title)
            {
                $title = str_replace($not_echo, "", $title);
                $title = html_entity_decode($title, ENT_COMPAT, "UTF-8");
                cmd_send(say($chan, "Title: " . $title));
            }
            else
            {
                echo "Could not find the title.\n\r";
            }
        }
    }
}

function parse_buffer()
{
    static $names_complete;
    /*
    :username!~identd@hostname JOIN :#php
    :username!~identd@hostname PRIVMSG #PHP :action text
    :username!~identd@hostname command channel :text
    */

    global $con, $CONFIG, $channels;

    $buffer = $con['buffer']['all'];
    $buffer = explode(" ", $buffer, 6);

    /* Get username */
    $buffer['username'] = substr($buffer[0], 1, strpos($buffer['0'], "!") - 1);
    $user["name"] = $buffer['username'];

    /* Get the username or channel */
    global $chan_user;
    $chan_user = $buffer[2];

    /* Get identd */
    $posExcl = strpos($buffer[0], "!");
    $posAt = strpos($buffer[0], "@");
    $user['identd'] = substr($buffer[0], $posExcl + 1, $posAt - $posExcl - 1);
    $user['hostname'] = substr($buffer[0], strpos($buffer[0], "@") + 1);

    /* The user and the host, the whole shabang */
    $user['host'] = substr($buffer[0], 1);

    /* Isolate the command the user is sending from
    the "general" text that is sent to the channel
    This is  privmsg to the channel we are talking about.
    
    We also format $buffer['text'] so that it can be logged nicely.
    */
    switch (strtoupper($buffer[1]))
    {
        case "JOIN":
            $chan_user = ltrim($chan_user, ":");
            do_join($user, strtolower($chan_user));
            break;
        case "QUIT":
            $message = ltrim($con['buffer']['all'], ":");
            $message = substr($message, strpos($message, ":") + 1);
            do_quit($user, $message);
            break;
        case "NOTICE":
            do_notice($user, substr($buffer[3], 1));
            break;
        case "PART":
            $message = ltrim($con['buffer']['all'], ":");
            $message = substr($message, strpos($message, ":") + 1);
            do_part($user, strtolower($chan_user), $message);
            break;
        case "MODE":
            do_mode($user, $chan_user, ltrim($buffer[3], ":"));
            break;
        case "NICK":
            do_nick($user, substr($buffer[2], 1));
            break;
        case "PRIVMSG":
            $message = ltrim($con['buffer']['all'], ":");
            $message = substr($message, strpos($message, ":") + 1);
            do_privmsg($user, strtolower($buffer[2]), $message);
            break;
        case "353":
            $nick_list = explode(":", $con['buffer']['all']);
            $nick_chan = explode(" ", $nick_list[1]);
            $nick_chan = $nick_chan[4];
            $nick_list = explode(" ", $nick_list[2]);
            $nick_chan = strtolower($nick_chan);
            if ($names_complete)
            {
                // If $names_complete is TRUE then it's a new list
                // ortherwise, we simply want to keep adding users
                $channels[$nick_chan]['users'] = array();
            }
            add_users($nick_list, $nick_chan);
            $names_complete = false;
            break;
        case "366":
            $names_complete = true;
            break;
        case "KICK":
            $message = ltrim($con['buffer']['all'], ":");
            $message = substr($message, strpos($message, ":") + 1);
            do_kick($user, $buffer[3], strtolower($buffer[2]), $message);
            break;
    }
    $con['buffer'] = $buffer;
}

function say($channel, $message)
{
    return ('PRIVMSG ' . $channel . ' :' . $message);
}

function part($chan)
{
    global $channels;
    cmd_send("PART " . $chan);
    unset($channels[$chan]);
}

function notice($user, $msg, $tochan = false)
{
    if ($tochan == false && substr($user, 0, 1) == "#")
    {
        return false;
    }
    cmd_send('NOTICE ' . $user . ' :' . $msg);
}

function kick($nick, $chan, $message = false)
{
    global $CONFIG;

    if (is_op($CONFIG['nick'], $chan))
    {
        if ($message)
        {
            cmd_send("KICK $chan $nick");
        }
        else
        {
            cmd_send("KICK $chan $nick :$message");
        }
    }
    else
    {
        print ("CANT KICK NOT OPPED\n");
    }
}

function is_op($nick, $chan)
{
    global $channels;

    $nick = strtolower(trim($nick));

    if (!in_channel($chan, $nick))
    {
        echo ($nick . " notinchan " . $chan);
        return false;
    }

    if ($channels[$chan]['users'][$nick]['code'] == "@")
    {
        return true;
    }

    return false;
}

function get_title($url)
{
    $contents = file_get_contents($url, false, null, 0, 10240);
    $match = preg_match('@<title>(.*?)</title>@i', $contents, $matches);
    if ((!$match) || (!$contents))
    {
        return false;
    }
    else
    {
        return $matches[1];
    }
}

function check_access($user)
{
    global $acl;

    $access = file($acl) or die("Please provide an access list\n");
    for ($i = 0; $i < count($access); $i++)
    {
        $access[$i] = trim($access[$i]);
    }

    if (in_array($user['hostname'], $access))
    {
        return true;
    }
    else
    {
        return false;
    }
}

function get_weather($code, $units = "s")
{
    global $weather_cache;
    $minutes_passed = (time() - $weather_cache[$units][$code]["time"]) / 60;
    if (!isset($weather_cache[$units][$code]) || $minutes_passed > 15)
    {
        $weather = new Services_Weather();
        $wdc = $weather->service("Weatherdotcom");
		$wdc->setAccountData("1049959469", "07d78af0423039d5");
        $fc = $wdc->getWeather($code, $units);

        if ($weather->isError($fc))
        {
            return false;
        }
        else
        {
            if ($units == "m")
            {
                $output = "Location: " . $fc['station'] . ",  Temp: " . $fc['temperature'] .
                    "C, Feels like: " . $fc['feltTemperature'] . "C, Humidity: " . $fc['humidity'] .
                    "%, Conditions: " . $fc['condition'] . ", Wind: " . $fc['wind'] .
                    "kph, Visibility: " . $fc['visibility'] . "km, Updated: " . $fc['update'] .
                    " GMT";
            }
            elseif ($units == "s")
            {
                $output = "Location: " . $fc['station'] . ",  Temp: " . $fc['temperature'] .
                    "F, Feels like: " . $fc['feltTemperature'] . "F, Humidity: " . $fc['humidity'] .
                    "%, Conditions: " . $fc['condition'] . ", Wind: " . $fc['wind'] .
                    "mph, Visibility: " . $fc['visibility'] . " miles, Updated: " . $fc['update'] .
                    " GMT";
            }
            $weather_cache[$units][$code]["response"] = $output . " (cache)";
            $weather_cache[$units][$code]["time"] = time();
            return $output;
        }
    }
    else
    {
        return $weather_cache[$units][$code]["response"];
    }
}

function get_forecast($code, $days = 3, $units = "s")
{
	global $forecast_cache;
    $minutes_passed = (time() - $forecast_cache[$units][$code]["time"]) / 60;
    if (!isset($forecast_cache[$units][$code]) || $minutes_passed > 15)
    {
        $weather = new Services_Weather();
        $wdc = $weather->service("Weatherdotcom");
		$wdc->setAccountData("1049959469", "07d78af0423039d5");
        $fc = $wdc->getForecast($code, $days, $units);

        if ($weather->isError($fc))
        {
            //return false;
			print("error\n");
        }
        else
        {
            if ($units == "m")
            {
                $output = "Location: " . $wdc->getLocation($code);
				foreach($fc['days'] as $key => $day)
				{
					$output = $output . " " . "Day ".($key + 1).": " . $day['day']['condition'] . ", High: " . $day['temperatureHigh'] . "C, Low: " . $day['temperatureLow']."C";
				}
				
            }
            elseif ($units == "s")
            {
                $output = "Location: " . $wdc->getLocation($code);
				foreach($fc['days'] as $key => $day)
				{
					$output = $output . " " . "Day ".($key + 1).": " . $day['day']['condition'] . ", High: " . $day['temperatureHigh'] . "F, Low: " . $day['temperatureLow']."F";
				}
            }
            $forecast_cache[$units][$code]["response"] = $output . " (cache)";
            $forecast_cache[$units][$code]["time"] = time();
            return $output;
			print_r($wdc->getLocation($code));
        }
    }
    else
    {
        return $forecast_cache[$units][$code]["response"];
    }
}

function get_loc($string)
{
	$weather = new Services_Weather();
	$wdc = $weather->service("Weatherdotcom");
	$wdc->setAccountData("1049959469", "07d78af0423039d5");
	$loc = $wdc->searchLocation($string, true);
	
	if ($weather->isError($loc))
	{
		print("error\n".$loc->getMessage());
		return false;
	}
	return($loc);
}

function ctcp_catcher()
{
    global $con;
    global $uhost;

    $uhost = str_replace("~", "", $uhost);

    $ctcp = explode(" ", $con['buffer']['text']);
    $ctcp = $ctcp[0];

    if ($ctcp == "\1VERSION\1")
    {
        cmd_send(say($uhost, "\1VERSION EstavioBot:0.0:UrMom\1"));
    }
}

function func_find_client($search_text)
{
    $search = strip_tags($search_text);

    $client = simplexml_load_file("CLIENT_DUMP.xml");

    $headlines = $client->xpath('/CLIENT_DUMP/headline');

    foreach ($headlines as $headline)
    {
        $instances = $headline->xpath("instance");
        //print(nl2br(print_r($instances, true)));
        //print("<br><br>\n");
        foreach ($instances as $instance)
        {
            //$description = $instance->xpath('description');
            if (strtolower($instance['title']) == strtolower($search))
            {
                return "" . $instance['title'] . ": " . strip_tags($instance->description) .
                    " (" . $instance['url'] . ")";
            }
            else
            {
                //print($instance['title']);
                //print("<br>\n");
            }
            //print(nl2br(print_r($instance)));
            //print("<br><br>\n");
        }
    }
    return "Function not found";
}

function func_find_server($search_text)
{
    $search = strip_tags($search_text);

    $client = simplexml_load_file("SERVER_DUMP.xml");

    $headlines = $client->xpath('/SERVER_DUMP/headline');

    foreach ($headlines as $headline)
    {
        $instances = $headline->xpath("instance");
        //print(nl2br(print_r($instances, true)));
        //print("<br><br>\n");
        foreach ($instances as $instance)
        {
            //$description = $instance->xpath('description');
            if (strtolower($instance['title']) == strtolower($search))
            {
                return "" . $instance['title'] . ": " . strip_tags($instance->description) .
                    " (" . $instance['url'] . ")";
            }
            else
            {
                //print($instance['title']);
                //print("<br>\n");
            }
            //print(nl2br(print_r($instance)));
            //print("<br><br>\n");
        }
    }
    return "Function not found";
}

function action($chan, $text)
{

    return (say($chan, "\1ACTION " . $text . "\1"));

}

function check_config($config_array, $network)
{
    if (isset($config_array[$network]))
    {
        return true;
    }
    else
    {
        die("NOES " . $network . "\n" . $$network);
    }

}

function in_channel($chan, $nick = false)
{
    global $channels;

    if ($nick == false)
    {
        if (isset($channels[$chan]))
        {
            return true;
        }
    }
    else
    {
        $nick = trim(strtolower($nick));
        if (isset($channels[$chan]['users'][$nick]))
        {
            return true;
        }
    }
    return false;
}



function get_last_seen($nick)
{
    global $CONFIG;
    $net = $CONFIG['network'];
    $nick = strtolower($nick);

    $db = sqlite_open("bot_$net");

    $exist = sqlite_query($db,
        "SELECT name FROM sqlite_master WHERE type='table' AND name='lastseen'");
    if (sqlite_num_rows($exist) != 1)
    {
        //echo("get1\n");
        return false;
    }

    $result = sqlite_query($db, "SELECT * FROM lastseen WHERE nick='$nick'");
    if (sqlite_num_rows($result) > 0)
    {
        //echo("get2\n");
        $res = sqlite_fetch_array($result);
        //$res['nick'] = sqlite_udf_decode_binary($res['nick']);
        //$res['chan'] = sqlite_udf_decode_binary($res['chan']);
        //$res['message'] = sqlite_udf_decode_binary($res['message']);
        //$res['newnick'] = sqlite_udf_decode_binary($res['newnick']);

        return $res;
    }
    else
    {
        //echo("get3\n");
        return false;
    }
    var_dump(sqlite_error_string(sqlite_last_error($db)));

}

function set_last_seen($nick, $time, $action, $message, $newnick = null, $chan = null)
{
    global $CONFIG;
    $net = $CONFIG['network'];

    $nick = strtolower(sqlite_escape_string($nick));
    $message = sqlite_escape_string($message);
    $newnick = sqlite_escape_string($newnick);
    $chan = sqlite_escape_string($chan);

    $db = sqlite_open("bot_$net");

    $exist = sqlite_query($db,
        "SELECT name FROM sqlite_master WHERE type='table' AND name='lastseen'");
    if (sqlite_num_rows($exist) != 1)
    {
        sqlite_query($db, "CREATE TABLE [lastseen] (
		datetime INTEGER,
		nick VARCHAR PRIMARY KEY,
		action VARCHAR,
		message VARCHAR,
		newnick VARCHAR,
		channel VARCHAR
		)");
        //echo("set1\n");
    }

    $result = sqlite_query($db, "SELECT * FROM lastseen WHERE nick='$nick'");

    if (sqlite_num_rows($result) > 0)
    {
        if ($action == "nick")
        {
            echo ($newnick . "\n");
            sqlite_query($db, "UPDATE 'lastseen' SET datetime = '$time', action = '$action', 
				message = '$message', newnick = '$newnick' WHERE nick = '$nick'");
            //echo("set2\n");
        }
        elseif ($action == "quit")
        {
            sqlite_query($db, "UPDATE 'lastseen' SET datetime = $time, action = '$action', 
				message = '$message' WHERE nick = '$nick'");
            //echo("set3\n");
        }
        else
        {
            sqlite_query($db, "UPDATE 'lastseen' SET datetime = '$time', action = '$action', 
				message = '$message', 
				channel = '$chan' 
				WHERE nick = '$nick'");
            //echo("set4\n");
        }
    }
    else
    {
        if ($action == "nick")
        {
            sqlite_query($db, "INSERT INTO 'lastseen' (datetime, nick, action, newnick) 
					VALUES ('$time', '$nick', '$action', '$newnick')");
            //echo("set5\n");
        }
        elseif ($action == "quit")
        {
            sqlite_query($db, "INSERT INTO 'lastseen' (datetime, nick, action, message) 
					VALUES ('$time', '$nick', '$action', '$message')");
            //echo("set6\n");
        }
        else
        {
            sqlite_query($db, "INSERT INTO 'lastseen' (datetime, nick, action, message, channel) 
					VALUES ('$time', '$nick', '$action', '$message', '$chan')");
            //echo("set7\n");
        }
    }
    //var_dump(sqlite_error_string(sqlite_last_error($db)));
}

function eval_math($expression)
{
    $m = new EvalMath;
    // basic evaluation:
    if ($result = $m->evaluate($expression))
    {
        return $result;
    }
    else
    {
        return "Could not evaluate function: " . $m->last_error . "";
    }
}

function get_default_zip($nick)
{
    global $CONFIG;
    $net = $CONFIG['network'];

    $db = sqlite_open("bot_$net");
    $nick = strtolower($nick);

    $table_exists = sqlite_query($db,
        "SELECT name FROM sqlite_master WHERE type='table' AND name='default_zip'");
    if (sqlite_num_rows($table_exists) != 1)
    {
        //echo("get1\n");
        return false;
    }

    $result = sqlite_query($db, "SELECT * FROM default_zip WHERE nick='$nick'");
    if (sqlite_num_rows($result) > 0)
    {
        //echo("get2\n");
        $res = sqlite_fetch_array($result);
        $res = $res['zip'];

        return $res;
    }
    else
    {
        //echo("get3\n");
        return false;
    }
    //var_dump(sqlite_error_string(sqlite_last_error($db)));
}

function set_default_zip($nick, $zip)
{
    global $CONFIG;
    $net = $CONFIG['network'];

    $nick = strtolower(sqlite_escape_string($nick));

    $db = sqlite_open("bot_$net");

    $table_exists = sqlite_query($db,
        "SELECT name FROM sqlite_master WHERE type='table' AND name='default_zip'");
    if (sqlite_num_rows($table_exists) != 1)
    {
        sqlite_query($db, "CREATE TABLE [default_zip] (
		nick VARCHAR PRIMARY KEY,
		zip INTEGER,
		)");
        //echo("set1\n");
    }

    $result = sqlite_query($db, "SELECT * FROM default_zip WHERE nick='$nick'");

    if (sqlite_num_rows($result) > 0)
    {
        sqlite_query($db, "UPDATE 'default_zip' SET 'zip' = $zip WHERE nick = '$nick'");
    }
    else
    {
        sqlite_query($db, "INSERT INTO 'lastseen' (nick, zip) 
				VALUES ('$nick', '$zip')");
    }
    //var_dump(sqlite_error_string(sqlite_last_error($db)));
}

function add_user($nick, $chan)
{
    global $channels, $CONFIG;

    $nick = strtolower(trim($nick));

    $code = substr($nick, 0, 1);
    if (in_channel($chan, $nick))
    {
        print ($nick . " IS IN THIS CHAN\n");
    }
    else
    {
        $code_list = array("@", "+", "%", "&", "~");
        if (in_array($code, $code_list))
        {
            $nick = substr($nick, 1);
        }
        else
        {
            $code = "";
        }
        $channels[$chan]['users'][$nick] = array("code" => $code);
    }
}

function add_users($userlist, $chan)
{
    foreach ($userlist as $element)
    {
        add_user(trim($element, " "), $chan);
    }
}

function remove_user($nick, $chan)
{
    global $channels;

    $nick = strtolower(trim($nick));

    if (in_channel($chan, $nick))
    {
        unset($channels[$chan]['users'][$nick]);
        //print ("removed $nick from $chan\n");
    }
    else
    {
        //print ("ru: $nick IS NOT IN $chan\n");
    }

}

function remove_user_all($nick)
{
    global $channels;

    $nick = strtolower(trim($nick));

    foreach ($channels as $key => $val)
    {
        if (in_channel($key, $nick))
        {
            remove_user($nick, $key);

        }
        else
        {
            //print ("rua: " . $nick . " IS NOT IN " . $key . "\n");
        }
    }

}

function equals_chan($chan1, $chan2)
{


}

function equals_nick($nick1, $nick2)
{


}

function do_privmsg($user, $chan, $message)
{
    global $CONFIG;
    if (strtolower($chan) == strtolower($CONFIG['nick']))
    {
        //do PM stuff
        process_commands($message, $user, $user['name'], true);
        log_to_file("PM " . $user['name'] . ": " . $message);

        //cmd_send(say("Ortzinator", "PM[" . $user['name'] . "] " . $message));
    }
    else
    {
        //do channel stuff
        process_commands($message, $user, $chan);
        log_to_file($chan . " " . $user['name'] . ": " . $message);
    }

    $lookforurl = strstr($text, "http://");
    $pastes = array(
    "pastebin.ca",
    "pastebin.com",
    "pastebin.co.uk",
    "gmod.foszor.com/luabin",
    "pastebin.se",
    "nomorepasting.com",
    "mattsbox.co.uk/high6",
    "facepunchstudios.com/showpost.php",
    "pastebin.urfbownd.net",
    );

    foreach ($pastes as $paste)
    {
    if(strstr($text, $paste))
    {
    $foundpaste = true;
    echo"found\n";
    break 1;
    }
    }

    if ($lookforurl && !$foundpaste)
    {
    url_title_grab($lookforurl);
    }

    set_last_seen($user['name'], time(), "msg", $message, null, $chan);
    reset_timeout();
}
function do_join($user, $chan)
{
    global $CONFIG, $channels;
    if ($user['name'] == $CONFIG['nick'])
    {
        return;
    }

    //if ($chan == "#maya" && $CONFIG['network'] == "freenode")
    //    {
    //    	notice($user['name'], $user['name'].": ");
    //    }
    log_to_file("*JOINING " . $channel . ": " . $user["name"] . " ( " . $user["host"] .
        " )");
    add_user($user['name'], $chan);
    set_last_seen($user['name'], time(), "join", $message, null, $chan);
    reset_timeout();
}
function do_part($user, $chan, $message)
{
    global $CONFIG, $channels;
    if ($user['name'] == $CONFIG['nick'])
    {
        return;
    }
    log_to_file("*PARTING " . $chan . ": " . $user["name"] . " ( " . $user["host"] .
        " ) (" . $message . ")");
    remove_user($user['name'], $chan);
    set_last_seen($user['name'], time(), "part", $message, null, $chan);
    reset_timeout();
}
function do_quit($user, $message)
{
    global $CONFIG, $tallies;
    if ($user['name'] == $CONFIG['nick'])
    {
        return;
    }
    log_to_file("*QUIT: " . $user["name"] . " ( " . $user["host"] . ") (" . $message .
        ")");
    remove_user_all(trim($user['name']));
    set_last_seen($user['name'], time(), "quit", $message);
    reset_timeout();
}
function do_notice($user, $message)
{
    log_to_file("*NOTICE from" . $user['name'] . "(" . $user["host"] . "): " . $message);
    reset_timeout();
}
function do_mode($user, $channel, $mode)
{
    global $CONFIG;
    if ($user['name'] == $CONFIG['nick'])
    {
        return;
    }
    cmd_send("NAMES $channel");
    log_to_file("*MODE " . $channel . ": " . $user["name"] . " sets mode " . $mode);
    reset_timeout();
}
function do_nick($user, $newnick)
{
    global $channels;

    log_to_file("*NICK: " . $user["name"] . " is now known as " . $newnick);

    reset($channels);
    while (list($key, $val) = each($channels))
    {
        if ($result = array_search($user['name'], $val['users']))
        {
            $channels[$key]['users'][$result] = $newnick;
        }
    }
    set_last_seen($user['name'], time(), "nick", null, $newnick);
	set_last_seen($newnick, time(), "nickfrom", null, $user['name']);
    reset_timeout();
}
function do_kick($user_kicker, $nick, $chan, $message)
{
    global $CONFIG, $channels;

    log_to_file("*KICK: " . $user_kicker["name"] . " kicked " . $user_kicked["name"] .
        " from " . $chan);

    if ($user_kicked == $CONFIG['nick'])
    {
        unset($channels[$chan]);
        cmd_send("JOIN " . $chan);
        return;
    }
    remove_user($nick, $chan);
    set_last_seen($nick, time(), "kick", $message, null, $chan);
    reset_timeout();
}
?>