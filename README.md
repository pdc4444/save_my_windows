# save_my_windows
A PHP Based script that saves and reloads windows into their correct locations.

Why PHP you ask? It's what I know the best and it's easy for me to create something quickly.

This script was made to save the locations of your current windows and reload them in their correct positions. It uses a few different tools to get the job done and most importantly it is not perfect.

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

NOTE: This script sometimes cannot save the correct command which means it will fail to reopen the saved window.
If this happens open the saved_windows.json file, find the window which didn't load and populate the Override Command with the correct command that will open the window.
Additionally, if you have saved windows that are two of the same thing they share the same WM_CLASS, these are opened first and one at a time to carefully notate which window is which so that the script knows where to place it.
If you have windows with similar WM_CLASS and they take a long time to warm up (such as a connection that has an initial popup that says connecting but ends in a different window), then you should tune the Window Warmup Delay setting in the json config.
The Window Warmup Delay accepts an integer which translates into how many seconds the script will wait after opening the window. You should tune this setting to the amount of time the window you're opening reaches it's ready state.
Failure to do so will result in windows being misplaced or not opened. For clarity, this is ONLY an issue with windows that share WM_CLASS. Unique windows will not need this type of tuning.