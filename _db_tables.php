<?php
$pdo=new PDO('sqlite:data/intake.sqlite');
$q=$pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table'");
foreach($q as $r){echo $r['name'],"\n";}
?>
