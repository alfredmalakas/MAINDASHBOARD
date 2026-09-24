<?php
require_once __DIR__.'/../../config/db.php'; require_once __DIR__.'/../../config/security.php'; requireRole('HR Administrator');
$pageTitle='Backup & Restore'; $message='';
$backupDir=__DIR__.'/../../storage/backups'; if(!is_dir($backupDir)) mkdir($backupDir,0750,true);
if(isset($_GET['download'])){ $name=basename($_GET['download']); $file=$backupDir.'/'.$name; if(is_file($file)){audit($pdo,'BACKUP_DOWNLOAD','system_backups',null,$name); header('Content-Type:application/sql'); header('Content-Disposition:attachment; filename="'.$name.'"'); readfile($file); exit;} }
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  if(isset($_POST['restore_backup'])){
    if(empty($_FILES['backup_file']['tmp_name']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
      $message='Please select a valid SQL backup file.';
    } else {
      $name=basename($_FILES['backup_file']['name']);
      if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='sql'){
        $message='Only .sql backup files are allowed.';
      } else {
        $tmp=$_FILES['backup_file']['tmp_name'];
        $mysql='C:\\xampp\\mysql\\bin\\mysql.exe';
        if(PHP_OS_FAMILY==='Windows' && is_file($mysql)){
          $cmd='"'.$mysql.'" --host='.escapeshellarg(DB_HOST).' --user='.escapeshellarg(DB_USER).' '.(DB_PASS!==''?'--password='.escapeshellarg(DB_PASS).' ':'').' '.escapeshellarg(DB_NAME).' < '.escapeshellarg($tmp);
          exec($cmd,$o,$code);
          if($code===0){ audit($pdo,'BACKUP_RESTORE','system_backups',null,$name); $message='Database restored successfully from the selected SQL backup.'; }
          else { $message='Restore failed. Check that the SQL file belongs to this HRIS database and that mysql.exe is available.'; }
        } else { $message='mysql.exe was not found. For XAMPP, verify C:\\xampp\\mysql\\bin\\mysql.exe exists.'; }
      }
    }
  } else {
    $name='hris_backup_'.date('Ymd_His').'.sql'; $file=$backupDir.'/'.$name; $mysqldump='C:\\xampp\\mysql\\bin\\mysqldump.exe';
    if(PHP_OS_FAMILY==='Windows' && is_file($mysqldump)){$cmd='"'.$mysqldump.'" --host='.escapeshellarg(DB_HOST).' --user='.escapeshellarg(DB_USER).' '.(DB_PASS!==''?'--password='.escapeshellarg(DB_PASS).' ':'').' '.escapeshellarg(DB_NAME).' > '.escapeshellarg($file); exec($cmd,$o,$code);} else {$code=1;}
    if($code===0 && is_file($file)){ $size=filesize($file); $s=$pdo->prepare('INSERT INTO system_backups(filename,file_size,created_by) VALUES(?,?,?)');$s->execute([$name,$size,$_SESSION['user_id']]);audit($pdo,'BACKUP_CREATE','system_backups',$pdo->lastInsertId(),$name);$message='Backup created successfully.';}else{$message='Automatic mysqldump is available when XAMPP mysqldump.exe is installed. Use phpMyAdmin export as a fallback.';}
  }
}
$backups=$pdo->query('SELECT * FROM system_backups ORDER BY created_at DESC LIMIT 20')->fetchAll(); include __DIR__.'/../../includes/header.php';
?>
<div class="card"><h2>Database Backup & Restore</h2><p>Create a SQL backup or restore an existing SQL backup. Restore replaces database data, so use a trusted backup only.</p><form method="post"><?= csrf_field() ?><button class="btn btn-primary">Create SQL Backup</button></form><hr><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="file" name="backup_file" accept=".sql" required><button class="btn btn-secondary" name="restore_backup" value="1">Restore SQL Backup</button></form><?php if($message): ?><div class="alert alert-info"><?= htmlspecialchars($message) ?></div><?php endif; ?></div><div class="card"><h3>Backup History</h3><div class="table-wrap"><table><thead><tr><th>File</th><th>Size</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach($backups as $b): ?><tr><td><?=htmlspecialchars($b['filename'])?></td><td><?=number_format($b['file_size']/1024,1)?> KB</td><td><?=htmlspecialchars($b['created_at'])?></td><td><a class="btn btn-secondary btn-sm" href="?download=<?=urlencode($b['filename'])?>">Download</a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
