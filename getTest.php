<?php
header("Access-Control-Allow-Origin: *");
include 'auth.php';

$array = ($_GET);
$test = explode('/', $array['width']);
$name = explode('.', $test[2]);
$name = $name[0];
$lead = $test[5];

(new ApiService())->getContacts($name,$lead);
