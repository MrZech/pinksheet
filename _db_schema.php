<?php
$pdo=new PDO('sqlite:data/intake.sqlite');
$tables=['intake_items','sku_photos'];
foreach($tables as $t){
  echo "-- $t\n";
  $stmt=$pdo->query("PRAGMA table_info($t)");
  foreach($stmt as $row){echo $row['cid'],": ",$row['name']," ",$row['type'],"\n";}
}
?>
