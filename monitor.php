<?php

/**********************************************************************************
* This program runs as a daemon to periodically check whether the Orion system is *
* running on all servers.  Orion gathers time-stamped system performance data from*
* servers and stores the data in the Graphite system for time-series display.  To *
* see if Orion is collecting data, we ask Graphite all data points in the last N  *
* with M time granularity.  This is done with a URL request to Graphite specifying*
* the server  group (e.g. Modern War iOS), the period N and the granularity M.    *
* These URLs are stored in file config.inc.  We have set n=4 hours and M=1 minute.*
* The URLs also specify the getting the total server errors for each group.       *
* If $fail_thresh fraction of data points in the 4 hour sample have value of 0, we*
* assume that Orion is now working for that server group.  All URLs in config.inc *
* are tested every $check_delay seconds.  The  result of the checks is stored in  *
* file ipc.  This is a string with either value SUCCESS (all URLs passed) or a    *
* comman seperated list of the server groups that failed.                         *
*                                                                                 *
* A listener port ($port=10000) responds to http requests by returning the value  *
* stored in file ipc.  A Pulse check named "Error Checks" unser service "Orion"   *
* pulses the listener port periodically.                                          *
*                                                                                 *
* The program runs on analytics-dev in dir /home/gene/orion_monitor.              *
**********************************************************************************/

require("config.inc");

$port = 10000;
$graphite_status = "xxx";
$check_delay = 600;
$fail_thresh = 0.95;

$pid = pcntl_fork();
if ($pid)
   {
   // Parent Proces
   $ts1 = time();
   date_default_timezone_set('America/Los_Angeles');

   while (1)
      {
      $graphite_status = 'SUCCESS';
      while (list($item,$data) = each($graphite_data))
         {
         $name = $graphite_data[$item]['name'];
         $url = $graphite_data[$item]['url'];
         $ch = curl_init($url);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         $results = curl_exec($ch); 
         $stat = $graphite_data[$item]['stat'] = process($name, $results); 
         if ($stat != "OK")
            {
            if ($graphite_status == "SUCCESS")
               $graphite_status = $stat;
            else
               $graphite_status = $graphite_status . "," . $stat;
            }   
         }
      reset ($graphite_data);  
      $fp = fopen("ipc", "w");
      fputs($fp, $graphite_status);
      fclose($fp);
//      print "Sleeping for $check_delay seconds\n";
      sleep($check_delay);
      }
   }
else
   {
   //Child--create listener port
   listener($port);
   }

function process($name, $results)
   {
   global $fail_thresh;

   $str = substr($results,1,strlen($results)-2);
    
   $arr = json_decode($str, true);
   $target = $arr['target'];
   $datapoints = $arr['datapoints'];
   $tot = 0;
   $zero_tot = 0;
   foreach($datapoints as $pt)
      {
      ++$tot;
      $x = $pt[0];
      $y = $pt[1];
      if (($x == 0) || (is_null($x)) || ($x == "0.0"))
         ++$zero_tot; 
      } 
   $ret = "OK";
#   if ($tot > 0)
#      if ($zero_tot/$tot > $fail_thresh)
   # Changed to fail only if all values are zero
   if ($zero_tot == $tot) 
         $ret = $name . "-FAIL";  
   return($ret);
   } 

function get_status()
   {
   global $graphite_status;
  
   $fp = fopen("ipc", "r");
   $stat = fgets($fp);
   fclose($fp);
   return($stat); 
   }

function listener($port)
   {
   $address = '0.0.0.0';
  

   if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false)
       {
       echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
       }

   if (socket_bind($sock, $address, $port) === false)
       {
       echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
       }

   if (socket_listen($sock, 5) === false)
       {
       echo "socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
       }

   do {
       if (($msgsock = socket_accept($sock)) === false)
           {
           echo "socket_accept() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
           break;
           }
       #print "Got connection\n";
       $stat = get_status(); 
       $len = strlen($stat) + 1; 
       $msg = "HTTP/1.0 200 OK\nContent-Type: text/html\nContent-Length: " . $len. "\n\n" . $stat . "\n";;
       socket_write($msgsock, $msg, strlen($msg));
       socket_close($msgsock);
       } while (true);
   }

?>
