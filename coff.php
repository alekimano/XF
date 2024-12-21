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
    protected string $_devHost = 'https://ecomtest.sberbank.ru';//Новый шлюз
//    protected string $_prodHost = 'https://securepayments.sberbank.ru';
    protected string $_prodHost = 'https://ecommerce.sberbank.ru';//Новый шлюз

    const FEATURES_AUTO_PAYMENT = 'AUTO_PAYMENT';

    /**
     * @brief Получить автор
     * @return array
     */
    protected function getAuthorization():array
    {
        return \Yii::$app->params['sberbank'];
    }

//      Старый шлюз
//    /**
//     * @param Order $order
//     * @param float $amount
//     * @param string $description
//     * @param int|null $clientId
//     * @param string|null $email
//     * @param bool $autoPayment
//     * @param string|null $orderNumber
//     * @return mixed
//     * @throws \yii\base\InvalidConfigException
//     * @throws \yii\httpclient\Exception
//     */
//    public function setOrder(Order $order, float $amount, string $description, int $clientId = null, string $email = null, bool $autoPayment = false, string $orderNumber = null)
//    {
//        if ($orderNumber) {
//            $response = $this->getOrder($orderNumber);
//            if ($response) {
//                if (isset($response->actionCodeDescription) && $response->actionCodeDescription !== '') {
//                    $data['orderNumber'] = $order->id . '-' . time();
//                    $response = json_decode($this->sendRequest($data, 'post', '/payment/rest/register.do'));
//                    SystemLog::create('setOrder expired ' . json_encode(get_object_vars($response)), 'sber');
//                    return $response;
//                } else {
//                    if (!$response->orderId) throw new \Exception('Не верный формат пришел');
//                    return (object)['orderId' => $response->orderId, 'formUrl' => $this->host() . '/payment/merchants/sbersafe_sberid/payment_ru.html?mdOrder=' . $response->orderId];
//                }
//            } else {
//                throw new \Exception('Произошла ошибка');
//            }
//        }
//        $data = [
//            'orderNumber' => $order->id,
//            'amount' => $amount,
//            'returnUrl' => \Yii::$app->params['baseUrl'] . '/course/master-group',
//            'description' => $description,
//        ];
//
//        $data = $this->getDataAuthorization($data);
//
//        if ($clientId) {
//            $data['clientId'] = $clientId;
//        }
//        if ($email) {
//            $data['email'] = $email;
//        }
//        if ($autoPayment) {
//            $data['features'] = self::FEATURES_AUTO_PAYMENT;
//        }
//        $response = json_decode($this->sendRequest($data, 'post', '/payment/rest/register.do'));
//        SystemLog::create('setOrder ' . json_encode(get_object_vars($response)), 'sber');
//
//        if (isset($response->errorCode) && $response->errorCode) {
//            switch ($response->errorCode) {
//                case 1:
//                    $response = $orderNumber ? $this->getOrder($orderNumber) : $this->getOrderNumber($data['orderNumber']);
//                    if ($response) {
//                        if (isset($response->actionCodeDescription) && $response->actionCodeDescription !== '') {
//                            $data['orderNumber'] = $order->id . '-' . time();
//                            $response = json_decode($this->sendRequest($data, 'post', '/payment/rest/register.do'));
//                            SystemLog::create('setOrder expired ' . json_encode(get_object_vars($response)), 'sber');
//                            return $response;
//                        } else {
//                            if (!$response->orderId) throw new \Exception('Не верный формат пришел');
//                            return (object)['orderId' => $response->orderId, 'formUrl' => $this->host() . '/payment/merchants/sbersafe_sberid/payment_ru.html?mdOrder=' . $response->orderId];
//                        }
//                    } else {
//                        throw new \Exception('Произошла ошибка');
//                    }
//                case 5:
//                    throw new \Exception('Доступ запрещен');
//                default:
//                    throw new \Exception('Произошла ошибка');
//            }
//        }
//
//        return $response;
//    }


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
            'orderNumber' => (string)$order->id,
            'amount' => $amount,
            'returnUrl' => \Yii::$app->params['baseUrl'] . '/course/master-group',
            'description' => $description,
            'orderBundle' => [
                'ffdVersion' => '1.05',
                'receiptType' => 'sell',
                'company' => [
                    'email' => 'support@99ballov.ru',
                    'inn' => Yii::$app->params['organizationData']['inn'],
                    'paymentAddress' => 'https://lk.99ballov.ru',
                    'sno' => 'osn',
                ],
                'total' => $amount,
                'payments' => [
                    [
                        'type' => 1,
                        'sum' => $amount,
                    ]
                ],
                'cartItems' => [
                    'items' => [
                        [
                            'positionId' => (string)$positionId,
                            'itemCode' => (string)$positionId,
                            'name' => $description,
                            'quantity' => [
                                'value' => 1,
                                "measure" => '0'
                            ],
                            'itemPrice' => $amount,
                            'itemAmount' => $amount,
                            'paymentMethod' => 'full_payment',
                            'paymentObject' => 'service',
                            'tax' => [
                                'taxType' => 0,
                            ]
                        ]
                    ],
                ]
            ]
        ];
        $data = $this->getDataAuthorization($data);

        if ($clientId) {
            $data['clientId'] = (string)$clientId;
        }

        if ($email) {
            $data['email'] = $email;
        }
        if ($autoPayment) {
            $data['features'] = self::FEATURES_AUTO_PAYMENT;
        }

        if ($orderNumber) {
            $response = $this->getOrder($orderNumber);
            if ($response) {
                if (isset($response->actionCodeDescription) && $response->actionCodeDescription !== '' && $response->orderStatus != OrderStatus::ORDER_STATUS_NEW) {
                    $data['orderNumber'] = $order->id . '-' . time();
                    $response = json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/register.do'));
                    SystemLog::create('setOrder expired ' . json_encode(get_object_vars($response)), 'sber', $order->id);
                    return $response;
                } else {
                    if (!$response->orderId) throw new \Exception('Не верный формат пришел');
//                    return (object)['orderId' => $response->orderId, 'formUrl' => $this->host() . '/payment/merchants/sbersafe_sberid/payment_ru.html?mdOrder=' . $response->orderId];
                    $url = 'https://';
                    if (defined('YII_ENV') && YII_ENV === 'dev') {
                        $url .= 'sbox.';
                    }
                    return (object)['orderId' => $response->orderId, 'formUrl' => $url . 'payecom.ru/pay_ru?orderId=' . $response->orderId];
                }
            } else {
                throw new \Exception('Произошла ошибка');
            }
        }

        $response = json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/register.do'));
        SystemLog::create('setOrder ' . json_encode(get_object_vars($response)), 'sber', $order->id);

        if (isset($response->errorCode) && $response->errorCode) {
            switch ($response->errorCode) {
                case 1:
                    $response = $orderNumber ? $this->getOrder($orderNumber) : $this->getOrderNumber($data['orderNumber']);
                    if ($response) {
                        if (isset($response->actionCodeDescription) && $response->actionCodeDescription !== '') {
                            $data['orderNumber'] = $order->id . '-' . time();
                            $response = json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/register.do'));
                            SystemLog::create('setOrder expired ' . json_encode(get_object_vars($response)), 'sber', $order->id);
                            return $response;
                        } else {
                            if (!$response->orderId) throw new \Exception('Не верный формат пришел');
                            return (object)['orderId' => $response->orderId, 'formUrl' => $this->host() . '/payment/merchants/sbersafe_sberid/payment_ru.html?mdOrder=' . $response->orderId];
                        }
                    } else {
                        throw new \Exception('Произошла ошибка');
                    }
                default:
                    throw new \Exception('Произошла ошибка');
            }
        }

        return $response;
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
        return json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/reverse.do'));//Новый шлюз
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
        return json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/getBindings.do'));//Новый шлюз
    }

    /**
     * @brief Получить статус авторизации по OrderNumber
     * @param string $id
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getOrderNumber(string $id):OrderStatus
    {
        $data = [
            'orderNumber' => $id,
        ];
        $data = $this->getDataAuthorization($data);

//        $response = json_decode($this->sendRequest($data, 'post', '/payment/rest/getOrderStatusExtended.do'), JSON_OBJECT_AS_ARRAY);
        $response = json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/getOrderStatusExtended.do'), JSON_OBJECT_AS_ARRAY);//Новый шлюз
        SystemLog::create('getOrderNumber ' . json_encode($response), 'sber', (int)$id);
        return new OrderStatus($response);
    }

    /**
     * @brief Автоплатеж
     * @param string $orderId
     * @param string|null $email
     * @param string $bindingId
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function autoPayment(string $orderId, string $bindingId, string $email = null)
    {
        $data = [
            'mdOrder' => $orderId,
            'bindingId' => $bindingId,
            'email' => $email,
        ];

        $data = $this->getDataAuthorization($data);

        if ($email) {
            $data['email'] = $email;
        }

//        return json_decode($this->sendRequest($data, 'post', '/payment/rest/paymentOrderBinding.do?' . http_build_query($data)));
        return json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/paymentOrderBinding.do'));//Новый шлюз
    }

    /**
     * @brief Получить статус авторизации
     * @param string $id
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception|Exception
     */
    public function getOrder(string $id):OrderStatus
    {
        $data = [
            'orderId' => $id,
        ];

        $data = $this->getDataAuthorization($data);

//        $response = json_decode($this->sendRequest($data, 'post', '/payment/rest/getOrderStatusExtended.do'), JSON_OBJECT_AS_ARRAY);
        $response = json_decode($this->sendRequest($data, 'post', '/ecomm/gw/partner/api/v1/getOrderStatusExtended.do'), JSON_OBJECT_AS_ARRAY);//Новый шлюз
        SystemLog::create('getOrder ' . json_encode($response), 'sber');

        try {
            return new OrderStatus($response);
        } catch (\Throwable $e) {
            ErrorLog::createLog(new \Exception('Error getOrder sber. Response: ' . json_encode($response)));
            throw new Exception($e);
        }
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
            ->setMethod($method);

        if ($data && $method == 'post') {
//            $response->addHeaders([
//                'content-type' => 'application/x-www-form-urlencoded',
//            ])
//                ->setData($data);
            //Новый шлюз
            $response
                ->setFormat(\yii\web\Response::FORMAT_JSON)
//                ->addHeaders([
//                    'content-type' => 'application/x-www-form-urlencoded',
//                ])
                ->setData($data);
        } else {
            if ($data) {
                $url = $url . '?' . http_build_query($data);
            }
        }

        SystemLog::create('url '. $this->host() . $url, 'sber');
        SystemLog::create($method . ' data: '. json_encode($data), 'sber');
        $response->setUrl($this->host() . $url);

        return $response->send()->content;
    }

    /**
     * @brief Подставить авторизационные данные
     * @param array $data
     * @return array
     */
    protected function getDataAuthorization(array $data):array
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
