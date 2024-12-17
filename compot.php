<?php
namespace frontend\controllers;

use common\components\payment\SberInstallmentComponent;
use common\components\SbpComponent;
use common\components\TbankInstallmentComponent;
use common\components\yandex\YandexPayComponent;
use common\helpers\text_helper\TextHelper;
use common\models\ActivateCourseUser;
use common\models\ConfirmPhoneSms;
use common\models\Course;
use common\models\JobBankAsk;
use common\models\KnowledgeLevels;
use common\models\PhoneConfirmation;
use common\models\RegistrationSubject;
use common\models\RouteSetting;
use common\models\Settings;
use common\models\Utm;
use frontend\components\ApiResponse;
use frontend\components\ApiResponseException;
use common\components\SberComponent;
use common\models\Invoice;
use common\models\OneSignal;
use common\helpers\ErrorLog;
use common\models\Account;
use common\models\ConfirmPhone;
use common\models\Notification;
use common\models\Profile;
use common\models\User;
use frontend\components\BaseFrontController;
use frontend\models\EmailForm;
use frontend\models\LoginForm;
use frontend\models\PasswordResetNewForm;
use frontend\models\PasswordTaskForm;
use frontend\models\RegistrationForm;
use frontend\models\RegistrationTask;
use frontend\models\RegistrationTaskView;
use frontend\models\ShortLogin;
use frontend\models\SignupForm;
use frontend\models\PasswordResetRequestForm;
use frontend\models\UpdatePasswordForm;
use Yii;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\widgets\ActiveForm;
use yii\authclient\OAuth2;
/**
 * Site controller
 */
class SiteController extends BaseFrontController
{
    public $layout = 'noauth';

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'auth-phone' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'auth' => [
                'class' => 'yii\authclient\AuthAction',
                'successCallback' => [$this, 'onAuthSuccess'],
            ],
            'auth-yandex' => [
                'class' => 'yii\authclient\AuthAction',
                'successCallback' => [$this, 'onAuthSuccessYandex'],
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    /**
     * @brief Действия после успешной аутентификации через другой сервис
     * @param OAuth2 $client
     * @return Response
     */
    public function onAuthSuccess(OAuth2 $client)
    {
        try {
            $attributes = $client->getUserAttributes();

            /* @var $auth Account */
            $auth = Account::find()->where([
                'provider' => $client->getId(),
                'client_id' => $attributes['id'],
            ])->one();

            if (Yii::$app->user->isGuest) {
                if ($auth) { // авторизация
                    $user = $auth->user;
                    Yii::$app->user->login($user, 3600 * 24 * 30);
                } else { // регистрация
                    if (isset($attributes['email']) && User::find()->where(['email' => $attributes['email']])->exists()) {
                        Yii::$app->getSession()->setFlash('error', [
                            Yii::t('app', "Пользователь с такой электронной почтой как в {client} уже существует, но с ним не связан. Для начала войдите на сайт использую электронную почту, для того, что бы связать её.", ['client' => $client->getTitle()]),
                        ]);
                    } else {
                        $settings = Settings::find()->where(['type' => Settings::TYPE_REGISTRATION_VK, 'value' => Settings::VALUE_YES])->count();
                        if (!$settings) {
                            Yii::$app->session->setFlash('error', 'Такой аккаунт не найден. Необходимо зарегистрироваться');
                            return $this->goHome();
                        }
                        $user = new User([
                            'username' => ArrayHelper::getValue($attributes, 'id') . '@vk.com',
                        ]);
                        $user->is_first_visit = 1;
                        $user->type_registration = User::TYPE_REGISTRATION_SOCIAL_NETWORK;
                        $transaction = $user->getDb()->beginTransaction();
                        if ($user->save()) {
//                            $authManager = \Yii::$app->getAuthManager();
//                            $role = $authManager->getRole('user');
//                            $authManager->assign($role, $user->id);

                            $profile = $user->profile;
                            $profile->scenario = Profile::SCENARIO_REGISTER_VK;
                            $profile->user_id = $user->id;
                            $profile->name = ArrayHelper::getValue($attributes, 'first_name');
                            $profile->last_name = ArrayHelper::getValue($attributes, 'last_name');
                            if (!$profile->save()) {
                                throw new \Exception('Не удалось создать профиль. Ошибки валидации: ' . json_encode($profile->errors));
                            }

                            $user->setToUL();

                            $auth = new Account([
                                'user_id' => $user->id,
                                'provider' => $client->getId(),
                                'client_id' => (string)$attributes['id'],
                            ]);
                            if ($auth->save()) {
//                                $user->setRef();
                                $user->save(false);
                                $transaction->commit();
                                Yii::$app->user->login($user, 3600 * 24 * 30);
                                return $this->redirect(['profile/reg']);
                            } else {
                                ErrorLog::createLog(new \Exception(json_encode($auth->getErrors())));
                                $transaction->rollback();
                            }
                        } else {
                            ErrorLog::createLog(new \Exception(json_encode($user->getErrors())));
                            $transaction->rollback();
                        }
                    }
                }
            } else { // Пользователь уже зарегистрирован
                if (!$auth) { // добавляем внешний сервис аутентификации
                    $auth = new Account([
                        'user_id' => Yii::$app->user->id,
                        'provider' => $client->getId(),
                        'client_id' => $attributes['id'],
                    ]);
                    $auth->save();
                }
            }

            if (Yii::$app->session->get('prev_url')) {
                return $this->redirect(Yii::$app->params['baseUrl'] . Yii::$app->session->get('prev_url'));
            }
            return $this->goHome();
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            return $this->redirect(['user/login', 'vkFail' => true]);
        } catch (\Throwable $t) {
            ErrorLog::createLogThrowable($t);
            return $this->redirect(['user/login', 'vkFail' => true]);
        }
    }

    /**
     * @brief Действия после успешной аутентификации через другой сервис
     * @param OAuth2 $client
     * @return Response
     */
    public function onAuthSuccessYandex(OAuth2 $client)
    {
        try {
            $attributes = $client->getUserAttributes();

            /* @var $auth Account */
            $auth = Account::find()->where([
                'provider' => 'yandex',
                'client_id' => $attributes['id'],
            ])->one();

            if (Yii::$app->user->isGuest) {
                if ($auth) { // авторизация
                    $user = $auth->user;
                    Yii::$app->user->login($user, 3600 * 24 * 30);
                } else { // регистрация

                    if (isset($attributes['default_email']) && User::find()->where(['email' => $attributes['default_email']])->exists()) {
                        Yii::$app->getSession()->setFlash('error', [
                            Yii::t('app', "Пользователь с такой электронной почтой как в {client} уже существует, но с ним не связан. Для начала войдите на сайт использую электронную почту, для того, что бы связать её.", ['client' => $client->getTitle()]),
                        ]);
                    } else {
                        $settings = Settings::find()->where(['type' => Settings::TYPE_REGISTRATION_YANDEX, 'value' => Settings::VALUE_YES])->count();
                        if (!$settings) {
                            Yii::$app->session->setFlash('error', 'Такой аккаунт не найден. Необходимо зарегистрироваться');
                            return $this->goHome();
                        }

                        $defaultPhone = ArrayHelper::getValue($attributes,'default_phone');

                        $user = User::find()
                            ->andWhere([
                                'phone' => $defaultPhone ? $str = str_replace('+', '', $defaultPhone['number']) : null
                            ])
                            ->orWhere([
                                'email' =>  ArrayHelper::getValue($attributes, 'default_email')
                            ])
                            ->one();



                        if($user){
                            $transaction = $user->getDb()->beginTransaction();
                            $auth = new Account([
                                'user_id' => $user->id,
                                'provider' => 'yandex',
                                'client_id' => (string)$attributes['id'],
                            ]);
                            if ($auth->save()) {
//                                $user->setRef();
                                $user->save(false);
                                $transaction->commit();
                                Yii::$app->user->login($user, 3600 * 24 * 30);
                                return $this->redirect(['profile/reg']);
                            } else {
                                ErrorLog::createLog(new \Exception(json_encode($auth->getErrors())));
                                $transaction->rollback();
                            }
                        }else{
                            $user = new User([
                                'username' => ArrayHelper::getValue($attributes, 'default_email'),
                                'phone' => $defaultPhone ? $defaultPhone['number'] : null,
                            ]);
                            $transaction = $user->getDb()->beginTransaction();
                            $user->is_first_visit = 1;

                            $user->type_registration = User::TYPE_REGISTRATION_SOCIAL_NETWORK;
                        }



                        if ($user->save()) {
//                            $authManager = \Yii::$app->getAuthManager();
//                            $role = $authManager->getRole('user');
//                            $authManager->assign($role, $user->id);

                            $profile = $user->profile;
                            $profile->scenario = Profile::SCENARIO_REGISTER_YANDEX;
                            $profile->user_id = $user->id;
                            $profile->name = ArrayHelper::getValue($attributes, 'first_name');
                            $profile->last_name = ArrayHelper::getValue($attributes, 'last_name');
                            if (!$profile->save()) {
                                throw new \Exception('Не удалось создать профиль. Ошибки валидации: ' . json_encode($profile->errors));
                            }

                            $user->setToUL();

                            $auth = new Account([
                                'user_id' => $user->id,
                                'provider' => 'yandex',
                                'client_id' => (string)$attributes['id'],
                            ]);
                            if ($auth->save()) {
//                                $user->setRef();
                                $user->save(false);
                                $transaction->commit();

                                Yii::$app->user->login($user, 3600 * 24 * 30);
                                return $this->redirect(['profile/reg']);
                            } else {
                                ErrorLog::createLog(new \Exception(json_encode($auth->getErrors())));
                                $transaction->rollback();
                            }
                        } else {
                            ErrorLog::createLog(new \Exception(json_encode($user->getErrors())));
                            $transaction->rollback();
                        }
                    }
                }
            } else { // Пользователь уже зарегистрирован
                if (!$auth) { // добавляем внешний сервис аутентификации
                    $auth = new Account([
                        'user_id' => Yii::$app->user->id,
                        'provider' => 'yandex',
                        'client_id' => $attributes['id'],
                    ]);
                    $auth->save();
                }
            }

            if (Yii::$app->session->get('prev_url')) {
                return $this->redirect(Yii::$app->params['baseUrl'] . Yii::$app->session->get('prev_url'));
            }
            return $this->goHome();
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            return $this->redirect(['user/login', 'yandexFail' => true]);
        } catch (\Throwable $t) {
            ErrorLog::createLogThrowable($t);
            return $this->redirect(['user/login', 'yandexFail' => true]);
        }
    }

    /**
     * Displays homepage.
     *
     * @return mixed
     */
    public function actionIndex()
    {
        $this->layout = 'lk';
        return $this->render('index');
    }

    /**
     * Logs out the current user.
     *
     * @return mixed
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->goHome();
    }

    /**
     * @brief Подтверждение номера телефона
     * @return string|Response|array
     * @throws \Exception
     */
    public function actionConfirmPhone()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['user/login']);
        }

        $user = User::getCurrent();
        if ($user->confirmed_phone && !User::find()->where(['phone' => $user->confirmed_phone])->andWhere(['!=', 'id', $user->id])->limit(1)->one()) {
            $user->confirmed_phone_at = $user->previous_confirmed_phone_at;
            $user->phone = $user->confirmed_phone;
            $user->save(false);
        }

        $user->setScenario(User::SCENARIO_UPDATE_PHONE);

        if (!$user->needConfirmPhone) {
            return $this->goHome();
        }

        $model = new ConfirmPhone($user);

        if (!$user->getConfirmPhoneSms()) {
            if (!$user->sendConfirmPhoneSms()) {
                Yii::$app->session->setFlash('warning', 'Не удалось отправить смс-сообщение');
                return $this->render('confirm-phone', [
                    'model' => $model,
                    'user' => $user,
                ]);
            }
        }

        if (Yii::$app->request->isAjax && $model->load(Yii::$app->request->post())) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return ActiveForm::validate($model);
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $user->setPhoneConfirmed();
            return $this->goHome();
        }

        return $this->render('confirm-phone', [
            'model' => $model,
            'user' => $user,
        ]);
    }

    /**
     * @brief Подтверждение почты
     * @return string|Response
     * @throws \yii\base\InvalidConfigException
     */
    public function actionConfirmEmail()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['user/login']);
        }

        $user = User::getCurrent();
        $user->setScenario(User::SCENARIO_SET_EMAIL);

        if (!$user->needConfirmEmail) {
            return $this->goHome();
        }

//        $token = \Yii::createObject(['class' => Token::className(), 'type' => Token::TYPE_CONFIRMATION]);
//        $token->link('user', $user);
//        $user->mailer->sendWelcomeMessage($user, $token);
        $user->sendWelcomeMessage();
        return $this->render('confirm-email', [
            'user' => $user,
        ]);
    }

    /**
     * @brief Удаление номера телефона
     * @return Response
     */
    public function actionDeletePhone()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['user/login']);
        }

        $user = User::getCurrent();
        $user->scenario = User::SCENARIO_DELETE_PHONE_USER;
        $user->phone = null;
        if (!$user->save()) {
            Yii::$app->session->setFlash('warning', 'Произошла ошибка');
        }
        return $this->goHome();
    }

    /**
     * @return Response
     * @throws ForbiddenHttpException
     */
    public function actionDeleteEmail()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['user/login']);
        }

        $user = User::getCurrent();

        if ($user->confirmed_at) {
            throw new ForbiddenHttpException('Нельзя удалять подтвержденную почту');
        }

        if ($user->getVkAccount()) {
            $user->email = null;
            $user->save(false);
            return $this->goHome();
        } else {
            throw new ForbiddenHttpException('Нельзя удалять почту, если нет привязки к странице ВК');
        }
    }

    /**
     * @brief Установит все уведомления пользователя как просмотренные
     */
    public function actionShowNotification()
    {
        Notification::updateAll(['viewed_at' => true], 'user_id = :user_id AND viewed_at IS NULL', [
            ':user_id' => User::getCurrent()->id,
        ]);
    }

    /**
     * @brief Сохрание player_id в базу
     * @param $player_id
     */
    public function actionSetPushPlayer($player_id)
    {
        if (!$exist = OneSignal::findOne(['player_id' => $player_id])) {
            $oneSignal = new OneSignal([
                'user_id' => User::getCurrent()->id,
                'player_id' => $player_id,
            ]);

            $oneSignal->save();
        }
    }

    public function actionSetEmail()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = User::getCurrent();
        if ($user->email) {
            return true;
        }
        $emailForm = new EmailForm();
        $emailForm->setAttributes(\Yii::$app->request->post());
        if ($emailForm->validate()) {
            $emailForm->setEmail($user);
            return true;
        }
        Yii::$app->response->statusCode = 422;
        return $emailForm->getErrors();
    }

    /**
     * @param string $code
     * @param string $email
     * @throws NotFoundHttpException
     */
    public function actionSetInvoiceOrderEmail($code, $email)
    {
        $user = User::getCurrent();
        $invoice = Invoice::find()
            ->where('deleted_at IS NULL')
            ->andWhere(['url' => $code])
            ->andWhere('status NOT IN (:cancel, :payment) OR status IS NULL', [
                ':cancel' => Invoice::STATUS_CANCEL,
                ':payment' => Invoice::STATUS_PAYMENT,
            ])
            ->one();
        /** @var Invoice $invoice */

        if (!$invoice) throw new NotFoundHttpException('Инвойс не найден');
        if ($invoice->user_id && $invoice->user_id != $user->id) throw new ForbiddenHttpException('У Вас нет прав доступа');
        $order = $invoice->getOrder($user);
        if (!$order) throw new \Exception('Произошла ошибка');

        $order->email = $email;
        $order->saveStrict();
    }

    /**
     * @brief Получить инвойс счета
     * @param string $code
     * @param string $email
     * @param bool $autoRenewal
     * @return ApiResponse|ApiResponseException
     */
    public function actionSetInvoiceOrder(string $code, string $email, bool $autoRenewal = false)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $user = User::getCurrent();
            if($user->status != User::STATUS_ACTIVE) throw new BadRequestHttpException('Оплачивать могут только авторизованные пользователи');
            $invoice = Invoice::find()
                ->where('deleted_at IS NULL')
                ->andWhere(['url' => $code])
                ->andWhere('status NOT IN (:cancel, :payment) OR status IS NULL', [
                    ':cancel' => Invoice::STATUS_CANCEL,
                    ':payment' => Invoice::STATUS_PAYMENT,
                ])
                ->one();
            /** @var Invoice $invoice */

            if (!$invoice) throw new NotFoundHttpException('Инвойс не найден');
            if ($invoice->user_id && $invoice->user_id != $user->id) throw new ForbiddenHttpException('У Вас нет прав доступа');
            if (!$invoice->validateCart() && $invoice->type != Invoice::TYPE_SURCHARGE) {
                return new ApiResponse(true, 'Необходимо убрать из корзины активированный курс', 406);
            }
            if ($autoRenewal) {
                if (!$invoice->setAutoreneval()) throw new \Exception('Произошла ошибка');
            }
            $order = $invoice->getOrder($user);
            if (!$order) throw new \Exception('Произошла ошибка');

            $order->email = $email;
            if (!$order->save()) throw new \Exception('Произошла ошибка');

            return new ApiResponse(false, [
                'description' => $order->getDescriptionSber(),
                'amount' => $order->amount,
                'invoiceId' => $order->id,
                'accountId' => $user->id,
                'email' => $order->email,
                'publicId' => Yii::$app->params['cloudPayment']['pk'],
            ]);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }

    }

    /**
     * @brief Оплата выставление счета по сбербанку
     * @param string $code
     * @param string $email
     * @return ApiResponse|void|Response
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function actionSetInvoiceOrderSber(string $code, string $email, bool $autoRenewal = false)
    {
        $user = User::getCurrent();
        if($user->status != User::STATUS_ACTIVE) throw new BadRequestHttpException('Оплачивать могут только авторизованные пользователи');
        $invoice = Invoice::find()
            ->where('deleted_at IS NULL')
            ->andWhere(['url' => $code])
            ->andWhere('status NOT IN (:cancel, :payment) OR status IS NULL', [
                ':cancel' => Invoice::STATUS_CANCEL,
                ':payment' => Invoice::STATUS_PAYMENT,
            ])
            ->one();
        /** @var Invoice $invoice */

        if (!$invoice) throw new NotFoundHttpException('Инвойс не найден');
        if ($invoice->user_id && $invoice->user_id != $user->id) throw new ForbiddenHttpException('У Вас нет прав доступа');
        if (!$invoice->validateCart() && $invoice->type != Invoice::TYPE_SURCHARGE) {
            Yii::$app->session->setFlash('warning', 'Необходимо убрать из корзины активированный курс');
            return $this->goBack();
        }
        if ($autoRenewal) {
            if (!$invoice->setAutoreneval()) throw new \Exception('Произошла ошибка');
        }
        $order = $invoice->getOrder($user);
        if (!$order) throw new \Exception('Произошла ошибка');

        $sberComponent = new SberComponent();
//        if ($invoice->prepayment) {
//            $promocode = $invoice->promocode;
//            $sum = !$promocode ? $invoice->prepayment_amount : ceil($invoice->getTotalSumPromocode($promocode));
//        } else {
//            $sum = $order->amount;
//        }

//        $sum = $invoice->prepayment ? $invoice->prepayment_amount : $order->amount;
        try {
            $response = $sberComponent->setOrder($order, intval($order->amount * 100), $order->getDescriptionSber(), $user->id, $email, false, $order->orderNumber);
        } catch (\Throwable $e) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return new ApiResponse(true, 'Произошла ошибка, повторите еще раз попозже');
        }


        if ($response) {
            $order->orderNumber = $response->orderId;
            $order->is_installment = false;
            $order->email = $email;
            if (!$order->save()) throw new \Exception('Произошла ошибка');

            return $this->redirect($response->formUrl);
        }
    }

    /**
     * @brief Оплата выставление счета по сбп
     * @param string $code
     * @param string $email
     * @return false|ApiResponse|string
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     * @throws \Exception
     */
    public function actionSetInvoiceOrderSbp(string $code, string $email, bool $autoRenewal = false)
    {
        $user = User::getCurrent();
        if($user->status != User::STATUS_ACTIVE) throw new BadRequestHttpException('Оплачивать могут только авторизованные пользователи');
        $invoice = Invoice::find()
            ->where('deleted_at IS NULL')
            ->andWhere(['url' => $code])
            ->andWhere('status NOT IN (:cancel, :payment) OR status IS NULL', [
                ':cancel' => Invoice::STATUS_CANCEL,
                ':payment' => Invoice::STATUS_PAYMENT,
            ])
            ->one();
        /** @var Invoice $invoice */

        if (!$invoice) throw new NotFoundHttpException('Инвойс не найден');
        if ($invoice->user_id && $invoice->user_id != $user->id) throw new ForbiddenHttpException('У Вас нет прав доступа');
        if (!$invoice->validateCart() && $invoice->type != Invoice::TYPE_SURCHARGE) {
            Yii::$app->session->setFlash('warning', 'Необходимо убрать из корзины активированный курс');
            return $this->goBack();
        }
        if ($autoRenewal) {
            if (!$invoice->setAutoreneval()) throw new \Exception('Произошла ошибка');
        }
        $order = $invoice->getOrder($user);
        if (!$order) throw new \Exception('Произошла ошибка');

        $sbpComponent = new SbpComponent();

        $response = $sbpComponent->setOrder($order, intval($order->amount * 100), $order->getDescriptionSber(), $user->id, $email, false);

        if ($response?->rq_uid && $response?->order_id) {

            $order->is_installment = false;
            $order->email = $email;
            $order->sbp_rquid = $response->rq_uid;
            $order->sbp_order_id = $response->order_id;

            if (!$order->save()) throw new \Exception('Произошла ошибка');

            return json_encode($response);
        }
    }

    /**
     * @brief Оплата выставление счета по сбп
     * @param string $code
     * @param string $email
     * @return false|ApiResponse|string
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     * @throws \Exception
     */
    public function actionSetInvoiceOrderTbankInstallment(string $code, string $email)
    {
        $user = User::getCurrent();
        if($user->status != User::STATUS_ACTIVE) throw new BadRequestHttpException('Оплачивать могут только авторизованные пользователи');
        $invoice = Invoice::find()
            ->where('deleted_at IS NULL')
            ->andWhere(['url' => $code])
            ->andWhere('status NOT IN (:cancel, :payment) OR status IS NULL', [
                ':cancel' => Invoice::STATUS_CANCEL,
                ':payment' => Invoice::STATUS_PAYMENT,
            ])
            ->one();
        /** @var Invoice $invoice */

        if (!$invoice) throw new NotFoundHttpException('Инвойс не найден');
        if ($invoice->user_id && $invoice->user_id != $user->id) throw new ForbiddenHttpException('У Вас нет прав доступа');
        if (!$invoice->validateCart() && $invoice->type != Invoice::TYPE_SURCHARGE) {
            Yii::$app->session->setFlash('warning', 'Необходимо убрать из корзины активированный курс');
            return $this->goBack();
        }

        $order = $invoice->getOrder($user);
        if (!$order) throw new \Exception('Произошла ошибка');

        $tbankComponent = new TbankInstallmentComponent();

        $response = $tbankComponent->setOrder($order, $order->amount, $order->getDescriptionSber(), $email);

        if ($response) {
            
            $order->email = $email;
            $order->tbank_installment_id = $response->id;

            if (!$order->save()) throw new \Exception('Произошла ошибка');

            return json_encode($response);
        }
    }

    /**
     * @brief Оплата выставление счета по сбербанку
     * @param string $code
     * @param string $email
     * @return void|\yii\web\Response
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function actionSetInvoiceOrderSberInstallment(string $code, string $email, bool $autoRenewal = false)
    {
        $user = User::getCurrent();
        if($user->status != User::STATUS_ACTIVE) throw new BadRequestHttpException('Оплачивать могут только авторизованные пользователи');
        $invoice = Invoice::find()
            ->where('deleted_at IS NULL')
            ->andWhere(['url' => $code])
            ->andWhere('status NOT IN (:cancel, :payment) OR status IS NULL', [
                ':cancel' => Invoice::STATUS_CANCEL,
                ':payment' => Invoice::STATUS_PAYMENT,
            ])
            ->one();
        /** @var Invoice $invoice */

        if (!$invoice) throw new NotFoundHttpException('Инвойс не найден');
        if ($invoice->user_id && $invoice->user_id != $user->id) throw new ForbiddenHttpException('У Вас нет прав доступа');
        if (!$invoice->validateCart() && $invoice->type != Invoice::TYPE_SURCHARGE) {
            Yii::$app->session->setFlash('warning', 'Необходимо убрать из корзины активированный курс');
            return $this->goBack();
        }
        if ($autoRenewal) {
            if (!$invoice->setAutoreneval()) throw new \Exception('Произошла ошибка');
        }
        $order = $invoice->getOrder($user);
        if (!$order) throw new \Exception('Произошла ошибка');

        $sberComponent = new SberInstallmentComponent();

        $response = $sberComponent->setOrder($order, intval($order->amount * 100), $order->getDescriptionSber(), $user->id, $email, false, $order->orderNumber);

        if ($response) {
            $order->orderNumber = $response->orderId;
            $order->is_installment = true;
            $order->email = $email;
            if (!$order->save()) throw new \Exception('Произошла ошибка');
            return $this->redirect($response->formUrl);
        }
    }

    /**
     * @brief Оплата выставление счета по сбербанку
     * @param string $code
     * @param string $email
     * @return ApiResponse|void|Response
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function actionSetInvoiceOrderYandex(string $code, string $email, bool $autoRenewal = false)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = User::getCurrent();
        if($user->status != User::STATUS_ACTIVE) throw new BadRequestHttpException('Оплачивать могут только авторизованные пользователи');
        $invoice = Invoice::find()
            ->where('deleted_at IS NULL')
            ->andWhere(['url' => $code])
            ->andWhere('status NOT IN (:cancel, :payment) OR status IS NULL', [
                ':cancel' => Invoice::STATUS_CANCEL,
                ':payment' => Invoice::STATUS_PAYMENT,
            ])
            ->one();
        /** @var Invoice $invoice */

        if (!$invoice) throw new NotFoundHttpException('Инвойс не найден');
        if ($invoice->user_id && $invoice->user_id != $user->id) throw new ForbiddenHttpException('У Вас нет прав доступа');
        if (!$invoice->validateCart() && $invoice->type != Invoice::TYPE_SURCHARGE) {
            Yii::$app->session->setFlash('warning', 'Необходимо убрать из корзины активированный курс');
            return $this->goBack();
        }
        if ($autoRenewal) {
            if (!$invoice->setAutoreneval()) throw new \Exception('Произошла ошибка');
        }
        $order = $invoice->getOrder($user);
        if (!$order) throw new \Exception('Произошла ошибка');

        if ($order->yandex_pay) {
            return new ApiResponse(false, $order->yandex_pay);
        }
        $yandexPayComponent = new YandexPayComponent();
        $redirect = $yandexPayComponent->getPaymentUrl($order);

        if ($redirect) {
            $order->yandex_pay = $redirect;
            $order->email = $email;
            if (!$order->save()) throw new \Exception('Произошла ошибка');
            return new ApiResponse(false, $redirect);
        }
    }

    /**
     * @brief Регистрация по ajax
     * @return ApiResponse|ApiResponseException
     */
    public function actionSignup()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = Yii::$app->request;

            $model = new SignupForm([
                'phone' => $request->post('phone'),
                'email' => $request->post('email'),
                'password' => $request->post('password'),
                'name' => $request->post('name'),
                'lastName' => $request->post('lastName'),
                'type_registration' => User::TYPE_REGISTRATION_EMAIL,
            ]);

            if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);

            if ($model->register()) {
                return $this->confirmCode($model->getUser(), null, true);
            }
            return new ApiResponse(true, 'Произошла ошибка');
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Установит номер телефона
     * @return ApiResponse|ApiResponseException
     */
    public function actionSetPhone(string $typeSend = 'call')
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = Yii::$app->request;
            $user = User::getCurrent();
            if ($user->status != User::STATUS_NO_AUTORIZATION) throw new ForbiddenHttpException();
            $phone = Yii::$app->request->post('phone');
            $code = $request->post('code');
            $model = new RegistrationForm();
            $model->scenario = RegistrationForm::SCENARIO_PHONE;
            $model->phone = $phone;

            if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);

            if (!$code) {
                if ($model->setPhone($user)) return $this->confirmCode($user, null, true, $typeSend);
            } else {
                $user->setScenario(User::SCENARIO_UPDATE_PHONE);

                if (!$user->needConfirmPhone) {
                    return new ApiResponse(true, ['message' => 'Нельзя подтвердить телефон'], 406);
                }

                $model = new ConfirmPhone($user);
                $model->code = $code;

                if (!$user->getConfirmPhoneSms()) {
                    if (!$user->sendConfirmPhoneSms(null, $typeSend)) {
                        return new ApiResponse(true, ['message' => $typeSend == 'sms' ? 'Не удалось отправить смс-сообщение' : 'Не удалось дозвониться'], 406);
                    }
                }

                if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);

                if (!$user->setPhoneConfirmed()) throw new \Exception('Код не подтвердился');
                return new ApiResponse(false, 'Номер подтвердился');
            }
            return new ApiResponse(true, 'Произошла ошибка');
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Авторизация по ajax
     * @return ApiResponse
     * @throws BadRequestHttpException
     * @throws \yii\base\Exception
     */
    public function actionLogin(string $typeSend = null)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $request = Yii::$app->request;
        $model = new LoginForm();
        $model->username = $request->post('username');
        $model->password = $request->post('password');
        $model->code = $request->post('code');

        if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);

        $user = $model->getUser();
        if (!$typeSend) {
            if ($model->getType() == LoginForm::TYPE_EMAIL || ($model->getType() == LoginForm::TYPE_PHONE && !$model->code)) {
                if (!$model->password && !$model->code) {
                    return new ApiResponse(false, [
                        'fullname' => $user->getBowedFullName(TextHelper::CASE_NOMENATIVE, false, false),
                        'avatar' => $user->getAvatarPath(true),
                        'phone' => $user->getCutPhone(),
                        'isEmail' => boolval($user->email),
                    ]);
                }

                if ($model->code) {
                    $phoneConfirmation = new PhoneConfirmation(['user' => $user, 'scenario' => PhoneConfirmation::SCENARIO_CODE]);
                    $phoneConfirmation->code = $model->code;
                    return $this->phoneConfirmate($phoneConfirmation, false, $user);
                }
                $currentUser = User::getCurrent();
                Yii::$app->user->login($user, 3600 * 24 * 30);

                //Если у пользователя есть последняя utm метка с пометкой "Открывать курсы не только при регистрации"
                $lastUtm = $currentUser->getLastUtm();
                if ($lastUtm && $lastUtm->redirect_id) {
                    $routeConfig = RouteSetting::findRedirect($lastUtm->redirect_id);
                    if ($routeConfig && !$routeConfig->is_open_course_registration && $routeConfig->isCountOpen($user)) {
                        $routeConfig->activateCourse($user);
                    }
                }

                $user->setUtmUser($_COOKIE['cookie_auth']);

                if (isset($_COOKIE['cookie_auth'])) {
                    setcookie( "cookie_auth", "", time()- 3600, "/","", 0);
                }
                return new ApiResponse(false, true);
            }
        }

        return $this->confirmCode($user, (string)$model->code, false, $typeSend ?? 'call');
    }

    /**
     * @brief Сброс пароля
     * @return ApiResponse|ApiResponseException
     */
    public function actionPasswordReset()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {

            $username = Yii::$app->request->post('username');
            if (strpos($username, '@')) {
                $user = User::findOne(['email' => $username, 'status' => User::STATUS_ACTIVE]);
            } else {
                $phone = TextHelper::normalizePhone($username);
                $user = User::findOne(['phone' => $phone, 'status' => User::STATUS_ACTIVE]);
            }

            if (!$user) throw new NotFoundHttpException('Нет такого пользователя');
            if(!$user->email) return new ApiResponse(true, ['message' => 'У тебя не привязана почта. Сбросить пароль не получиться.'], 406);
            if ($user->last_password_send > time() - PhoneConfirmation::PAUSE_BETWEEN_QUERY) {
                return new ApiResponse(true, [
                    'message' => 'Вы уже пытались сбросить пароль. Можете повторить через: ' . ($user->last_password_send - (time() - PhoneConfirmation::PAUSE_BETWEEN_QUERY)) . ' секунд',
                    'time' => ($user->last_password_send - (time() - PhoneConfirmation::PAUSE_BETWEEN_QUERY)),
                ]);
            }

            $model = new PasswordResetNewForm();
            $model->email = $user->email;

            if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);

            return $model->reset()
                ? new ApiResponse(false, $user->email)
                : new ApiResponse(true, ['message' => 'Не удалось отправить письмо на email']);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Отправка кода по смс если указан email
     * @return ApiResponse|ApiResponseException
     */
    public function actionSendCodeEmail()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = Yii::$app->request;
            $user = User::findOne(['email' => $request->post('email'), 'status' => User::STATUS_ACTIVE]);
            if (!$user->phone) return new ApiResponse(true, ['message' => 'Не указан номер телефона'], 406);
            if (!$user->confirmed_phone_at) return new ApiResponse(true, ['message' => 'Номер телефона уже не актуальный']);

            return $this->confirmCode($user, (string)$request->get('code'), false);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Подтверждение кода
     * @param User $user
     * @param string|null $requestCode
     * @return ApiResponse
     * @throws BadRequestHttpException
     */
    private function confirmCode(User $user, string $requestCode = null, bool $confirmation, string $typeSend = ConfirmPhoneSms::TYPE_SEND_CALL):ApiResponse
    {
        $phoneConfirmation = new PhoneConfirmation(['user' => $user, 'scenario' => PhoneConfirmation::SCENARIO_CODE]);
        if (!$requestCode) {
//            if (($code = $phoneConfirmation->getCode()) && $code->created_at > time() - PhoneConfirmation::PAUSE_BETWEEN_QUERY) {
            $secondPause = ($typeSend == ConfirmPhoneSms::TYPE_SEND_CALL ? PhoneConfirmation::PAUSE_CALL_BETWEEN_QUERY : PhoneConfirmation::PAUSE_SMS_BETWEEN_QUERY);
            if (($code = $phoneConfirmation->getCode($typeSend)) && $code->created_at > time() - $secondPause) {
                return new ApiResponse(true, [
                    'message' => 'Вы недавно запрашивали код. Повторно запросить можно будет через: ' . ($code->created_at - (time() - $secondPause)) . ' секунд',
                    'time' => ($code->created_at - (time() - $secondPause)),
                ]);
            }

            $message = $typeSend == ConfirmPhoneSms::TYPE_SEND_SMS
                ? 'Не удалось отправить смс на указанный номер.'
                : 'Не удалось дозвониться на указанный номер.';
            return $phoneConfirmation->sendCode($typeSend)
                ? new ApiResponse(false, [
                        'time' => $secondPause,
                        'fullname' => $user->getBowedFullName(TextHelper::CASE_NOMENATIVE, false, false),
                        'avatar' => $user->getAvatarPath(true),
                        'phone' => $user->getCutPhone(),
                        'isEmail' => boolval($user->email),
                    ])
                : new ApiResponse(true, ['message' => $message . ' Проверьте правильность номера.']);
        } else {
            $phoneConfirmation->code = $requestCode;
            return $this->phoneConfirmate($phoneConfirmation, $confirmation, $user);
        }
    }

    /**
     * @btief Проверить код
     * @param PhoneConfirmation $phoneConfirmation
     * @param bool $confirmation
     * @param User $user
     * @return ApiResponse
     * @throws \Exception
     */
    private function phoneConfirmate(PhoneConfirmation $phoneConfirmation, bool $confirmation, User $user):ApiResponse
    {
        $confirmationFunction = $confirmation ? $phoneConfirmation->confirmAttempt() : $phoneConfirmation->attemptSuccess();
        if ($confirmationFunction) {
            Yii::$app->user->login($user, 3600 * 24 * 30);
            if (isset($_COOKIE['cookie_auth'])) {
                setcookie( "cookie_auth", "", time()- 3600, "/","", 0);
            }
            return new ApiResponse(false, true);
        }

        return new ApiResponse(true, $phoneConfirmation->errors);
    }

    /**
     * @brief Установит пароль
     * @return ApiResponse|ApiResponseException
     */
    public function actionSetPassword()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = Yii::$app->request;
            $user = User::getCurrent();
            if ($user->status != User::STATUS_NO_AUTORIZATION) throw new ForbiddenHttpException();
            $model = new UpdatePasswordForm($user);
            $model->scenario = UpdatePasswordForm::SCENARIO_REGISTRATION;
            $model->newPassword = $request->post('newPassword');
            $model->repeatPassword = $request->post('repeatPassword');


            if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);
            if (!$model->setPassword()) throw new \Exception('Не удалось установить пароль');
            $subjectList = [];
            foreach (RegistrationSubject::subjectExam() as $subject) {
                $subjectList[] = Course::responseSubject($subject);
            }

            $classList = [];
            foreach (RegistrationSubject::$classList as $key => $class) {
                $classList[] = RegistrationSubject::responseClass($key);
            }

            return new ApiResponse(false, [
                'subjectList' => $subjectList,
                'text' => RegistrationSubject::getText(),
                'classList' => $classList,
            ]);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Новая регистрация
     * @return ApiResponse|ApiResponseException
     */
    public function actionSignupNew(int $task_id = null, int $wiki_id = null)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = Yii::$app->request;
            $user = User::getCurrent();
            if ($user->status != User::STATUS_NO_AUTORIZATION) throw new ForbiddenHttpException();

            $registrationForm = new RegistrationForm();
            $registrationForm->scenario = RegistrationForm::SCENARIO_SIGNUP;

            $jobBankAsk = null;
            $wiki = null;
            if ($task_id) {
                $jobBankAsk = JobBankAsk::findOne($task_id);
                if (!$jobBankAsk) throw new NotFoundHttpException('Нет такого вопроса');
            }
            if ($wiki_id) {
                $wiki = KnowledgeLevels::findOne($wiki_id);
                if (!$wiki) throw new NotFoundHttpException('Нет такой статьи');
            }
            $registrationForm->subjects = $request->post('subjects');
            $registrationForm->class = $request->post('class');
            $registrationForm->demo = $request->get('demo');
            $registrationForm->jobBankAsk = $jobBankAsk;
            $registrationForm->wiki = $wiki;

            if (!$registrationForm->validate()) return new ApiResponse(false, $registrationForm->errors, 406);

            return $registrationForm->signup($user)
                ? new ApiResponse(false, true)
                : new ApiResponse(true, 'Произошла ошибка');
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Отмена регистрации
     * @return ApiResponse|ApiResponseException
     */
    public function actionCancelRegistration()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $user = User::getCurrent();
            if ($user->status != User::STATUS_NO_AUTORIZATION) throw new ForbiddenHttpException();
            $registrationForm = new RegistrationForm();
            return $registrationForm->cancel($user)
                ? new ApiResponse(false, true)
                : new ApiResponse(true, 'Произошла ошибка');
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Подтверждение номера телефона
     * @return ApiResponseException|ApiResponse
     * @throws \Exception
     */
    public function actionConfirmPhoneNew()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = Yii::$app->request;
            $user = User::find()->where(['phone' => TextHelper::normalizePhone($request->post('phone'))])->one();

            if (!$user) throw new NotFoundHttpException('Нет такого пользователя');
            $user->setScenario(User::SCENARIO_UPDATE_PHONE);

            if (!$user->needConfirmPhone) {
                return new ApiResponse(true, ['message' => 'Нельзя подтвердить телефон'], 406);
            }

            $model = new ConfirmPhone($user);
            $model->code = $request->post('code');

            if (!$user->getConfirmPhoneSms()) {
                if (!$user->sendConfirmPhoneSms()) {
                    return new ApiResponse(true, ['message' => 'Не удалось отправить смс-сообщение'], 406);
                }
            }

            if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);

            if (!$user->setPhoneConfirmed()) throw new \Exception('Код не подтвердился');
            if (isset($_COOKIE['cookie_auth'])) {
                $user->setUtmUser($_COOKIE['cookie_auth']);
            }
            Yii::$app->user->login($user, 3600 * 24 * 30);
            if (isset($_COOKIE['cookie_auth'])) {
                setcookie( "cookie_auth", "", time()- 3600, "/","", 0);
            }
            return new ApiResponse(false, ['email' => $user->email]);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Определяет какой шаг регистрации
     * @return ApiResponse|ApiResponseException
     */
    public function actionStep()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $user = User::getCurrent();
            if ($user->status != User::STATUS_NO_AUTORIZATION) throw new ForbiddenHttpException();

            $profile = $user->profile;

            $subjectList = [];
            foreach (RegistrationSubject::subjectExam() as $subject) {
                $subjectList[] = Course::responseSubject($subject);
            }

            $classList = [];
            foreach (RegistrationSubject::$classList as $key => $class) {
                $classList[] = RegistrationSubject::responseClass($key);
            }

            return new ApiResponse(false, [
                'name' => $profile->name,
                'lastname' => $profile->last_name,
                'phone' => $user->phone,
                'isConfirmedPhone' => !!$user->confirmed_phone_at,
                'isPassword' => !!$user->password_hash,
                'questionnaire' => [
                    'subjectList' => $subjectList,
                    'text' => RegistrationSubject::getText(),
                    'classList' => $classList,
                ],
            ]);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Получить форму для выбора предмета и класса
     * @param int|null $class
     * @return ApiResponse|ApiResponseException
     */
    public function actionRegistrationSubject(int $class = null)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $subjectList = [];
            foreach (RegistrationSubject::subjectExam($class) as $subject) {
                $subjectList[] = Course::responseSubject($subject);
            }

            $classList = [];
            foreach (RegistrationSubject::$classList as $key => $item) {
                $classList[] = RegistrationSubject::responseClass($key);
            }

            return new ApiResponse(false, [
                'subjectList' => $subjectList,
                'classList' => $classList,
                'text' => RegistrationSubject::getText(),
            ]);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Получить форму для выбора предмета и класса
     * @param int $id
     * @param $type
     * @return ApiResponse|ApiResponseException
     */
    public function actionRegistrationTask(int $id, $type)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $user = User::getCurrent();
            $jobBankAsk = JobBankAsk::findOne($id);
            $model = new RegistrationTask([
                'jobBankAsk' => $jobBankAsk,
                'user' => $user,
            ]);

            $request = Yii::$app->request;
            $model->class = $request->post('class');
            $model->subjects = $request->post('subjects');

            if (!$model->validate()) return new ApiResponse(false, $model->errors, 406);

            return $model->save($type)
                ? new ApiResponse(false, true)
                : new ApiResponse(true, 'Произошла ошибка');
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Установит пароль для taskView и зарегестрироваться
     * @param int $type
     * @param int $id
     * @return ApiResponse|ApiResponseException
     */
    public function actionSetPasswordTask(int $type, int $id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $request = Yii::$app->request;

            $user = User::getCurrent();
            if ($user->status != User::STATUS_NO_AUTORIZATION) throw new ForbiddenHttpException();
            if (!in_array($type, [RegistrationTaskView::BANNER_NO, RegistrationTaskView::BANNER_YES, RegistrationTaskView::BANNER_POPUP])) throw new NotFoundHttpException('Нет такого типа баннера');
            $jobBankAsk = JobBankAsk::findOne($id);
            if (!$jobBankAsk) throw new NotFoundHttpException('Нет такого вопроса');
            $model = new PasswordTaskForm($user, $jobBankAsk);
            $model->newPassword = $request->post('newPassword');
            $model->repeatPassword = $request->post('repeatPassword');


            if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);
            if (!$model->setPassword($type)) throw new \Exception('Не удалось установить пароль');

            return new ApiResponse(false, true);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }

    /**
     * @brief Экшен редиректов
     * @return Response
     * @throws \Exception
     */
    public function actionRedirected()
    {
        $routeSetting = new RouteSetting();
        $request = Yii::$app->request;
//        $url = ltrim(Yii::$app->controller->request->url, '/');
        $route = $routeSetting->fileToClass();
        if (isset($route[$request->pathInfo])) {
            $setting = $route[$request->pathInfo];

            $utmSource = $setting->utm_source;
            $utmMedium = $setting->utm_medium;
            $utmCampaign = $setting->utm_campaign;
            $utmContent = $setting->utm_content;
            $utmTerm = $setting->utm_term;
            if (
                (is_null($setting->utm_source) || trim($setting->utm_source) == '')
                && (is_null($setting->utm_medium) || trim($setting->utm_medium) == '')
                && (is_null($setting->utm_campaign) || trim($setting->utm_campaign) == '')
                && (is_null($setting->utm_content) || trim($setting->utm_content) == '')
                && (is_null($setting->utm_term) || trim($setting->utm_term) == '')
            ) {
                $utmSource = $request->get('utm_source');
                $utmMedium = $request->get('utm_medium');
                $utmCampaign = $request->get('utm_campaign');
                $utmContent = $request->get('utm_content');
                $utmTerm = $request->get('utm_term');
            }

            $user = User::getCurrent();
            if ($user->status == User::STATUS_ACTIVE && $setting->redirect_id && $request->pathInfo == 'podgotovka_mo') {
                $setting->activateCourse($user);
                $user->is_popup_podgotovka = true;
                $user->save();
            }

            $profile = $user->profile;
            if ($user->status == User::STATUS_ACTIVE && $setting->student_teacher && !$profile->type_user) {
                $profile->scenario = Profile::SCENARIO_TYPE_USER;
                $profile->type_user = Profile::TYPE_USER_SET;
                $profile->saveStrict();
            }

            if (!$setting->is_open_course_registration && $user->status == User::STATUS_ACTIVE && $setting->isCountOpen($user)) {
                $utm = $user->getLastUtm();
                $setting->activateCourse($user, $utm);
            }
            return $this->redirect([$setting->url,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => $utmCampaign,
                'utm_content' => $utmContent,
                'utm_term' => $utmTerm,
                'redirect_id' => $setting->redirect_id ?? null,
                'sign-up' => $setting->is_registration,
                'is_demo' => $setting->demo ? 1 : 0,
            ], 301);
        }
        return $this->redirect(['/']);
    }

    /**
     * @brief Сохранить utm метку при клике
     * @return ApiResponse
     * @throws \Exception
     */
    public function actionClickBanner()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $user = User::getCurrent();
        $utm = new Utm([
            'user_id' => $user->id,
        ]);

        $utm->utm_source = 'banner';
        $utm->utm_medium = 'freemg';
        return $utm->saveStrict()
            ? new ApiResponse(false, 'utm метки сохранились')
            : new ApiResponse(true, 'Произола ошибка');
    }

    /**
     * @brief Авторизоваться|Зарегестрировать пользователю по номеру телефона
     * @return ApiResponse|ApiResponseException
     * @throws \Throwable
     */
    public function actionAuthPhone()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $currentUser = User::getCurrent();
            if ($currentUser->status != User::STATUS_NO_AUTORIZATION) throw new ForbiddenHttpException();

            $request = Yii::$app->request;

            $model = new ShortLogin();
            $model->phone = $request->post('phone');
            $model->code = $request->post('code');

            if (!$model->validate()) return new ApiResponse(true, $model->errors, 406);

            $user = $model->getUser();
            if (!$user) throw new BadRequestHttpException();
            if ($model->phone && !$model->code) return $this->confirmCode($user, null, true, ConfirmPhoneSms::TYPE_SEND_SMS);

            $user->setScenario(User::SCENARIO_UPDATE_PHONE);

            if ($user->status == User::STATUS_NO_AUTORIZATION && !$user->needConfirmPhone) {
                return new ApiResponse(true, ['message' => 'Нельзя подтвердить телефон'], 406);
            }

            if ($user->status == User::STATUS_NO_AUTORIZATION) {
                if (!$user->setPhoneConfirmed()) throw new \Exception('Код не подтвердился');
                $model->registrationUser();
            } else {
                $cart = $currentUser->getCart();
                if ($cart) {
                    if (!$cart->transferUser($user)) throw new \Exception('Произошла ошибка при присвоению корзины пользователю');
                }
            }
            Yii::$app->user->login($user, 3600 * 24 * 30);

            return new ApiResponse(false, [
                'id' => $user->id,
                'name' => $user->getBowedName(),
                'avatar' => $user->getAvatarPath(),
            ]);
        } catch (\Exception $e) {
            return new ApiResponseException($e);
        }
    }
}
