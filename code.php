<?php
// Укажите путь к файлу для записи логов
$logFile = 'post_requests.log';

// Проверяем, является ли запрос POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получение заголовков запроса
    $headers = getallheaders();

    // Получаем тело запроса
    $requestBody = file_get_contents('php://input');

    // Логируем информацию о запросе
    $logEntry = "----------------------------\n";
    $logEntry .= "Time: " . date("Y-m-d H:i:s") . "\n";
    $logEntry .= "Headers:\n";
    foreach ($headers as $name => $value) {
        $logEntry .= "$name: $value\n";
    }
    $logEntry .= "Body:\n$requestBody\n\n";

    // Записываем лог в файл
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Вы можете добавить дальнейшую обработку запроса или возврат ответа здесь
echo 'POST request logged';
?>