<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=require_login();ensure_lead_schema();$manager=can_view_all_sales_financials($user);
if(!$manager&&!sales_partner_ready($user)){http_response_code(403);exit('Τα εταιρικά αρχεία διατίθενται μετά την έγκριση συνεργάτη.');}
db()->exec("CREATE TABLE IF NOT EXISTS sales_toolkit_files(id CHAR(36) PRIMARY KEY,title VARCHAR(180) NOT NULL,version_label VARCHAR(50) NOT NULL,file_name VARCHAR(180) NOT NULL,sha256 CHAR(64) NOT NULL,size_bytes INT NOT NULL,uploaded_by CHAR(36) NOT NULL,published_by CHAR(36) NULL,published_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
db()->exec("CREATE TABLE IF NOT EXISTS sales_toolkit_file_parts(file_id CHAR(36) NOT NULL,part_no INT NOT NULL,content MEDIUMBLOB NOT NULL,PRIMARY KEY(file_id,part_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if(isset($_GET['download'])){
    $id=is_string($_GET['download'])?$_GET['download']:'';$q=db()->prepare('SELECT * FROM sales_toolkit_files WHERE id=?'.($manager?'':' AND published_at IS NOT NULL'));$q->execute([$id]);$file=$q->fetch();
    if(!$file){http_response_code(404);exit('Δεν βρέθηκε αρχείο.');}
    header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','_',$file['file_name']).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header("Content-Security-Policy: sandbox");header('Content-Length: '.$file['size_bytes']);
    $q=db()->prepare('SELECT content FROM sales_toolkit_file_parts WHERE file_id=? ORDER BY part_no');$q->execute([$id]);while($part=$q->fetch())echo $part['content'];exit;
}
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();try{
        sales_require_manager($user);personnel_reauth($user,$_POST);$action=sales_text($_POST,'action',1,20);$pdo=db();
        if($action==='upload'){
            $title=sales_text($_POST,'title',5,180);$version=sales_text($_POST,'version_label',1,50);$f=$_FILES['file']??[];$name=basename((string)($f['name']??''));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            if(($f['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']??'')||($f['size']??0)<1||$f['size']>32*1024*1024||!in_array($ext,['pdf','pptx','png','jpg','jpeg','html'],true)||strlen($name)>180)throw new InvalidArgumentException('Επιλέξτε PDF, PPTX, PNG, JPG ή HTML υπογραφής έως 32 MB. Ελέγξτε και το όριο upload του hosting.');
            $bytes=file_get_contents($f['tmp_name']);if($bytes===false)throw new InvalidArgumentException('Δεν διαβάστηκε το αρχείο.');
            if(($ext==='pdf'&&!str_starts_with($bytes,'%PDF-'))||($ext==='pptx'&&!str_starts_with($bytes,"PK\x03\x04"))||(in_array($ext,['png','jpg','jpeg'],true)&&getimagesizefromstring($bytes)===false))throw new InvalidArgumentException('Το περιεχόμενο δεν αντιστοιχεί στον τύπο αρχείου.');
            $id=uuid_v4();$pdo->beginTransaction();
            $pdo->prepare('INSERT INTO sales_toolkit_files(id,title,version_label,file_name,sha256,size_bytes,uploaded_by) VALUES(?,?,?,?,?,?,?)')->execute([$id,$title,$version,$name,hash('sha256',$bytes),strlen($bytes),$user['id']]);
            $q=$pdo->prepare('INSERT INTO sales_toolkit_file_parts(file_id,part_no,content) VALUES(?,?,?)');foreach(str_split($bytes,262144) as $n=>$chunk)$q->execute([$id,$n,$chunk]);
            sales_portal_event($user,'toolkit_file',$id,'draft_uploaded',['sha256'=>hash('sha256',$bytes)]);$pdo->commit();
        }elseif($action==='publish'){
            if(($_POST['reviewed']??'')!=='1')throw new InvalidArgumentException('Προηγείται έλεγχος αρχείου.');$id=sales_text($_POST,'id',1,36);$pdo->beginTransaction();
            $q=$pdo->prepare('SELECT id FROM sales_toolkit_files WHERE id=? AND published_at IS NULL FOR UPDATE');$q->execute([$id]);if(!$q->fetch())throw new InvalidArgumentException('Το αρχείο δεν είναι προσχέδιο.');
            $pdo->prepare('UPDATE sales_toolkit_files SET published_at=NOW(),published_by=? WHERE id=?')->execute([$user['id'],$id]);sales_portal_event($user,'toolkit_file',$id,'published',[]);$pdo->commit();
        }else throw new InvalidArgumentException('Μη έγκυρη ενέργεια.');
        flash('success','Η έκδοση αποθηκεύτηκε χωρίς αντικατάσταση παλιού αρχείου.');redirect_to('toolkit-files.php');
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error=$e instanceof InvalidArgumentException?$e->getMessage():'Η ενέργεια δεν ολοκληρώθηκε.';}
}
render_header('Εγκεκριμένα αρχεία πωλήσεων',$user);
?><section class="card form-section"><h1>Αρχεία πωλήσεων</h1><p>Παρουσιάσεις GR/EN, λογότυπα, υπογραφές email και βοηθήματα. Κάθε νέα έκδοση ανεβαίνει ως προσχέδιο και χρειάζεται χωριστή δημοσίευση. Τα αρχεία δίνονται για λήψη, όχι ως εκτελέσιμο περιεχόμενο μέσα στο CRM.</p><?php if($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>
<?php foreach(db()->query('SELECT * FROM sales_toolkit_files'.($manager?'':' WHERE published_at IS NOT NULL').' ORDER BY created_at DESC,id DESC')->fetchAll() as $f): ?><article class="card"><h2><?= e($f['title'].' · '.$f['version_label']) ?></h2><p><?= $f['published_at']?'Δημοσιευμένο '.$f['published_at']:'Προσχέδιο — μόνο διοίκηση' ?> · <?= e($f['file_name']) ?></p><a class="button" href="<?= e(crm_url('toolkit-files.php?download='.$f['id'])) ?>">Λήψη για έλεγχο / χρήση</a><?php if($manager&&!$f['published_at']): ?><form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= e($f['id']) ?>"><label class="checkbox-row"><input type="checkbox" name="reviewed" value="1" required> Έλεγξα το αρχείο και το εγκρίνω για εταιρική χρήση.</label><label>Ο κωδικός μου<input type="password" name="actor_password" required autocomplete="current-password"></label><button class="button">Δημοσίευση συγκεκριμένης έκδοσης</button></form><?php endif; ?></article><?php endforeach; ?></section>
<?php if($manager): ?><form method="post" enctype="multipart/form-data" class="card form-section stack"><?= csrf_field() ?><input type="hidden" name="action" value="upload"><h2>Νέα έκδοση — προσχέδιο</h2><label>Τίτλος<input name="title" minlength="5" maxlength="180" required></label><label>Έκδοση / γλώσσα<input name="version_label" maxlength="50" required></label><label>Αρχείο<input type="file" name="file" accept=".pdf,.pptx,.png,.jpg,.jpeg,.html" required></label><label>Ο κωδικός μου<input type="password" name="actor_password" required autocomplete="current-password"></label><button class="button primary">Αποθήκευση για έλεγχο</button></form><?php endif; render_footer(); ?>
