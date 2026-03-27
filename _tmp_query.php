<?php
$pdo=new PDO('sqlite:data/intake.sqlite');
$pdo->exec("CREATE TABLE IF NOT EXISTS sku_photos (id INTEGER PRIMARY KEY AUTOINCREMENT, sku_normalized TEXT NOT NULL, original_name TEXT NOT NULL, stored_name TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')));");
$stmt=$pdo->query('select id,sku_normalized,original_name,stored_name,file_size from sku_photos');
foreach($stmt as $row){echo $row['id']." | ".$row['sku_normalized']." | ".$row['original_name']." | ".$row['stored_name']." | ".$row['file_size']."\n";}
?>
