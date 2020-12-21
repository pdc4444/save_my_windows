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

This script sometimes cannot save the correct command which means it will fail to reopen the saved window.
If this happens open the saved_windows.json file, find the window which didn't load and populate the Override Command with the correct command that will open the window.

*/

$longopts = ['save', 'load', 'help', 'review_saved::', 'review_current', 'close', 'load_file:'];
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
            case 'load_file':
                custom_load($value);
                break;
            case 'review_current':
                review();
                break;
            case 'review_saved':
                review($save_location, $value);
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
 * This function takes a directory path and loads that json object instead. Useful for people that have multiple saved desktops and want to load a different configuration.
 * 
 * @param $value - The file path to the saved_windows.json file that you wish to load
 */
function custom_load($value)
{
    if (file_exists($value) && strpos($value, '.json') !== FALSE) {
        load($value);
    }
}


/**
 * This function returns a list of currently open window ids vis wnckprop
 * 
 * @return array $windows - The list of currently open windows
 */
function getWindowList()
{
    $cmd = 'wnckprop --list';
    $results = my_shell_exec($cmd);
    $windows = [];
    if (!empty($results['stdout'])) {
        $result_array = explode("\n", $results['stdout']);
        foreach ($result_array as $line) {
            $line_array = explode(":", $line);
            if (!empty($line_array[0])){
                $windows[] = $line_array[0];
            }
        }
    }
    return $windows;
}

/**
 * This function compares the currently open windows with a previous list of open windows and returns the difference
 * 
 * @param array $previous_windows - An array of previously opened windows
 * @return array $diff - An array of the differences found
 */
function getWindowDiff($previous_windows)
{
    $current_windows = getWindowList();
    $diff = array_diff($current_windows, $previous_windows);
    return $diff;
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

    //Set the custom xprop for the previous window data if it does not exist (compatibility for older saved window configurations)
    $xprop_counter = 0;
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        if (!isset($previous_window_data['Custom xprop'])) {
            $previous_windows[$previous_window_id]['Custom xprop'] = $xprop_counter;
        }
        $xprop_counter++;
    }

    //Get all the WM_CLASS values from $previous_windows and find the windows with duplicate WM_CLASS
    $wm_classes = array_column($previous_windows, 'WM_CLASS');
    $class_count = array_count_values($wm_classes);
    $duplicate_wm_classes = [];
    foreach ($class_count as $class => $class_occurences) {
        if ($class_occurences > 1) {
            $duplicate_wm_classes[] = $class; 
        }
    }

    $notated_windows = [];
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        if (in_array($previous_window_data['WM_CLASS'], $duplicate_wm_classes)) {
            if (!empty($previous_window_data['Override Command'])) {
                exec($previous_window_data['Override Command'] . ' > /dev/null 2>&1 &');    
            } else {
                exec($previous_window_data['Process Name'] . ' > /dev/null 2>&1 &');
            }
            $not_yet_loaded = true;
            $sleep_time = 0;
            $sleep_increment = 500000;
            if (isset($previous_window_data['Window Warmup Delay']) && !empty($previous_window_data['Window Warmup Delay'])) {
                sleep(intval($previous_window_data['Window Warmup Delay']));
            }
            while ($not_yet_loaded) {
                usleep($sleep_time);
                $new_windows = getWindowDiff($notated_windows);
                if(!empty($new_windows)) {
                    $new_window = current($new_windows);
                    $notated_windows[] = $new_window;
                    $not_yet_loaded = false;
                }
                $sleep_time += $sleep_increment;
            }
    
            //Set the custom xprop one window at a time for windows that have duplicate WM_CLASS
            $cmd = 'xprop -id ' . $new_window . ' -format custom_xprop 8s -set custom_xprop "' . $previous_window_data['Custom xprop'] . '"';
            exec($cmd);
        }
    }

    //Open each unique window, use the override command if it exists
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        if (!in_array($previous_window_data['WM_CLASS'], $duplicate_wm_classes)) {
            if (!empty($previous_window_data['Override Command'])) {
                exec($previous_window_data['Override Command'] . ' > /dev/null 2>&1 &');    
            } else {
                exec($previous_window_data['Process Name'] . ' > /dev/null 2>&1 &');
            }
        }
    }

    $desktops = array_column($previous_windows, 'Desktop');
    $desktops = array_unique($desktops);
    sort($desktops);
    sleep(10); //Give time for the new windows to all load
    $new_windows = compileWindows();

    //Ensure that the freshly opened windows register as not having an xprop assigned. The xprop will persist from applications that close their window but stay open in the background (like Discord)
    foreach ($new_windows as $new_window_id => $new_window_data) {
        if (isset($new_window_data['Custom xprop']) && !in_array($new_window_data['WM_CLASS'], $duplicate_wm_classes)) {
            unset($new_windows[$new_window_id]['Custom xprop']);
        }
    }

    //Set the custom xprop on each window, this is needed to tell windows apart that have the same WM_CLASS. Allows the script to open multiple windows of the same type.
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        foreach ($new_windows as $new_window_id => $new_window_data) {
            $cmd = 'xprop -id ' . $new_window_id . ' -format custom_xprop 8s -set custom_xprop "' . $previous_window_data['Custom xprop'] . '"';
            if (!empty($previous_window_data['Override Command']) && $new_window_data['Process Name'] == $previous_window_data['Override Command'] && !isset($new_window_data['Custom xprop'])) {
                $new_windows[$new_window_id]['Custom xprop'] = $previous_window_data['Custom xprop'];
                exec($cmd);
                continue 2;
            } else if ($new_window_data['Process Name'] == $previous_window_data['Process Name'] && !isset($new_window_data['Custom xprop'])) {
                $new_windows[$new_window_id]['Custom xprop'] = $previous_window_data['Custom xprop'];
                exec($cmd);
                continue 2;
            } else if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS'] && !isset($new_window_data['Custom xprop'])) {
                $new_windows[$new_window_id]['Custom xprop'] = $previous_window_data['Custom xprop'];
                exec($cmd);
                continue 2;
            }
        }
    }

    $new_windows = compileWindows();
    //Set all new windows to 1, 1 to resize without an issue
    foreach ($new_windows as $new_window_id => $new_window_data) {
        $cmd = 'wmctrl -i -r ' . $new_window_id . ' -e "0,1,1,0,0"';
        exec($cmd);
    }

    //Set height and width
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        foreach ($new_windows as $new_window_id => $new_window_data) {
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS'] && $new_window_data['Custom xprop'] == $previous_window_data['Custom xprop']) {
                $x = trim($previous_window_data['Xpos']);
                $y = trim($previous_window_data['Ypos']);
                $w = trim($previous_window_data['Width']);
                $h = trim($previous_window_data['Height']);
                $cmd = 'wnckprop --window ' . $new_window_id . ' --unmaximize --set-width=' . $w . ' --set-height=' . $h;
                exec($cmd);
            }
        }
    }

    //Match the current windows WM_CLASS to the saved WM_CLASS and attempt to move it into the correct location via wmctrl
    foreach ($previous_windows as $previous_window_id => $previous_window_data) {
        foreach ($new_windows as $new_window_id => $new_window_data) {
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS'] && $new_window_data['Custom xprop'] == $previous_window_data['Custom xprop']) {
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
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS'] && $new_window_data['Custom xprop'] == $previous_window_data['Custom xprop']) {
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
            if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS'] && $new_window_data['Custom xprop'] == $previous_window_data['Custom xprop']) {
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
                    if ($new_window_data['WM_CLASS'] == $previous_window_data['WM_CLASS'] && $new_window_data['Custom xprop'] == $previous_window_data['Custom xprop']) {
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
 * @param mixed $user_specificed_location - If both this and $save_location is not false, we will load the windows from the user passed file path
 */
function review($save_location = FALSE, $user_specificed_location = FALSE)
{
    if ($save_location !== FALSE) {
        $review_file = ($user_specificed_location) ? $user_specificed_location : $save_location;
        if (file_exists($review_file) && strpos($review_file, '.json') !== FALSE) {
            $raw_data = file_get_contents($save_location);
            $windows = json_decode($raw_data, true);
        } else {
            exit("Unable to read the saved json file!\nCheck permissions, and / or verify that the path is correct.\n");
        }
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
    $windows = compileWindows(true);
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
    $text .= "\nNote: This script sometimes cannot save the correct command which means it will fail to reopen the saved window.";
    $text .= "\nIf this happens open the saved_windows.json file, find the window which didn't load and populate the Override Command with the correct command that will open the window.";
    $text .= "\nAdditionally, if you have saved windows that are two of the same thing they share the same WM_CLASS, these are opened first and one at a time to carefully notate which window is which so that the script knows where to place it.";
    $text .= "\nIf you have windows with similar WM_CLASS and they take a long time to warm up (such as a connection that has an initial popup that says connecting but ends in a different window), then you should tune the Window Warmup Delay setting in the json config.";
    $text .= "\nThe Window Warmup Delay accepts an integer which translates into how many seconds the script will wait after opening the window. You should tune this setting to the amount of time the window you're opening reaches it's ready state.";
    $text .= "\nFailure to do so will result in windows being misplaced or not opened. For clarity, this is ONLY an issue with windows that share WM_CLASS. Unique windows will not need this type of tuning.";
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
    $text .= "\n--load_file         The same as --load only you can specifcy a file path of the json configuration you want to load.";
    $text .= "\n                        Example: php x_window_maintainer.php --load_file=/path/to/saved_json_file.json";
    $text .= "\n--review_current    Prints an array to the command line that displays all the current window location information.";
    $text .= "\n--review_saved      Prints an array to the command line that displays all saved window location information.";
    $text .= "\n                    You can also specify a file path to review a specific json file.";
    $text .= "\n                        Example: php x_window_maintainer.php --review_saved=/path/to/saved_json_file.json";
    $text .= "\n--close             Closes all open windows.";
    $text .= "\n--help              Prints the extended help text.";
    echo $text . "\n";
}

/**
 * This function compiles the array of currently opened windows and attempts to predict the process name so that we may reopen it when load() is called.
 * Most of the information in this function comes from the wmctrl command.
 * 
 * @param boolean $saving - A flag to detect whether or not we are saving the current window configuration.
 * @return array $compiled_windows - A multidimensional array collected by window IDs with specific process and location information for each window.
 */
function compileWindows($saving = false)
{
    $cmd = 'wmctrl -lxpG';
    $result = my_shell_exec($cmd);
    $compiled_windows = [];
    $process_list = getProcessList();
    $flatpak_list = getFlatPaks();
    $custom_xprop = 0;
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

                //Get the custom xprop if it exists
                $cmd = 'xprop -id ' . $window_key . ' | grep custom_xprop';
                $results = my_shell_exec($cmd);
                if (!empty($results['stdout'])) {
                    $compiled_windows[$window_key]['Custom xprop'] = trim(str_replace('"','',explode(' = ',$results['stdout'])[1]));
                } else if ($saving === true) {
                    $compiled_windows[$window_key]['Custom xprop'] = $custom_xprop;
                    $custom_xprop++;
                }

                if (array_key_exists($line_array[2], $process_list) && strpos($process_list[$line_array[2]], '[kthreadd]') === FALSE) {
                    $compiled_windows[$window_key]['Process Name'] = $process_list[$line_array[2]];
                    $compiled_windows[$window_key]['PID'] = $line_array[2];
                } else {
                    $prediction = predictLikelyProcess($line_array[7], $flatpak_list);
                    $compiled_windows[$window_key]['Process Name'] = $prediction['Process Name'];
                    $compiled_windows[$window_key]['PID'] = $prediction['PID'];
                }
                $compiled_windows[$window_key]['Override Command'] = "";
                $compiled_windows[$window_key]['Window Warmup Delay'] = "";
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