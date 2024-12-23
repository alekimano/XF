<?php

namespace common\components;

use common\helpers\ErrorLog;
use common\models\Cart;
use common\models\Course;
use common\models\Invoice;
use common\models\Order;
use common\models\SystemLog;
use common\models\User;
use frontend\components\sber\OrderStatus;
use Yii;
use yii\base\Component;
use yii\base\Exception;
use yii\httpclient\Client;

/**
 * @url https://ecomtest.sberbank.ru/doc#tag/basicServices
 */
class SberComponent extends Request
{
    //    protected string $_devHost = 'https://3dsec.sberbank.ru';
    // protected string $_devHost = 'https://ecomtest.sberbank.ru'; //Новый шлюз
    //    protected string $_prodHost = 'https://securepayments.sberbank.ru';
    // protected string $_prodHost = 'https://ecommerce.sberbank.ru'; //Новый шлюз
    protected string $_devHost = 'https://rest-api-test.tinkoff.ru';
    protected string $_prodHost = 'https://securepay.tinkoff.ru';


    const FEATURES_AUTO_PAYMENT = 'AUTO_PAYMENT';

    /**
     * @brief Получить автор
     * @return array
     */
    protected function getAuthorization(): array
    {
        return \Yii::$app->params['sberbank'];
    }

    //Новый шлюз
    /**
     * @param Order $order
     * @param int $amount
     * @param string $description
     * @param int|null $clientId
     * @param string|null $email
     * @param bool $autoPayment
     * @param string|null $orderNumber
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function setOrder(Order $order, int $amount, string $description, int $clientId = null, string $email = null, bool $autoPayment = false, string $orderNumber = null)
    {
        $positionId = $order->invoice_id ?? $order->course_id;
        $data = [
            'TerminalKey' => Yii::$app->params['tinkoff']['terminal_key'],
            'Amount' => $amount,
            'OrderId' => (string)$order->id,
            'Description' => $description,
            'NotificationURL' => 'https://lk.99ballov.wocom.biz' . '/sber/index',
            'SuccessURL' => 'https://lk.99ballov.wocom.biz' . '/course/master-group',
            'FailURL' => 'https://lk.99ballov.wocom.biz' . '/course/master-group',
            'Receipt' => [
                'Items' => [
                    [
                        'Name' => $description,
                        'Quantity' => 1,
                        'Amount' => $amount,
                        'Price' => $amount,
                        'PaymentMethod' => 'full_prepayment',
                        'PaymentObject' => 'service',
                        'Tax' => 'none'
                    ]
                ],
                'Email' => $email ?? 'support@99ballov.ru',
                'Taxation' => 'osn'
            ]
        ];

        if ($autoPayment) {
            $data['Recurrent'] = 'Y';
        }

        if ($clientId) {
            $data['CustomerKey'] = (string)$clientId;
        }

        $data['Token'] = $this->generateToken($data);

        $response = json_decode($this->sendRequest($data, 'post', '/v2/Init'));
        SystemLog::create('setOrder ' . json_encode($response), 'tinkoff', $order->id);
        if (!$response) {
            throw new \Exception('Некорректный ответ от платежного шлюза: ' . json_encode($response));
        }

        return (object)[
            'orderId' => $response->PaymentId,
            'formUrl' => $response->PaymentURL
        ];
    }

    /**
     * Генерация токена для подписи запроса
     */
    protected function generateToken(array $data): string
    {
        $password = Yii::$app->params['tinkoff']['password'];
        ksort($data);

        // Фрмируем строку для подписи
        $values = '';
        foreach ($data as $key => $value) {
            if ($key === 'Receipt') {
                continue; // Пропускаем объект Receipt при формировании подписи
            }
            if (!empty($value)) {
                $values .= $value;
            }
        }

        // Возвращаем SHA-256 хеш
        return hash('sha256', $password . $values);
    }

    /**
     * @brief Отмена операции
     * @param string $id
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function cancelOrder(string $id)
    {
        $data = [
            'orderId' => $id,
        ];
        $data = $this->getDataAuthorization($data);

        //        return json_decode($this->sendRequest($data, 'post', '/payment/rest/reverse.do'));
        return json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/reverse.do')); //Новый шлюз
    }

    /**
     * @brief Список связей с картами
     * @param string $id
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function bindingList(string $id)
    {
        $data = [
            'clientId' => $id,
        ];
        $data = $this->getDataAuthorization($data);

        //        return json_decode($this->sendRequest($data, 'post', '/payment/rest/getBindings.do'));
        return json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/getBindings.do')); //Новый шлюз
    }

    /**
     * @brief Получить статус авторизации по OrderNumber
     * @param string $id
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getOrderNumber(string $id): OrderStatus
    {
        $data = [
            'orderNumber' => $id,
        ];
        $data = $this->getDataAuthorization($data);

        //        $response = json_decode($this->sendRequest($data, 'post', '/payment/rest/getOrderStatusExtended.do'), JSON_OBJECT_AS_ARRAY);
        $response = json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/getOrderStatusExtended.do'), JSON_OBJECT_AS_ARRAY); //Новый шлюз
        SystemLog::create('getOrderNumber ' . json_encode($response), 'sber', (int)$id);
        return new OrderStatus($response);
    }

    /**
     * Автоматическое зачисление средств после оплаты
     * @param string $paymentId ID платежа из Init
     * @param string $rebillId ID рекуррентного платежа
     * @param string|null $email Email для уведомлений
     * @return mixed
     * @throws \Exception
     */
    public function autoPayment(string $paymentId, string $rebillId, string $email = null)
    {
        $data = [
            'TerminalKey' => Yii::$app->params['tinkoff']['terminal_key'],
            'PaymentId' => $paymentId,
            'RebillId' => $rebillId
        ];

        if ($email) {
            $data['SendEmail'] = true;
            $data['InfoEmail'] = $email;
        }

        $data['Token'] = $this->generateToken($data);

        $response = json_decode($this->sendRequest($data, 'post', '/v2/Charge'));
        SystemLog::create('autoPayment response: ' . json_encode($response), 'tinkoff');

        if (!$response || !$response->Success) {
            throw new \Exception('Ошибка автоматического зачисления: ' . ($response->Message ?? 'Неизвестная ошибка'));
        }

        return $response;
    }

    /**
     * Получить статус платежа
     * @param string $id ID платежа
     * @return OrderStatus
     * @throws \Exception
     */
    public function getOrder(string $id): OrderStatus
    {
        $data = [
            'TerminalKey' => Yii::$app->params['tinkoff']['terminal_key'],
            'PaymentId' => $id,
            'Token' => ''
        ];

        $data['Token'] = $this->generateToken($data);

        $response = json_decode($this->sendRequest($data, 'post', '/v2/GetState'), true);
        SystemLog::create('getOrder response: ' . json_encode($response), 'tinkoff');

        try {
            if (!$response['Success']) {
                throw new \Exception('Error getting order status: ' . ($response['Message'] ?? 'Unknown error'));
            }

            $orderStatusData = [
                'orderNumber' => $response['OrderId'],
                'orderStatus' => $this->mapTinkoffStatus($response['Status']),
                'amount' => $response['Amount'],
                'actionCodeDescription' => $response['Message'] ?? '',
                'date' => time(),
                'orderId' => $response['PaymentId'],
            ];

            return new OrderStatus($orderStatusData);
        } catch (\Throwable $e) {
            ErrorLog::createLog(new \Exception('Error getOrder tinkoff. Response: ' . json_encode($response)));
            throw new Exception($e);
        }
    }

    /**
     * Преобразование статусов Тинькофф в статусы OrderStatus
     */
    private function mapTinkoffStatus(string $tinkoffStatus): int
    {
        $statusMap = [
            'NEW' => 0,            // Заказ зарегистрирован, но не оплачен
            'AUTHORIZED' => 1,     // Предавторизованная сумма удержана
            'CONFIRMED' => 2,      // Проведена полная авторизация суммы заказа
            'REVERSED' => 3,       // Авторизация отменена
            'REFUNDED' => 4,       // Проведен возврат
            '3DS_CHECKING' => 5,   // Инициирована авторизация через 3DS
            'REJECTED' => 6,       // Авторизация отклонена
            'CANCELED' => 3,       // Операция отменена
            'DEADLINE_EXPIRED' => 6 // Истек срок ожидания
        ];

        return $statusMap[$tinkoffStatus] ?? 0;
    }

    /**
     * @param array $data
     * @param string $method
     * @param string $url
     * @return string
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    protected function sendRequest(array $data, string $method, string $url): string
    {
        $client = new Client();
        $response = $client->createRequest()
            ->setMethod($method)
            ->setFormat(Client::FORMAT_JSON)
            ->addHeaders([
                'Content-Type' => 'application/json'
            ])
            ->setData($data);

        $response->setUrl($this->_prodHost . $url);
        $data = $response->send();
        return $data->content;
    }

    /**
     * @brief Подставить авторизационные данные
     * @param array $data
     * @return array
     */
    protected function getDataAuthorization(array $data): array
    {
        $params = $this->getAuthorization();
        if (isset($params['token'])) {
            $data['token'] = $params['token'];
        } else {
            $data['userName'] = $params['username'];
            $data['password'] = $params['password'];
        }
        return $data;
    }
}
