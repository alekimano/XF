<?php
//echo 'ÐÑÐ¾Ð²Ð¾Ð´ÑÑÑÑ Ð¿Ð»Ð°Ð½Ð¾Ð²ÑÐµ ÑÐµÑÐ½Ð¸ÑÐµÑÐºÐ¸Ðµ ÑÐ°Ð±Ð¾ÑÑ. ÐÑÐ¸ÐµÐ½ÑÐ¸ÑÐ¾Ð²Ð¾ÑÐ½Ð¾Ðµ Ð²ÑÐµÐ¼Ñ Ð·Ð°Ð²ÐµÑÑÐµÐ½Ð¸Ñ 15:00 Ð¿Ð¾ ÐÐ¡Ð'; die;
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../../common/config/bootstrap.php';
require __DIR__ . '/../config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../../common/config/main.php',
    require __DIR__ . '/../../common/config/main-local.php',
    require __DIR__ . '/../config/main.php',
    require __DIR__ . '/../config/main-local.php'
);

(new yii\web\Application($config))->run();