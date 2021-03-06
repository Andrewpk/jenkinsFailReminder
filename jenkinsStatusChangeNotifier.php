<?php

date_default_timezone_set('America/Detroit');
require 'vendor/autoload.php';
$PROJROOT = dirname(__FILE__) . "/";
require_once $PROJROOT . "src/Utils.php";
require_once $PROJROOT . "src/Hipchat.php";

$CONFIG = getConfig($PROJROOT);

$channels = $CONFIG['channels'];
$outputString = "";

foreach ($channels as $channel) {
    $previousRun = getPreviousRunData($channel);

    $hipchat = new Hipchat($channel);

    $jenkinsserver = $channel['jenkinsserver'];
    echo "Connecting to " . $jenkinsserver . "\n";
    $jenkins = new \JenkinsApi\Jenkins($jenkinsserver);

    $viewToWatch = $channel['jenkinsview'];
    echo "Loading jobs for view " . $viewToWatch . "\n";

    $view = $jenkins->getView($viewToWatch);

    saveCurrentState($view, $channel);

    printRecentlyBrokenOrBackToSuccessBuildOutputText($view, $jenkins, $channel, $outputString, $previousRun, $hipchat);
}

function printRecentlyBrokenOrBackToSuccessBuildOutputText($view, \JenkinsApi\Jenkins $jenkins, $channel, $outputString, $previousRun, $hipchat)
{
    foreach ($view->allJobs as $job) {
        $color = "";
        if ($job->buildable == true) {
            echo "Checking job : " . $job->name . " with color: " . $job->color . "\n";
            if (strcmp($job->color, "red") == 0) {
                $succeededJob = $jenkins->getJob($job->name);
                if (hasColorChangedSinceLastRun($succeededJob, $previousRun)) {
                    if ($succeededJob->builds[0]->timestamp / 1000 < strtotime($channel['alerttimestring'])) {
                        $color = "red";
                        $outputString .= "<a target=\"_blank\" href = \"" . $job->url . "\">" . $job->name .
                            "</a> has failed to build. \n";
                    }
                }
            } else if (strcmp($job->color, "blue") == 0) {
                $succeededJob = $jenkins->getJob($job->name);
                if (hasColorChangedSinceLastRun($succeededJob, $previousRun)) {
                    if ($succeededJob->builds[0]->timestamp / 1000 < strtotime($channel['alerttimestring'])) {
                        $color = "green";
                        $outputString .= "<a target=\"_blank\" href = \"" . $job->url . "\">" . $job->name .
                            "</a> back to successful build\n";
                    }
                }
            }
        }
        else{
            echo "Skipping unbuildable: " . $job->name . "\n";
        }
        if (strlen($outputString) > 0) {
            $outputString = getGreeting() . "\n" . $outputString;
            $hipchat->postOutputWithColor($outputString, $color);
            $outputString = "";
        }

    }
}

function hasColorChangedSinceLastRun($job, $previousRun)
{
    if (count($previousRun) > 0) {
        foreach ($previousRun as $previousJob) {
            if (strcmp($previousJob->name, $job->name) == 0) {
                if (strcmp($previousJob->color, $job->color) == 0) {
                    return false;
                }

                //try to handle building states
                if(strcmp($job->color, "red")){
                    return true;
                }
                if(strcmp($job->color, "blue")){
                    return true;
                }
                return false;
            }
        }
    }

    //we have no previous, so assume it's not new
    return false;
}

function getPreviousRunData($channel)
{
    echo "Loading previous run file: " . $channel['persistedDataFile'] . "\n";
    $previousRun = null;
    if (file_exists($channel['persistedDataFile'])) {
        $previousRun = file_get_contents($channel['persistedDataFile']);
        if (strlen($previousRun) > 0) {
            $previousRun = json_decode($previousRun);
        }
    }
    return $previousRun;
}

function saveCurrentState($view, $channel)
{
    echo "Saving previous run file: " . $channel['persistedDataFile'] . "\n";

    if (count($view->allJobs) > 0) {
        file_put_contents($channel['persistedDataFile'], json_encode($view->allJobs));
    }
}