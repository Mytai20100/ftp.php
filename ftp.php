<?php
session_start(); error_reporting(0);

if (isset($_GET['theme'])) { setcookie('theme',$_GET['theme'],time()+31536000,'/'); header('Location: '.$_SERVER['PHP_SELF']); exit; }
if (isset($_GET['logout'])) { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }

$theme = $_COOKIE['theme'] ?? 'dark';

function fmtSize($b){ if($b<0)return'-'; foreach(['B','KB','MB','GB','TB'] as $u){if($b<1024)return round($b,1)." $u"; $b/=1024;} }
function fmtDate($t){ return $t?date('Y-m-d H:i',$t):'-'; }
function joinPath($a,$b){ if($b==='..'){ $p=explode('/',rtrim($a,'/')); array_pop($p); return implode('/',$p)?:'/'; } return rtrim($a,'/').'/'.$b; }
function crumbs($c){ $o=[['/','root']]; $p=''; foreach(array_filter(explode('/',$c)) as $x){ $p.='/'.$x; $o[]=[$p,$x]; } return $o; }
function esc($s){ return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }

function ftpConn(){
    $c=@ftp_connect($_SESSION['host'],$_SESSION['port'],15); if(!$c)return false;
    if(!@ftp_login($c,$_SESSION['user'],$_SESSION['pass'])){ftp_close($c);return false;}
    ftp_pasv($c,true); return $c;
}
function sshConn(){
    if(!function_exists('ssh2_connect'))return false;
    $c=@ssh2_connect($_SESSION['host'],$_SESSION['port']); if(!$c)return false;
    if(!@ssh2_auth_password($c,$_SESSION['user'],$_SESSION['pass']))return false;
    return $c;
}
function sfUrl($sf,$p){ return "ssh2.sftp://$sf".($p[0]==='/'?$p:'/'.$p); }

function pingServer($host,$port){
    $t=microtime(true);
    $s=@fsockopen($host,$port,$e,$em,3);
    if($s){ fclose($s); return round((microtime(true)-$t)*1000); }
    return false;
}

function tryConnect(){
    if($_SESSION['proto']==='sftp'){
        $c=sshConn(); if(!$c){$_SESSION['err']='SFTP connection failed';return;}
        $sf=ssh2_sftp($c);
        if(!@opendir(sfUrl($sf,'/'))){$_SESSION['err']='SFTP auth failed';return;}
    } else {
        $c=ftpConn(); if(!$c){$_SESSION['err']='FTP connection failed';return;}
        $_SESSION['cwd']=@ftp_pwd($c)?:'/'; ftp_close($c);
    }
    $_SESSION['connected']=true; $_SESSION['cwd']=$_SESSION['cwd']??'/';
}

if(!isset($_SESSION['connected'])&&isset($_GET['u'],$_GET['p'])){
    $_SESSION['host']=trim($_GET['h']??'localhost');
    $_SESSION['proto']=strtolower($_GET['a']??'ftp');
    $_SESSION['port']=(int)($_GET['port']??($_SESSION['proto']==='sftp'?22:21));
    $_SESSION['user']=$_GET['u']; $_SESSION['pass']=$_GET['p'];
    tryConnect(); header('Location: '.$_SERVER['PHP_SELF']); exit;
}

$msg=''; $isErr=false;

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';

    if($act==='login'){
        $_SESSION['host']=trim($_POST['host']??'localhost');
        $_SESSION['proto']=strtolower($_POST['proto']??'ftp');
        $_SESSION['port']=(int)($_POST['port']??($_SESSION['proto']==='sftp'?22:21));
        $_SESSION['user']=$_POST['user']??''; $_SESSION['pass']=$_POST['pass']??'';
        tryConnect();
        if(!empty($_SESSION['err'])){$msg=$_SESSION['err'];$isErr=true;unset($_SESSION['err']);}
    }

    if($act==='cd'&&isset($_SESSION['connected'])) $_SESSION['cwd']=$_POST['dir']??'/';

    if($act==='mkdir'&&isset($_SESSION['connected'])){
        $path=joinPath($_SESSION['cwd'],basename(trim($_POST['name']??'')));
        if($_SESSION['proto']==='sftp'){$c=sshConn();$sf=ssh2_sftp($c);$ok=@ssh2_sftp_mkdir($sf,$path,0755,true);}
        else{$c=ftpConn();$ok=$c&&@ftp_mkdir($c,$path);if($c)ftp_close($c);}
        $msg=$ok?'Directory created':'mkdir failed'; $isErr=!$ok;
    }

    if($act==='delete'&&isset($_SESSION['connected'])){
        $path=joinPath($_SESSION['cwd'],$_POST['name']??'');
        if($_SESSION['proto']==='sftp'){$c=sshConn();$sf=ssh2_sftp($c);$ok=@ssh2_sftp_unlink($sf,$path)||@ssh2_sftp_rmdir($sf,$path);}
        else{$c=ftpConn();$ok=$c&&(@ftp_delete($c,$path)||@ftp_rmdir($c,$path));if($c)ftp_close($c);}
        $msg=$ok?'Deleted':'Delete failed'; $isErr=!$ok;
    }

    if($act==='rename'&&isset($_SESSION['connected'])){
        $old=joinPath($_SESSION['cwd'],$_POST['old']??'');
        $new=joinPath($_SESSION['cwd'],basename($_POST['new']??''));
        if($_SESSION['proto']==='sftp'){$c=sshConn();$sf=ssh2_sftp($c);$ok=@ssh2_sftp_rename($sf,$old,$new);}
        else{$c=ftpConn();$ok=$c&&@ftp_rename($c,$old,$new);if($c)ftp_close($c);}
        $msg=$ok?'Renamed':'Rename failed'; $isErr=!$ok;
    }

    if($act==='chmod'&&isset($_SESSION['connected'])){
        $path=joinPath($_SESSION['cwd'],$_POST['name']??''); $mode=octdec($_POST['mode']??'644');
        if($_SESSION['proto']==='sftp'){$c=sshConn();$sf=ssh2_sftp($c);$ok=@ssh2_sftp_chmod($sf,$path,$mode);}
        else{$c=ftpConn();$ok=$c&&(@ftp_chmod($c,$mode,$path)!==false);if($c)ftp_close($c);}
        $msg=$ok?'Permissions updated':'chmod failed'; $isErr=!$ok;
    }

    if($act==='upload'&&isset($_SESSION['connected'])&&!empty($_FILES['files'])){
        $ok=true; $names=[];
        foreach($_FILES['files']['tmp_name'] as $i=>$tmp){
            $n=basename($_FILES['files']['name'][$i]); $dest=joinPath($_SESSION['cwd'],$n); $names[]=$n;
            if($_SESSION['proto']==='sftp'){$c=sshConn();$sf=ssh2_sftp($c);$r=@ssh2_scp_send($c,$tmp,$dest,0644);if(!$r)$r=@file_put_contents(sfUrl($sf,$dest),file_get_contents($tmp))!==false;$ok=$ok&&$r;}
            else{$c=ftpConn();$r=$c&&@ftp_put($c,$dest,$tmp,FTP_BINARY);if($c)ftp_close($c);$ok=$ok&&$r;}
        }
        $msg=$ok?'Uploaded: '.implode(', ',$names):'Upload failed'; $isErr=!$ok;
    }

    if($act==='download'&&isset($_SESSION['connected'])){
        $n=$_POST['name']??''; $path=joinPath($_SESSION['cwd'],$n);
        $tmp=tempnam(sys_get_temp_dir(),'ftp'); $ok=false;
        if($_SESSION['proto']==='sftp'){$c=sshConn();$sf=ssh2_sftp($c);$ok=@ssh2_scp_recv($c,$path,$tmp);if(!$ok){$d=@file_get_contents(sfUrl($sf,$path));if($d!==false){file_put_contents($tmp,$d);$ok=true;}}}
        else{$c=ftpConn();$ok=$c&&@ftp_get($c,$tmp,$path,FTP_BINARY);if($c)ftp_close($c);}
        if($ok){header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.addslashes($n).'"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);exit;}
        @unlink($tmp); $msg='Download failed'; $isErr=true;
    }

    if($act==='newfile'&&isset($_SESSION['connected'])){
        $n=basename(trim($_POST['name']??'')); $path=joinPath($_SESSION['cwd'],$n);
        $tmp=tempnam(sys_get_temp_dir(),'ftp'); file_put_contents($tmp,'');
        if($_SESSION['proto']==='sftp'){$c=sshConn();$sf=ssh2_sftp($c);$ok=@ssh2_scp_send($c,$tmp,$path,0644);if(!$ok)$ok=@file_put_contents(sfUrl($sf,$path),'')!==false;}
        else{$c=ftpConn();$ok=$c&&@ftp_put($c,$path,$tmp,FTP_BINARY);if($c)ftp_close($c);}
        @unlink($tmp); $msg=$ok?'File created':'Create failed'; $isErr=!$ok;
    }
}

$files=[]; $cwd=$_SESSION['cwd']??'/';
$ping=null; $pingErr=false;

if(isset($_SESSION['connected'])){
    $pingMs=pingServer($_SESSION['host'],$_SESSION['port']);
    $ping=$pingMs!==false?$pingMs.'ms':'timeout';
    $pingErr=$pingMs===false;

    if($_SESSION['proto']==='sftp'){
        $c=sshConn();
        if($c){$sf=ssh2_sftp($c);$dh=@opendir(sfUrl($sf,$cwd));
            if($dh){while(($e=readdir($dh))!==false){if($e==='.')continue;$fp=sfUrl($sf,rtrim($cwd,'/').'/'.$e);$st=@stat($fp);$files[]=['name'=>$e,'size'=>$st['size']??0,'mtime'=>$st['mtime']??0,'isdir'=>$st?(($st['mode']&0170000)===0040000):($e==='..'),'perms'=>$st?substr(sprintf('%o',$st['mode']),-4):''];}closedir($dh);}
        }
    } else {
        $c=ftpConn();
        if($c){$list=@ftp_mlsd($c,$cwd);
            if($list===false){$raw=@ftp_rawlist($c,$cwd);if($raw)foreach($raw as $l){if(preg_match('/^([\-dlrwxsStT]+)\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w+\s+\d+\s+[\d:]+)\s+(.+)$/',$l,$m))$files[]=['name'=>$m[4],'size'=>(int)$m[2],'mtime'=>strtotime($m[3]),'isdir'=>$m[1][0]==='d','perms'=>$m[1]];}}
            else{foreach($list as $it){if($it['name']==='.')continue;$files[]=['name'=>$it['name'],'size'=>(int)($it['size']??0),'mtime'=>isset($it['modify'])?strtotime($it['modify']):0,'isdir'=>($it['type']??'')==='dir','perms'=>$it['perm']??''];}}
            ftp_close($c);
        }
    }
    usort($files,function($a,$b){if($a['name']==='..') return -1; if($b['name']==='..') return 1; if($a['isdir']!==$b['isdir'])return $b['isdir']-$a['isdir']; return strcasecmp($a['name'],$b['name']);});
}

$sort=$_GET['sort']??'name'; $order=$_GET['order']??'asc';
if($sort==='size') usort($files,fn($a,$b)=>$order==='asc'?$a['size']-$b['size']:$b['size']-$a['size']);
if($sort==='date') usort($files,fn($a,$b)=>$order==='asc'?$a['mtime']-$b['mtime']:$b['mtime']-$a['mtime']);
if($sort==='name'&&$order==='desc') usort($files,fn($a,$b)=>strcasecmp($b['name'],$a['name']));
$connected=isset($_SESSION['connected']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FTP Manager</title>
<style>
:root{
  --bg:#111;--bg2:#1a1a1a;--bg3:#222;--bg4:#2c2c2c;
  --border:#2e2e2e;--border2:#444;
  --text:#e0e0e0;--text2:#888;--text3:#555;
  --acc:#fff;--acc2:#ccc;
  --green:#5fa85f;--red:#a85f5f;--yellow:#a89a5f;
  --radius:5px;--shadow:0 2px 16px rgba(0,0,0,.6)
}
body.light{
  --bg:#f2f2f2;--bg2:#fff;--bg3:#ebebeb;--bg4:#e0e0e0;
  --border:#d8d8d8;--border2:#bbb;
  --text:#111;--text2:#666;--text3:#aaa;
  --acc:#111;--acc2:#444;
  --green:#2d7a2d;--red:#8a2020;--yellow:#7a6010;
  --shadow:0 2px 16px rgba(0,0,0,.08)
}
*{box-sizing:border-box;margin:0;padding:0}
body{font:13px/1.5 'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
a{color:var(--text2);text-decoration:none} a:hover{color:var(--acc)}
button,input,select{font:inherit;outline:none}
input,select{background:var(--bg3);color:var(--text);border:1px solid var(--border);border-radius:var(--radius);padding:5px 10px;transition:.15s}
input:focus,select:focus{border-color:var(--border2);background:var(--bg4)}
button{cursor:pointer;border:none;border-radius:var(--radius);padding:5px 13px;transition:.15s}
.btn{background:var(--bg3);color:var(--text2);border:1px solid var(--border)} .btn:hover{color:var(--acc);border-color:var(--border2)}
.btn-p{background:var(--acc);color:var(--bg);border:1px solid var(--acc)} .btn-p:hover{background:var(--acc2);border-color:var(--acc2)}
.btn-d{background:transparent;color:var(--red);border:1px solid transparent} .btn-d:hover{border-color:var(--red)}
.btn-sm{padding:2px 9px;font-size:11px}
#app{display:flex;flex-direction:column;min-height:100vh}
header{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 16px;display:flex;align-items:center;gap:8px;height:44px;box-shadow:var(--shadow)}
.logo{font-weight:700;font-size:14px;letter-spacing:.3px;color:var(--acc);margin-right:4px}
.hbadge{background:var(--bg3);border:1px solid var(--border);border-radius:20px;padding:2px 10px;font-size:11px;color:var(--text2)}
.ping{display:flex;align-items:center;gap:5px;font-size:11px;font-family:monospace;padding:2px 10px;border-radius:20px;border:1px solid var(--border);background:var(--bg3);cursor:pointer;user-select:none;transition:.15s}
.ping:hover{border-color:var(--border2)} .ping.ok{color:var(--green);border-color:var(--green)} .ping.err{color:var(--red);border-color:var(--red)}
.pdot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.hsp{flex:1}
main{flex:1;padding:16px;max-width:1280px;margin:0 auto;width:100%}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 44px)}
.login-box{background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:28px 32px;width:400px;box-shadow:var(--shadow)}
.login-box h2{margin-bottom:18px;font-size:16px;font-weight:600;color:var(--acc)}
.frow{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}
.frow.full{grid-template-columns:1fr}
.frow label{display:block;font-size:10px;color:var(--text3);margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px}
.frow input,.frow select{width:100%}
.login-box .btn-p{width:100%;padding:9px;margin-top:6px}
.url-hint{margin-top:12px;font-size:10px;color:var(--text3);line-height:1.8}
.url-hint code{background:var(--bg3);padding:1px 5px;border-radius:3px;font-family:monospace}
.breadcrumb{display:flex;align-items:center;gap:3px;flex-wrap:wrap;margin-bottom:10px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:7px 12px}
.breadcrumb button{background:none;border:none;padding:0;cursor:pointer;font-size:11px;color:var(--text2)} .breadcrumb button:hover{color:var(--acc)}
.sep{color:var(--text3);font-size:11px} .cur{font-size:11px;color:var(--text)}
.toolbar{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;align-items:center}
.ftw{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
table{width:100%;border-collapse:collapse}
thead th{background:var(--bg3);padding:7px 12px;text-align:left;font-size:10px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);white-space:nowrap}
thead th a{color:var(--text3);display:flex;align-items:center;gap:3px} thead th a:hover{color:var(--text2)}
tbody tr{border-bottom:1px solid var(--border);transition:.08s} tbody tr:last-child{border:none} tbody tr:hover{background:var(--bg3)}
td{padding:6px 12px;vertical-align:middle}
.fname{display:flex;align-items:center;gap:7px}
.ico{font-size:10px;color:var(--text3);font-style:normal;width:12px;text-align:center}
.fdir td:first-child{font-weight:600}
.fsize,.fdate,.fperms{color:var(--text2);font-size:11px;font-family:monospace}
.fact{display:flex;gap:3px;justify-content:flex-end}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:100;align-items:center;justify-content:center} .overlay.show{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:8px;padding:22px;min-width:290px;box-shadow:var(--shadow)}
.modal h3{margin-bottom:14px;font-size:14px;font-weight:600;color:var(--acc)} .modal input{width:100%;margin-bottom:10px}
.mbtns{display:flex;gap:6px;justify-content:flex-end}
.msg{padding:7px 12px;border-radius:var(--radius);margin-bottom:10px;font-size:12px}
.msg.ok{background:rgba(95,168,95,.08);border:1px solid var(--green);color:var(--green)}
.msg.err{background:rgba(168,95,95,.08);border:1px solid var(--red);color:var(--red)}
.upzone{border:2px dashed var(--border2);border-radius:var(--radius);padding:20px;text-align:center;color:var(--text2);cursor:pointer;transition:.15s;margin-bottom:10px;font-size:12px}
.upzone:hover,.upzone.drag{border-color:var(--acc);color:var(--acc)} .upzone input{display:none}
::-webkit-scrollbar{width:5px;height:5px} ::-webkit-scrollbar-track{background:var(--bg)} ::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
</style>
</head>
<body class="<?= $theme==='light'?'light':'' ?>">
<div id="app">
<header>
  <span class="logo">FTP Manager</span>
  <?php if($connected): ?>
    <span class="hbadge"><?= esc(strtoupper($_SESSION['proto'])) ?></span>
    <span class="hbadge"><?= esc($_SESSION['user']).'@'.esc($_SESSION['host'].':'.$_SESSION['port']) ?></span>
    <span class="ping <?= $pingErr?'err':'ok' ?>" onclick="doPing()" title="Click to re-ping">
      <i class="pdot"></i><span id="ping-val"><?= $pingErr?'timeout':$ping ?></span>
    </span>
  <?php endif ?>
  <span class="hsp"></span>
  <a href="?theme=<?= $theme==='dark'?'light':'dark' ?>" class="btn btn-sm"><?= $theme==='dark'?'Light':'Dark' ?></a>
  <?php if($connected): ?><a href="?logout=1" class="btn btn-sm btn-d">Disconnect</a><?php endif ?>
</header>
<main>
<?php if(!$connected): ?>
<div class="login-wrap"><div class="login-box">
  <h2>FTP / SFTP Manager</h2>
  <?php if($msg): ?><div class="msg err"><?= esc($msg) ?></div><?php endif ?>
  <form method="POST">
  <input type="hidden" name="action" value="login">
  <div class="frow">
    <div><label>Protocol</label><select name="proto" id="proto" onchange="setPort(this)"><option value="ftp">FTP</option><option value="sftp">SFTP</option></select></div>
    <div><label>Port</label><input name="port" id="portf" value="21"></div>
  </div>
  <div class="frow full"><label>Host / IP</label><input name="host" placeholder="ftp.example.com" required></div>
  <div class="frow">
    <div><label>Username</label><input name="user" required></div>
    <div><label>Password</label><input name="pass" type="password"></div>
  </div>
  <button class="btn-p" type="submit">Connect</button>
  </form>
  <div class="url-hint">URL login: <code>?h=host&a=ftp&u=user&p=pass&port=21</code></div>
</div></div>
<?php else: ?>
<?php if($msg): ?><div class="msg <?= $isErr?'err':'ok' ?>"><?= esc($msg) ?></div><?php endif ?>
<div class="breadcrumb">
  <?php $cr=crumbs($cwd); foreach($cr as $i=>[$p,$n]): ?>
    <?php if($i>0): ?><span class="sep">/</span><?php endif ?>
    <?php if($i<count($cr)-1): ?><form method="POST" style="display:inline"><input type="hidden" name="action" value="cd"><input type="hidden" name="dir" value="<?= esc($p) ?>"><button type="submit"><?= esc($n) ?></button></form>
    <?php else: ?><span class="cur"><?= esc($n) ?></span><?php endif ?>
  <?php endforeach ?>
  <span class="hsp"></span><span style="font-size:10px;color:var(--text3)"><?= count($files) ?> items</span>
</div>
<div class="toolbar">
  <button class="btn" onclick="show('m-mkdir')">+ Folder</button>
  <button class="btn" onclick="show('m-newfile')">+ File</button>
  <button class="btn-p" onclick="show('m-upload')">Upload</button>
  <span class="hsp"></span>
  <input type="text" id="filter" placeholder="Filter..." oninput="filterRows(this.value)" style="width:180px">
</div>
<div class="ftw"><table id="ftable">
<thead><tr>
  <th style="width:42%"><a href="?sort=name&order=<?= $sort==='name'&&$order==='asc'?'desc':'asc' ?>">Name <?= $sort==='name'?($order==='asc'?'&#x25B4;':'&#x25BE;'):'' ?></a></th>
  <th><a href="?sort=size&order=<?= $sort==='size'&&$order==='asc'?'desc':'asc' ?>">Size <?= $sort==='size'?($order==='asc'?'&#x25B4;':'&#x25BE;'):'' ?></a></th>
  <th><a href="?sort=date&order=<?= $sort==='date'&&$order==='asc'?'desc':'asc' ?>">Modified <?= $sort==='date'?($order==='asc'?'&#x25B4;':'&#x25BE;'):'' ?></a></th>
  <th>Perms</th>
  <th style="text-align:right">Actions</th>
</tr></thead>
<tbody>
<?php foreach($files as $f): $d=$f['isdir']; $n=$f['name']; $tp=joinPath($cwd,$n); ?>
<tr class="<?= $d?'fdir':'' ?>" data-name="<?= strtolower(esc($n)) ?>">
  <td><div class="fname"><i class="ico"><?= $n==='..'?'..':($d?'D':'F') ?></i>
    <?php if($d): ?><form method="POST" style="display:inline"><input type="hidden" name="action" value="cd"><input type="hidden" name="dir" value="<?= esc($tp) ?>"><button type="submit" style="background:none;border:none;padding:0;cursor:pointer;font-weight:600;color:var(--acc)"><?= esc($n) ?></button></form>
    <?php else: ?><span><?= esc($n) ?></span><?php endif ?>
  </div></td>
  <td class="fsize"><?= $d?'-':fmtSize($f['size']) ?></td>
  <td class="fdate"><?= fmtDate($f['mtime']) ?></td>
  <td class="fperms"><?= esc($f['perms']) ?></td>
  <td class="fact">
    <?php if($n!=='..'): ?>
      <?php if(!$d): ?><form method="POST" style="display:inline"><input type="hidden" name="action" value="download"><input type="hidden" name="name" value="<?= esc($n) ?>"><button class="btn btn-sm" type="submit">DL</button></form><?php endif ?>
      <button class="btn btn-sm" onclick="openR('<?= esc(addslashes($n)) ?>')">Rename</button>
      <button class="btn btn-sm" onclick="openC('<?= esc(addslashes($n)) ?>','<?= esc($f['perms']) ?>')">Chmod</button>
      <button class="btn btn-sm btn-d" onclick="delDlg('<?= esc(addslashes($n)) ?>','<?= $d?'rmdir':'delete' ?>')">Del</button>
    <?php endif ?>
  </td>
</tr>
<?php endforeach ?>
<?php if(empty($files)): ?><tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text3)">Empty directory</td></tr><?php endif ?>
</tbody>
</table></div>
<?php endif ?>
</main></div>

<div class="overlay" id="m-mkdir" onclick="if(event.target===this)hide('m-mkdir')"><div class="modal"><h3>New Folder</h3><form method="POST"><input type="hidden" name="action" value="mkdir"><input name="name" placeholder="Folder name" autofocus required><div class="mbtns"><button type="button" class="btn" onclick="hide('m-mkdir')">Cancel</button><button class="btn-p">Create</button></div></form></div></div>

<div class="overlay" id="m-newfile" onclick="if(event.target===this)hide('m-newfile')"><div class="modal"><h3>New File</h3><form method="POST"><input type="hidden" name="action" value="newfile"><input name="name" placeholder="file.txt" autofocus required><div class="mbtns"><button type="button" class="btn" onclick="hide('m-newfile')">Cancel</button><button class="btn-p">Create</button></div></form></div></div>

<div class="overlay" id="m-upload" onclick="if(event.target===this)hide('m-upload')"><div class="modal"><h3>Upload Files</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="upload"><div class="upzone" id="upzone" onclick="document.getElementById('ufile').click()" ondragover="event.preventDefault();this.classList.add('drag')" ondragleave="this.classList.remove('drag')" ondrop="dropF(event)"><input type="file" name="files[]" id="ufile" multiple onchange="showF(this)"><div id="upt">Click or drag files here</div></div><div class="mbtns"><button type="button" class="btn" onclick="hide('m-upload')">Cancel</button><button class="btn-p">Upload</button></div></form></div></div>

<div class="overlay" id="m-rename" onclick="if(event.target===this)hide('m-rename')"><div class="modal"><h3>Rename</h3><form method="POST"><input type="hidden" name="action" value="rename"><input type="hidden" name="old" id="r-old"><input name="new" id="r-new" required><div class="mbtns"><button type="button" class="btn" onclick="hide('m-rename')">Cancel</button><button class="btn-p">Rename</button></div></form></div></div>

<div class="overlay" id="m-chmod" onclick="if(event.target===this)hide('m-chmod')"><div class="modal"><h3>Permissions</h3><form method="POST"><input type="hidden" name="action" value="chmod"><input type="hidden" name="name" id="ch-n"><input name="mode" id="ch-m" placeholder="644" maxlength="4" required><div class="mbtns"><button type="button" class="btn" onclick="hide('m-chmod')">Cancel</button><button class="btn-p">Apply</button></div></form></div></div>

<div class="overlay" id="m-del" onclick="if(event.target===this)hide('m-del')"><div class="modal"><h3>Confirm Delete</h3><p id="del-msg" style="margin-bottom:14px;color:var(--text2);font-size:12px"></p><form method="POST"><input type="hidden" name="action" id="del-act"><input type="hidden" name="name" id="del-n"><div class="mbtns"><button type="button" class="btn" onclick="hide('m-del')">Cancel</button><button type="submit" style="background:var(--red);color:#fff;border:1px solid var(--red);border-radius:var(--radius);padding:5px 13px;cursor:pointer">Delete</button></div></form></div></div>

<script>
const show=id=>document.getElementById(id).classList.add('show');
const hide=id=>document.getElementById(id).classList.remove('show');
function setPort(s){document.getElementById('portf').value=s.value==='sftp'?'22':'21'}
function openR(n){document.getElementById('r-old').value=n;document.getElementById('r-new').value=n;show('m-rename')}
function openC(n,p){document.getElementById('ch-n').value=n;document.getElementById('ch-m').value=p;show('m-chmod')}
function delDlg(n,a){document.getElementById('del-n').value=n;document.getElementById('del-act').value=a;document.getElementById('del-msg').textContent='Delete "'+n+'"? This cannot be undone.';show('m-del')}
function showF(i){document.getElementById('upt').textContent=i.files.length+' file(s): '+Array.from(i.files).map(f=>f.name).join(', ')}
function dropF(e){e.preventDefault();document.getElementById('upzone').classList.remove('drag');const dt=new DataTransfer();Array.from(e.dataTransfer.files).forEach(f=>dt.items.add(f));const i=document.getElementById('ufile');i.files=dt.files;showF(i)}
function filterRows(v){v=v.toLowerCase();document.querySelectorAll('#ftable tbody tr').forEach(r=>r.style.display=(!v||r.dataset.name.includes(v))?'':'none')}
function doPing(){
  const el=document.querySelector('.ping'),val=document.getElementById('ping-val');
  if(!el||!val)return; val.textContent='...'; el.className='ping';
  const t=Date.now();
  fetch(location.pathname+'?_p=1',{cache:'no-cache'}).then(()=>{
    const ms=Date.now()-t; val.textContent=ms+'ms'; el.className='ping ok';
  }).catch(()=>{val.textContent='timeout';el.className='ping err';});
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.overlay.show').forEach(o=>o.classList.remove('show'))});
</script>
</body>
</html>
