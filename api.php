<?php
declare(strict_types=1);
ob_start();
ini_set('display_errors','0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
register_shutdown_function(function(){
  $error=error_get_last();
  if($error && in_array($error['type'],[E_ERROR,E_CORE_ERROR,E_COMPILE_ERROR,E_PARSE],true)){
    if(ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'PHP error: '.$error['message']],JSON_UNESCAPED_UNICODE);
  }
});
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') { http_response_code(204); exit; }
try {
  $pdo=new PDO('mysql:host=127.0.0.1;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $pdo->exec("CREATE DATABASE IF NOT EXISTS kannganngan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("USE kannganngan");
  // ปรับฐานข้อมูลเก่าให้รองรับโครงสร้างปัจจุบัน
  try{$pdo->exec("ALTER TABLE accounts ADD COLUMN username VARCHAR(50) NULL UNIQUE");}catch(Throwable $ignore){}
  try{$pdo->exec("ALTER TABLE accounts ADD COLUMN password_hash CHAR(64) NULL");}catch(Throwable $ignore){}
  try{$pdo->exec("UPDATE accounts SET username=CASE display_name WHEN 'แก้ว' THEN 'kaew' WHEN 'อุ้ย' THEN 'auy' ELSE username END WHERE username IS NULL");}catch(Throwable $ignore){}
  try{$pdo->exec("UPDATE accounts SET password_hash=SHA2(COALESCE(username,display_name),256) WHERE password_hash IS NULL");}catch(Throwable $ignore){}
  try{$pdo->exec("ALTER TABLE tasks ADD COLUMN title VARCHAR(255) NULL");}catch(Throwable $ignore){}
  try{$pdo->exec("UPDATE tasks SET title=name WHERE title IS NULL");}catch(Throwable $ignore){}
  try{$pdo->exec("ALTER TABLE tasks MODIFY title VARCHAR(255) NOT NULL");}catch(Throwable $ignore){}
  try{$pdo->exec("ALTER TABLE tasks ADD COLUMN progress TINYINT UNSIGNED NOT NULL DEFAULT 0");}catch(Throwable $ignore){}
  try{$pdo->exec("ALTER TABLE tasks ADD COLUMN task_date DATE NULL");}catch(Throwable $ignore){}
  $pdo->exec("CREATE TABLE IF NOT EXISTS task_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id BIGINT UNSIGNED NOT NULL, item_text VARCHAR(500) NOT NULL, is_done TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_item_task_api FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("DELETE FROM task_items WHERE item_text='Array'");
  $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, display_name VARCHAR(50) NOT NULL UNIQUE, username VARCHAR(50) NOT NULL UNIQUE, password_hash CHAR(64) NOT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT, task_type ENUM('once','daily') NOT NULL DEFAULT 'once', task_date DATE NULL, account_id INT UNSIGNED NOT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0, is_done TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_task_account_api FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  try{$pdo->exec("ALTER TABLE tasks ADD COLUMN task_date DATE NULL AFTER task_type");}catch(Throwable $ignore){}
  $pdo->exec("CREATE TABLE IF NOT EXISTS task_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id BIGINT UNSIGNED NULL, title VARCHAR(255) NOT NULL, account_id INT UNSIGNED NOT NULL, action VARCHAR(50) NOT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_history_account_api FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS presentations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT, image_data LONGTEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("INSERT IGNORE INTO accounts (display_name,username,password_hash) VALUES ('แก้ว','kaew',SHA2('kaew',256)),('อุ้ย','auy',SHA2('auy',256))");
  $method=$_SERVER['REQUEST_METHOD'];
  $payload=json_decode(file_get_contents('php://input'),true)?:[];
  if($method==='POST' && ($payload['action']??'')==='login'){
    $q=$pdo->prepare('SELECT id,display_name,username,password_hash FROM accounts WHERE username=? LIMIT 1');$q->execute([trim((string)($payload['username']??''))]);$account=$q->fetch();
    if(!$account || !hash_equals($account['password_hash'],hash('sha256',(string)($payload['password']??'')))){http_response_code(401);out(['ok'=>false,'error'=>'รหัสผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);}
    $_SESSION['account_id']=(int)$account['id'];$_SESSION['display_name']=$account['display_name'];out(['ok'=>true,'user'=>['name'=>$account['display_name'],'username'=>$account['username']]]);
  }
  if($method==='POST' && ($payload['action']??'')==='logout'){session_destroy();out(['ok'=>true]);}
  if($method==='GET'){
    $accounts=$pdo->query('SELECT a.id,a.display_name AS name,COALESCE(ROUND(AVG(t.progress)),0) AS progress FROM accounts a LEFT JOIN tasks t ON t.account_id=a.id GROUP BY a.id,a.display_name ORDER BY a.id')->fetchAll();
    $tasks=$pdo->query("SELECT t.id,t.title,t.description,t.task_type AS type,t.task_date,t.progress,t.is_done AS done,a.display_name AS owner FROM tasks t JOIN accounts a ON a.id=t.account_id ORDER BY t.task_date IS NULL,t.task_date,t.created_at DESC")->fetchAll();
    $history=$pdo->query("SELECT t.id,t.title,t.progress,t.updated_at AS created_at,a.display_name AS owner,'เสร็จแล้ว' AS action FROM tasks t JOIN accounts a ON a.id=t.account_id WHERE t.is_done=1 ORDER BY t.updated_at DESC LIMIT 100")->fetchAll();
    $presentations=$pdo->query("SELECT id,title,description,image_data,created_at FROM presentations ORDER BY created_at DESC")->fetchAll();
    foreach($tasks as &$t){$t['id']=(int)$t['id'];$t['progress']=(int)$t['progress'];$t['done']=(bool)$t['done'];} unset($t);
    foreach($accounts as &$a)$a['progress']=(int)$a['progress']; unset($a);
    $itemsByTask=[];$itemRows=$pdo->query('SELECT id,task_id,item_text,is_done FROM task_items ORDER BY id')->fetchAll();foreach($itemRows as $item){$item['id']=(int)$item['id'];$item['task_id']=(int)$item['task_id'];$item['done']=(bool)$item['is_done'];unset($item['is_done']);$itemsByTask[$item['task_id']][]=$item;}foreach($tasks as &$task)$task['items']=$itemsByTask[$task['id']]??[];unset($task);
    out(['ok'=>true,'accounts'=>$accounts,'tasks'=>$tasks,'history'=>$history,'presentations'=>$presentations]);
  }
  if($method==='POST'){
    $p=$payload; $action=$p['action']??''; $pdo->beginTransaction();
    if($action==='update_item'){$q=$pdo->prepare('UPDATE task_items SET is_done=? WHERE id=?');$q->execute([!empty($p['done'])?1:0,(int)$p['id']]);$q=$pdo->prepare("UPDATE tasks t JOIN (SELECT task_id,ROUND(SUM(is_done)*100/COUNT(*)) AS pct FROM task_items WHERE task_id=(SELECT task_id FROM task_items WHERE id=?) GROUP BY task_id) x ON x.task_id=t.id SET t.progress=x.pct,t.is_done=IF(x.pct=100,1,0) WHERE t.id=x.task_id");$q->execute([(int)$p['id']]);$pdo->commit();out(['ok'=>true]);}
    if($action==='update_task_full'){$q=$pdo->prepare('SELECT id FROM accounts WHERE display_name=? LIMIT 1');$q->execute([$p['owner']??'แก้ว']);$aid=$q->fetchColumn();$done=($p['status']??'open')==='done'?1:0;$progress=$done?100:min(100,max(0,(int)($p['progress']??0)));$q=$pdo->prepare('UPDATE tasks SET title=?,description=?,task_type=?,task_date=?,account_id=?,progress=?,is_done=? WHERE id=?');$q->execute([trim($p['title']??''),$p['description']??'',($p['type']??'once')==='daily'?'daily':'once',!empty($p['task_date'])?$p['task_date']:null,$aid,$progress,$done,(int)$p['id']]);$q=$pdo->prepare('DELETE FROM task_items WHERE task_id=?');$q->execute([(int)$p['id']]);if(is_array($p['items']??null)){$q=$pdo->prepare('INSERT INTO task_items(task_id,item_text,is_done) VALUES(?,?,?)');foreach($p['items'] as $item){$text=trim((string)($item['text']??''));if($text!=='')$q->execute([(int)$p['id'],$text,!empty($item['done'])?1:0]);}}$pdo->commit();out(['ok'=>true]);}
    if($action==='delete_history'){$q=$pdo->prepare('DELETE FROM tasks WHERE id=?');$q->execute([(int)$p['id']]);$pdo->commit();out(['ok'=>true]);}
    if($action==='update_task'){$q=$pdo->prepare('SELECT id FROM accounts WHERE display_name=? LIMIT 1');$q->execute([$p['owner']??'แก้ว']);$aid=$q->fetchColumn();$q=$pdo->prepare('UPDATE tasks SET title=?,description=?,task_type=?,task_date=?,account_id=? WHERE id=?');$q->execute([trim($p['title']??''),$p['description']??'',($p['type']??'once')==='daily'?'daily':'once',!empty($p['task_date'])?$p['task_date']:null,$aid,(int)$p['id']]);$q=$pdo->prepare("INSERT INTO task_history(task_id,title,account_id,action,progress) SELECT id,title,account_id,'แก้ไขงาน',progress FROM tasks WHERE id=?");$q->execute([(int)$p['id']]);$pdo->commit();out(['ok'=>true]);}
    if($action==='create'){
      $q=$pdo->prepare('SELECT id FROM accounts WHERE display_name=?');$q->execute([$p['owner']??'แก้ว']);$aid=$q->fetchColumn();
      $isDone=($p['status']??'open')==='done'?1:0;$progress=$isDone?100:min(100,max(0,(int)($p['progress']??0)));
      $q=$pdo->prepare('INSERT INTO tasks(title,description,task_type,task_date,account_id,progress,is_done) VALUES(?,?,?,?,?,?,?)');$q->execute([trim($p['title']??''),$p['description']??'',($p['type']??'once')==='daily'?'daily':'once',!empty($p['task_date'])?$p['task_date']:null,$aid,$progress,$isDone]);$tid=$pdo->lastInsertId();
      $q=$pdo->prepare('INSERT INTO task_history(task_id,title,account_id,action,progress) VALUES(?,?,?,?,?)');$q->execute([$tid,$p['title'],$aid,'สร้างงาน',0]);
      if(is_array($p['items']??null)){$q=$pdo->prepare('INSERT INTO task_items(task_id,item_text) VALUES(?,?)');foreach($p['items'] as $item){$text=is_array($item)?($item['text']??''):$item;$text=trim((string)$text);if($text!==''&&$text!=='Array')$q->execute([$tid,$text]);}}
    } elseif($action==='update'){
      $q=$pdo->prepare('SELECT account_id,title FROM tasks WHERE id=?');$q->execute([(int)$p['id']]);$old=$q->fetch();$prog=min(100,max(0,(int)($p['progress']??0)));$done=$prog>=100?1:0;
      $q=$pdo->prepare('UPDATE tasks SET progress=?,is_done=? WHERE id=?');$q->execute([$prog,$done,(int)$p['id']]);$q=$pdo->prepare('INSERT INTO task_history(task_id,title,account_id,action,progress) VALUES(?,?,?,?,?)');$q->execute([$p['id'],$old['title'],$old['account_id'],$done?'ทำเสร็จแล้ว':'อัปเดตความคืบหน้า',$prog]);
    } elseif($action==='account_progress'){
      $q=$pdo->prepare('UPDATE accounts SET progress=? WHERE display_name=?');$q->execute([min(100,max(0,(int)$p['progress'])),$p['owner']]);
    } elseif($action==='delete'){$q=$pdo->prepare('DELETE FROM tasks WHERE id=?');$q->execute([(int)$p['id']]);}
    elseif($action==='create_presentation'){$q=$pdo->prepare('INSERT INTO presentations(title,description,image_data) VALUES(?,?,?)');$q->execute([trim($p['title']??''),$p['description']??'',!empty($p['image_data'])?$p['image_data']:null]);}
    elseif($action==='delete_presentation'){$q=$pdo->prepare('DELETE FROM presentations WHERE id=?');$q->execute([(int)$p['id']]);}
    else throw new Exception('ไม่พบคำสั่ง');
    $pdo->commit();out(['ok'=>true]);
  }
  http_response_code(405);out(['ok'=>false,'error'=>'method']);
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);out(['ok'=>false,'error'=>$e->getMessage()]);}
function out(array $x){
  if(ob_get_level()) ob_clean();
  echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
