<?php

/*

Note: This script was made to save the locations of your current windows and reload them in their correct positions. It uses a few different tools to get the job done and most importantly it is not perfect.
This code was developed and tested on Pop OS 20.04 and is expected to work with any Ubuntu flavored distribution.

Installation instructions:
    1.) Clone this script to the location of your choosing
    2.) Install script dependencies:
    sudo apt-get update && sudo apt-get install wmctrl libwnck-3-dev x11-utils php-cli
    3.) Run the script and familiarize yourself with the commands available for use:
        php x_window_maintainer.php --help

To use this script, open the windows you'd like to save and put them in whatever location on your desktop. Then run this script with the save command.
Once the windows are stored in a saved file you can run the load command to reload all the windows that were captured with the save command.
It's recommended that you create a simple bash script that will call the load function upon login.

Example startup script:
#!/bin/bash
cd /path/to/script && php x_window_maintainer.php --close && php x_window_maintainer.php --load

This script has some limitations:
1.) Duplicate windows will end up on top of eachother because I don't yet have a good way of identifying and separating duplicates.
2.) This script sometimes cannot save the correct command which means it will fail to reopen the saved window.
        If this happens open the saved_windows.json file, find the window which didn't load and populate the Override Command with the correct command that will open the window.

*/

$longopts = ['save', 'load', 'help', 'review_saved', 'review_current', 'close'];
$cli_opts = getOpt('', $longopts);
$save_location = __DIR__ . DIRECTORY_SEPARATOR . 'saved_windows.json';

if (!empty($cli_opts)) {
    foreach ($cli_opts as $key => $value) {
        switch($key) {
            case 'save':
                save($save_location);
                break;
            case 'load':
                load($save_location);
                break;
            case 'review_current':
                review();
                break;
            case 'review_saved':
                review($save_location);
                break;
            case 'close':
                close();
                break;
            case 'help':
                extendedHelpText();
                break;
            default:
                help();
                break;
        }
        exit(); //Only one option allowed
    }
} else {
    help();
}


/**
 * This function loads the saved window locations and uses several tools to open each saved window and put them in their correct spot.
 * All of the window moving logic is located inside of here.
 * 
 * @param string $save_location - Where the saved json array exists on disk so we can load it for processing.
 */
function load($save_location)
{
    $raw_data = file_get_contents($save_location);
    $previous_windows = json_decode($raw_data, true);
    //Open each window, use the override command if it exists
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        if (!empty($previous_window_data['Override Command'])) {
            exec($previous_window_data['Override Command'] . ' > /dev/null 2>&1 &');    
        } else {
            exec($previous_window_data['Process Name'] . ' > /dev/null 2>&1 &');
        }
    }
    $desktops = array_column($previous_windows, 'Desktop');
    $desktops = array_unique($desktops);
    sort($desktops);
    sleep(10); //Give time for the new windows to all load
    $new_windows = compileWindows();

    //Set all new windows to 1, 1 to resize without an issue
    foreach ($new_windows as $new_window_id => $new_window_data) {
        $cmd = 'wmctrl -i -r ' . $new_window_id . ' -e "0,1,1,0,0"';
        exec($cmd);
    }

    //Set height and width
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        foreach ($new_windows as $new_window_id => $new_window_data) {
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS']) {
                $x = trim($previous_window_data['Xpos']);
                $y = trim($previous_window_data['Ypos']);
                $w = trim($previous_window_data['Width']);
                $h = trim($previous_window_data['Height']);
                $cmd = 'wnckprop --window ' . $new_window_id . ' --set-width=' . $w . ' --set-height=' . $h;
                exec($cmd);
            }
        }
    }

    //Match the current windows WM_CLASS to the saved WM_CLASS and attempt to move it into the correct location via wmctrl
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        foreach ($new_windows as $new_window_id => $new_window_data) {
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS']) {
                //Move the window to it's saved location // X, Y, W, H,
                $x = trim($previous_window_data['Xpos']);
                $y = trim($previous_window_data['Ypos']);
                $w = trim($previous_window_data['Width']);
                $h = trim($previous_window_data['Height']);
                $cmd = 'wmctrl -i -r ' . $new_window_id . ' -e "0,' . $x . ',' . $y . ',' . $w . ',' . $h . '"';
                exec($cmd);
            }
        }
    }

    //Use wnckprop to attempt moving the windows into the correct location if we find that the location is still incorrect
    $new_windows = compileWindows();
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        foreach ($new_windows as $new_window_id => $new_window_data) {
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS']) {
                $x = trim($previous_window_data['Xpos']);
                $y = trim($previous_window_data['Ypos']);
                if (trim($new_window_data['Xpos']) != $x || trim($new_window_data['Ypos']) != $y) {
                    $cmd = 'wnckprop --window ' . $new_window_id . ' --set-x=' . $x . ' --set-y=' . $y;
                    exec($cmd);
                }
            }
        }
    }

    //Loop through each window again and do a third and final more intensive pass to ensure the window is in the correct location using a computed offset via xprop and wnckprop
    $new_windows = compileWindows();
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        foreach ($new_windows as $new_window_id => $new_window_data) {
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS']) {
                $x = trim($previous_window_data['Xpos']);
                $y = trim($previous_window_data['Ypos']);
                $w = trim($previous_window_data['Width']);
                $h = trim($previous_window_data['Height']);
                if (trim($new_window_data['Xpos']) != $x || trim($new_window_data['Ypos']) != $y) {
                    $cmd = 'xprop -id ' . $new_window_id . ' | grep -i frame';
                    $new_results = my_shell_exec($cmd);
                    if (!empty($new_results['stdout'])) {
                        $coordinate_offsets = explode(',',explode(':', $new_results['stdout'])[0]);
                        $total_offset = array_sum($coordinate_offsets);
                        if ($x == 0) {
                            $y = $new_window_data['Ypos'] - ($total_offset - 1);
                        } else if ($y == 0) {
                            $x = $new_window_data['Xpos'] - ($total_offset - 1);
                        }
                        $cmd = 'wnckprop --window ' . $new_window_id . ' --set-x=' . $x . ' --set-y=' . $y;
                        exec($cmd);

                        $verify_windows = compileWindows();
                        if (trim($verify_windows[$new_window_id]['Xpos']) != $previous_window_data['Xpos'] || trim($verify_windows[$new_window_id]['Ypos']) != $previous_window_data['Ypos']) {
                            if ($x == 0) {
                                $y = $new_window_data['Ypos'] + ($total_offset + 1);
                            } else if ($y == 0) {
                                $x = $new_window_data['Xpos'] + ($total_offset + 1);
                            }
                            $cmd = 'wnckprop --window ' . $new_window_id . ' --set-x=' . $x . ' --set-y=' . $y;
                            exec($cmd);
                        }
                    }
                }
            }
        }
    }

    //Move the windows to their proper desktop workspaces
    foreach ($desktops as $desktop) {
        foreach ($previous_windows as $previous_window_id => $previous_window_data) {
            if ($previous_window_data['Desktop'] == $desktop) {
                foreach ($new_windows as $new_window_id => $new_window_data) {
                    if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS']) {
                        $cmd = 'wmctrl -i -r ' . $new_window_id . ' -t ' . $desktop;
                        exec($cmd);
                        unset($new_windows[$new_window_id]);
                        unset($previous_windows[$previous_window_id]);
                    }
                }
            }
        }
        sleep(1); // This is necessary to give the desktop environment time to make each new desktop as we're moving windows down the stack
    }
}

/**
 * This function simply closes all x windows.
 */
function close()
{
    $windows = compileWindows();
    foreach ($windows as $window_id => $window_data) {
        $cmd = 'wmctrl -i -c ' . $window_id;
        my_shell_exec($cmd);
    }
}

/**
 * This function prints an array to the cli for the user to review either the saved or current window positions
 * 
 * @param boolean $save_location - Used to determine if the user wants to review the saved window locations.
 */
function review($save_location = FALSE)
{
    if ($save_location !== FALSE) {
        $raw_data = file_get_contents($save_location);
        $windows = json_decode($raw_data, true);
    } else {
        $windows = compileWindows();
    }
    print_r($windows);
}

/**
 * This function saves the current window locations to the disk in human readable json format.
 * 
 * @param string $save_location - The location of the file we are saving the JSON array to on disk.
 */
function save($save_location)
{
    $windows = compileWindows();
    $data = json_encode($windows);
    $data = substr_replace($data,"{\n\n",0,1);
    $data = str_replace('{"',"{\n" . '"',$data);
    $data = str_replace('},',"},\n",$data);
    $data = str_replace(',',",\n    ",$data);
    $data = str_replace('}',"\n    }",$data);
    $data = str_replace("{\n" . '"',"{\n    " . '"',$data);
    $data = str_replace("{\n    " . '"',"{\n" . '"',$data);
    $data = str_replace("{\n" . '"',"{\n    " . '"',$data);
    $data = substr_replace($data,"\n}",(strlen($data) - 1),1);
    file_put_contents($save_location, $data);

    echo "Current x window locations captured and saved to disk:\n" . $save_location . "\n";
}

/**
 * This function prints out additional helpful text for the user.
 */
function extendedHelpText()
{
    $text = "\nThis script was made to save the locations of your current windows and reload them in their correct positions.";
    $text .= "\nIt uses a few different tools to get the job done and most importantly it is not perfect.";
    $text .= "\nEnsure these packages are installed: wmctrl, wnckprop, xprop, php";
    $text .= "\nTo use this script, open the windows you'd like to save and put them in whatever location on your desktop. Then run this script with the save command.";
    $text .= "\nOnce the windows are stored in a saved file you can run the load command to reload all the windows that were captured with the save command.";
    $text .= "\n";
    $text .= "\nThis script has some limitations:";
    $text .= "\n1.) Duplicate windows will end up on top of eachother because I don't yet have a good way of identifying and separating duplicates.";
    $text .= "\n2.) This script sometimes cannot save the correct command which means it will fail to reopen the saved window.";
    $text .= "\n         If this happens open the saved_windows.json file, find the window which didn't load and populate the Override Command with the correct command that will open the window.";
    echo $text . "\n";
    help();
}

/**
 * This function prints out command usage when the script is initiated incorrectly, or when extendedHelpText() is called.
 */
function help()
{
    $text  = "\nCommands:";
    $text .= "\n--save              Save the current open window locations";
    $text .= "\n--load              Closes all open windows and opens the saved windows and puts them into their proper spots.";
    $text .= "\n--review_current    Prints an array to the command line that displays all the current window location information.";
    $text .= "\n--review_saved      Prints an array to the command line that displays all saved window location information.";
    $text .= "\n--close             Closes all open windows.";
    $text .= "\n--help              Prints the extended help text.";
    echo $text . "\n";
}

/**
 * This function compiles the array of currently opened windows and attempts to predict the process name so that we may reopen it when load() is called.
 * Most of the information in this function comes from the wmctrl command.
 * 
 * @return array $compiled_windows - A multidimensional array collected by window IDs with specific process and location information for each window.
 */
function compileWindows()
{
    $cmd = 'wmctrl -lxpG';
    $result = my_shell_exec($cmd);
    $compiled_windows = [];
    $process_list = getProcessList();
    $flatpak_list = getFlatPaks();
    if (empty($result['stderr'])) {
        $raw_list = explode("\n", $result['stdout']);
        foreach ($raw_list as $key => $line) {
            if (!empty($line)) {
                $line = preg_replace('/\s+/', ' ', $line);
                $line_array = explode(' ', $line);
                $window_key = hexdec(str_replace('0x','', $line_array[0]));
                $compiled_windows[$window_key]['Desktop'] = $line_array[1];
                $window_position = getGeometry($window_key);
                $compiled_windows[$window_key]['Xpos'] = $window_position['Xpos'];
                $compiled_windows[$window_key]['Ypos'] = $window_position['Ypos'];
                $compiled_windows[$window_key]['Width'] = $line_array[5];
                $compiled_windows[$window_key]['Height'] = $line_array[6];
                $compiled_windows[$window_key]['WM_CLASS'] = $line_array[7];
                if (array_key_exists($line_array[2], $process_list) && strpos($process_list[$line_array[2]], '[kthreadd]') === FALSE) {
                    $compiled_windows[$window_key]['Process Name'] = $process_list[$line_array[2]];
                    $compiled_windows[$window_key]['PID'] = $line_array[2];
                } else {
                    $prediction = predictLikelyProcess($line_array[7], $flatpak_list);
                    $compiled_windows[$window_key]['Process Name'] = $prediction['Process Name'];
                    $compiled_windows[$window_key]['PID'] = $prediction['PID'];
                }
                $compiled_windows[$window_key]['Override Command'] = "";
                foreach ($compiled_windows[$window_key] as $the_window_array_key => $the_window_array_value) {
                    $compiled_windows[$window_key][$the_window_array_key] = trim($the_window_array_value);
                }
            }
        }
    }
    return $compiled_windows;
}

/**
 * This funciton takes the $window_id and uses wnckprop to get the X and Y position of the window on screen
 * 
 * @param string $window_id - A string that represents the window_id
 * @return array $geometry - An array containing the x and y positions of the window_id
 */
function getGeometry($window_id)
{
    $cmd = 'wnckprop --window ' . $window_id . ' | grep -i geometry';
    $result = my_shell_exec($cmd);
    $geometry = [];
    $geometry['Xpos'] = '';
    $geometry['Ypos'] = '';
    if (!empty($result['stdout'])) {
        $dimensions = explode(':', $result['stdout'])[1];
        $geometry['Xpos'] = explode(',', $dimensions)[0];
        $geometry['Ypos'] = explode(',', $dimensions)[1];
    }
    return $geometry;
}

/**
 * This function gets the list of currently installed flatpaks and returns an array of named flatpaks and their application id.
 * 
 * @return array $flatpaks - An array like so: ['flatpak1' => 'flatpak1_application_id', 'flatpack2' => 'application_id', ...]
 */
function getFlatPaks()
{
    $cmd = 'flatpak list';
    $result = my_shell_exec($cmd);
    $flatpaks = [];
    if (!empty($result['stdout'])) {
        $list = explode("\n", $result['stdout']);
        foreach ($list as $line) {
            if (!empty($line))  {
                $line = preg_replace('/\b\ \b/', '_****_', $line);
                $line = preg_replace('/\s+/', ' ', $line);
                $line_array = explode(' ', $line);
                $name = str_replace('_****_', ' ', $line_array[0]);
                $flatpaks[$name] = $line_array[1];
            }
        }
    }
    return $flatpaks;
}

/**
 * This function takes a search term and looks through the flatpak list to see if we have a flatpak installed for it.
 * It's used to help populate the Process Name part of the array produced from compileWindows.
 * 
 * @param string $search_term - A string that we are searching for in the flatpak_list
 * @param array $flatpak_list - An array of the flatpaks compiled from getFlatPaks()
 * @return string $prediction - If a flatpak was found a flatpak run command is concatenated and returned as the prediction
 */
function predictLikelyProcess($search_term, $flatpak_list)
{
    $search_term = explode('.', $search_term)[0];
    $cmd = "ps -ef | grep -i '" . $search_term . "' | grep -v grep";
    $result = my_shell_exec($cmd);
    $prediction = [];
    $prediction['PID'] = '';
    $prediction['Process Name'] = '';
    if (!empty($result['stdout'])) {
        $line = explode("\n", $result['stdout'])[0];
        $line = preg_replace('/\s+/', ' ', $line);
        $line_array = explode(' ', $line);
        $prediction['PID'] = $line_array[1];
        $prediction['Process Name'] = explode($line_array[6], $line)[1];
    }

    if (strpos($prediction['Process Name'], 'bwrap') !== FALSE) {
        foreach ($flatpak_list as $flatpak_name => $flatpak_url) {
            if (strpos(strtolower($flatpak_name), strtolower($search_term)) !== FALSE || strpos(strtolower($flatpak_url), strtolower($search_term)) !== FALSE) {
                $prediction['Process Name'] = 'flatpak run ' . $flatpak_url;
            }
        }
    }
    return $prediction;
}

/**
 * This funtion gets a list of all the active running processes and returns a tidy array of whats happening
 * 
 * @return array $process_list - An array of the actively running processes on the computer. ['PID' => 'process_command', ...]
 */
function getProcessList()
{
    $cmd = 'ps -ef';
    $results = my_shell_exec($cmd);
    $process_list = [];
    if (!empty($results['stdout'])) {
        $result_array = explode("\n", $results['stdout']);
        foreach ($result_array as $key => $line) {
            if ($key !== 0 && !empty($line)) {
                $line = preg_replace('/\s+/', ' ', $line);
                $line_array = explode(' ', $line);
                $process_list[$line_array[1]] = explode($line_array[6], $line)[1];
            }
        }
        
    }
    return $process_list;
}

/**
 * Allows a shell command via a new process and captures the stdout and stderr for examination
 * 
 * @param string $cmd - The command to be run
 * @return array The result of the command run for both stdout and stderr
 */
function my_shell_exec($cmd) 
{
    $proc = proc_open($cmd,[
        1 => ['pipe','w'],
        2 => ['pipe','w'],
        ],$pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    return ['stdout' => $stdout, 'stderr' => $stderr];
}