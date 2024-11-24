<?php
session_start();

// Устанавливаем лимит перенаправлений (1 раз)
if (!isset($_SESSION['redirected'])) {
    // Отметить, что перенаправление выполнено
    $_SESSION['redirected'] = true;

    // Выполнить перенаправление
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: https://lnk.do/a4GVnvtZ');
    exit();
} else {
    // Перенаправление уже было выполнено, показываем сообщение об ошибке
    header("Content-Type: text/html; charset=UTF-8");
    echo "<h1>Ошибка перенаправления</h1>";
    echo "<p>Произошло слишком много перенаправлений. Пожалуйста, попробуйте позже.</p>";
    exit();
}
?>
