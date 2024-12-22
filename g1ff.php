<?php

namespace common\models;

use backend\components\TelegramNew;
use backend\components\TelegramOld;
use common\base\ActiveRecord;
use common\components\CloudPaymentComponent;
use common\components\postback\AdvCakeComponent;
use common\helpers\ErrorLog;
use frontend\components\cloudKassir\CloudKassir;
use frontend\components\RussianFormatter;
use frontend\components\sber\OrderStatus;
use frontend\models\BindCard;
use Yii;
use frontend\components\yandex\Order as OrderYandex;
use yii\db\Exception;

/**
 * This is the model class for table "order".
 *
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 * @property float $amount
 * @property int $invoice_id
 * @property int $created_at
 * @property int $accepted_at
 * @property int $accepted_by
 * @property string|null $email
 * @property string|null $hash
 * @property int|null $cloud_payment_callback_id
 * @property int|null $card_confirmation
 * @property string|null $orderNumber
 * @property int|null $payment_type
 * @property string|null $yandex_pay
 * @property string|null $payment_id
 * @property int|null $is_installment
 * @property string|null $sbp_rquid
 * @property string|null $sbp_order_id
 * @property string|null $tbank_installment_id
 *
 * @property User $user
 * @property Course $course
 * @property Invoice $invoice
 * @property string $urlToPay
 * @property-read CloudPaymentsCallback $cloudPaymentCallback
 */
class Order extends ActiveRecord
{
    const SCENARIO_ACTIVATE_COURSE = 'activateCourse';

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_ACTIVATE_COURSE] = ['user_id', 'course_id'];

        return $scenarios;
    }

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'order';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            ['created_at', 'default', 'value' => time()],
            [['card_confirmation'], 'boolean'],
            [['user_id', 'amount', 'created_at'], 'required'],
            [['user_id', 'course_id', 'created_at', 'accepted_at', 'accepted_by', 'invoice_id', 'cloud_payment_callback_id', 'payment_type'], 'integer'],
            [['amount'], 'double', 'min' => 0, 'on' => self::SCENARIO_DEFAULT],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::className(), 'targetAttribute' => ['user_id' => 'id']],
            [['course_id'], 'exist', 'skipOnError' => true, 'targetClass' => Course::className(), 'targetAttribute' => ['course_id' => 'id']],
            [['accepted_by'], 'exist', 'skipOnError' => true, 'targetClass' => User::className(), 'targetAttribute' => ['accepted_by' => 'id']],
            [['invoice_id'], 'exist', 'skipOnError' => true, 'targetClass' => Invoice::className(), 'targetAttribute' => ['invoice_id' => 'id']],
            [['email', 'hash', 'orderNumber','sbp_rquid','sbp_order_id','tbank_installment_id'], 'string'],
            [['yandex_pay', 'payment_id'], 'string', 'max' => 255],
            [['hash'], 'unique'],
            [['is_installment'], 'boolean'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'course_id' => 'Course ID',
            'amount' => 'Amount',
            'invoice_id' => 'Выставление счета',
            'created_at' => 'Created At',
            'accepted_at' => 'Accepted At',
            'accepted_by' => 'Accepted By',
            'cloud_payment_callback_id' => 'id Cloud Payment',
            'hash' => 'Hash',
            'orderNumber' => 'OrderNumber',
            'yandex_pay' => 'Yandex Pay',
            'payment_type' => 'Тип оплаты',
            'payment_id' => 'Id оплаты для поиска',
            'is_installment' => 'В рассрочку',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::className(), ['id' => 'user_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getCourse()
    {
        return $this->hasOne(Course::className(), ['id' => 'course_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getInvoice()
    {
        return $this->hasOne(Invoice::className(), ['id' => 'invoice_id']);
    }

    /**
     * @brief Поиск ордера по хэшу
     * @param string $hash
     * @return Order|null
     */
    public static function findByHash($hash)
    {
        if ($hash) {
            return self::find()->where(['hash' => $hash])->andWhere('accepted_at IS NULL')->one();
        }

        return null;
    }

    /**
     * @brief Акцепт заказа
     * @param bool $free
     * @param null $idCloudPayment
     * @return bool
     */
    public function accept($free = false)
    {
        $transaction = Yii::$app->db->beginTransaction();

        try {
            $user = $this->user;
            $this->accepted_at = time();

            if ($this->card_confirmation) {
                if (!$this->saveStrict()) throw new \Exception('Can not saved order on accepted. Validate error: ' . json_encode($this->errors));
                $transaction->commit();
                return true;
            }
            $invoice = $this->invoice;
            if ($course = $this->course) {
                if ($user->activateCourse($course, false, $course->isLevelUp(), $this->amount == 0, $this?->payment_type) && $this->saveStrict()) {
                    $user->createNotification(Notification::TYPE_SUCCESS,
                        'Активирован "' . Course::$typeList[$course->getTypeActual()]
                        . ' по ' . Course::$subjectList[$course->subject] . '"',
                        ['user-course/view', 'id' => $user->getUserCourse($course->id)->id]
                    );
                    if (!$free) {
                        $invoice = $this->invoice;
                        if ($invoice && $invoice->is_first) {
                            $invoice->note = 'каталог';
                        } else {
                            if ($this->payment_type == Course::PAYMENT_TYPE_HALF) {
                                $price = $course->price / Course::PAYMENT_TYPE_HALF;
                            } else {
                                $price = $course->type == Course::TYPE_MEETING ? $course->price_pro : $course->price;
                            }
                            $invoice = new Invoice([
                                'user_id' => $this->user_id,
                                'sum' => $price,
                                'course' => json_encode([$course->id]),
                                'url' => self::generateUrlInvoice(),
                                'note' => 'каталог',
                                'type' => Invoice::TYPE_FULL_PAYMENT,
                                'status' => Invoice::STATUS_PAYMENT,
                                'no_autoreneval' => true,
                            ]);
                        }

                        if ($course->type == Course::TYPE_SPEEDRUN && $this?->payment_type) {
                            $typeName = $this?->payment_type == Course::PAYMENT_TYPE_FULL ? 'полный' : 'неполный';
                            $invoice->note .= "\n" . Course::$subjectList[$course->subject] . ' - ' . $typeName;
                        }

                        $invoice->scenario = Invoice::SCENARIO_PAY_USER;
                        $invoice->saveStrict();

                        $this->invoice_id = $invoice->id;

                        $invoiceCourse = InvoiceCourse::findOne(['invoice_id' => $invoice->id, 'course_id' => $course->id]);
                        if (!$invoiceCourse) {
                            $invoiceCourse = new InvoiceCourse([
                                'invoice_id' => $invoice->id,
                                'course_id' => $course->id,
                                'payment_type' => $this?->payment_type,
                            ]);

                            $invoiceCourse->saveStrict();
                        }

                        if ($invoiceCourse->payment_type === Course::PAYMENT_TYPE_HALF && ($this?->payment_type === Course::PAYMENT_TYPE_HALF || $this?->payment_type === Course::PAYMENT_TYPE_FULL)) {
                            $invoiceCourse->payment_type = Course::PAYMENT_TYPE_FULL;
                            $invoiceCourse->saveStrict(false);
                        }

//                        CloudKassir::getCourse($this);
                        if ($user->isPreviouslyBought($course)) {
                            Yii::$app->telegramOld->sendMessageAboutBuyCourse($user, $course, $this->amount);
                        } else {
                            Yii::$app->telegramNew->sendMessageAboutBuyCourse($user, $course, $this->amount);
                        }
                    }

//                    $user->actualPayCourseSubscription($course, $this);
                    $transaction->commit();
                    return true;
                }

                $transaction->rollBack();
                return false;
            } elseif ($invoice) {
                if (!$invoice->creator && !$invoice->cart_id) {
                    $transaction->commit();
                    return true;
                }
                $courses = $invoice->getCourseInvoice();

                $isProlong = false;
                $bInvoice = false;

                $invoiceCreatorManager = false;
                if ($invoice->creator && !$invoice->cart_id) {

                    $creator = User::findOne($invoice->creator);

                    $prev = strtotime('-1 month', strtotime(date('15.m.Y 23:59:59')));
                    $prev = strtotime(date('t.m.Y 23:59:59', $prev));

                    $time = strtotime(date('15.m.Y 23:59:59'));
                    $time = strtotime(date('t.m.Y 23:59:59', $time));

                    if ($creator->isRole(User::ROLE_PERSONAL_MANAGER) || $creator->isRole(User::ROLE_POPM)) {
                        $invoiceCreatorManager = true;
                        $coursesInvoice = $invoice->getCourseInvoice();

                        $arrDate = [];
                        $countUserManager = UserManager::find()->where(['user_id' => $this->user_id])->count();
                        $countPrevMasterGroup = 0;//Нужна для проверки имеется мастер группа в предыдущем месяце, количество мастер групп в предыдущем месяце
                        $countAllMasterGroup = 0;//Количество всего мастер групп
                        foreach ($coursesInvoice as $courseInvoice) {
                            if ($courseInvoice->type == Course::TYPE_MASTER_GROUP) {
                                $countAllMasterGroup++;
                                $countUserCourseMasterGroup = UserCourse::find()->innerJoin('course', 'user_course.course_id = course.id')
                                    ->where(['course.type' => Course::TYPE_MASTER_GROUP, 'user_course.user_id' => $this->user_id])
                                    ->andWhere(['course.subject' => $courseInvoice->subject, 'course.exam' => $courseInvoice->exam])
                                    ->andWhere(['between', 'course.date_end', strtotime(date('1.m.Y 00:00:00', $prev)), $prev])
                                    ->count();

                                if ($countUserCourseMasterGroup) {//Если есть предыдущий месяце Мастер группа
                                    $countPrevMasterGroup++;
                                }
                            }

                            if ($courseInvoice->date_end <= $time && $courseInvoice->type != Course::TYPE_MASTER_GROUP) continue;

                            if ($courseInvoice->date_end > $time && $countUserManager) {
                                $invoiceCreatorManager = false;
                                continue;
                            }

                            $dateEnd = strtotime(date('t.m.Y 23:59:59', $courseInvoice->date_end));
                            if (!in_array($dateEnd, $arrDate)) {
                                array_push($arrDate, $dateEnd);
                            }
                        }
                        $countUserCourse = UserCourse::find()->innerJoin('course', 'user_course.course_id = course.id')
                            ->where(['not in', 'course_id', json_decode($invoice->course)])
                            ->andWhere(['<=', 'course.date_end', $prev])
                            ->andWhere(['course.type' => Course::TYPE_MASTER_GROUP])
                            ->andWhere(['user_course.user_id' => $this->user_id])
                            ->count();

                        if ($countUserCourse && $countUserManager) {
                            $invoiceCreatorManager = false;
                        }

                        if ($countAllMasterGroup && $countAllMasterGroup && $countAllMasterGroup == $countPrevMasterGroup) {//имеется ли значения по мастер группам и посмотреть количество равняется или нет что было в предыдущем месяце
                            $invoiceCreatorManager = false;
                        }

                        $countArr = count($arrDate);
                        if ($countArr == 1) {
                            $date = current($arrDate);
                            if ($date > $time && !$countUserManager) {
                                $invoiceCreatorManager = false;
                            }
                        }
//                        elseif ($countArr > 1) {
//                            foreach ($arrDate as $item) {
//                                if ($item > $time) {
//                                    $invoiceCreatorManager = false;
//                                }
//                            }
//                        }
                    } elseif (!$creator->isRole(User::ROLE_PERSONAL_MANAGER)) {
                        $coursesInvoice = json_decode($invoice->course);
                        $userCourses = UserCourse::find()
                            ->innerJoin('course', 'user_course.course_id = course.id')
                            ->where(['user_id' => $this->user_id])
                            ->andWhere(['not in', 'course_id', $coursesInvoice])
                            ->andWhere(['course.type' => Course::TYPE_MASTER_GROUP])
                            ->andWhere(['<=', 'course.date_end', $prev])
                            ->count();

                        if (!$userCourses) {
                            $invoiceCreatorManager = true;
                        }
                    }


                    $countPrevMasterGroup = 0;//Нужна для проверки имеется мастер группа в предыдущем месяце, количество мастер групп в предыдущем месяце
                    $countAllMasterGroup = 0;//Количество всего мастер групп
                    foreach ($courses as $course) {
                        if ($course->type == Course::TYPE_MASTER_GROUP) {
                            $countAllMasterGroup++;
                            $prevStart = date('1.m.Y 00:00:00', strtotime('-1 month', $course->date_start));//берем курсы прошлого месяца
                            $prevEnd = date('t.m.Y 23:59:59', strtotime($prevStart));
                            $countUserCourseMasterGroup = UserCourse::find()->innerJoin('course', 'user_course.course_id = course.id')
                                ->where(['course.type' => Course::TYPE_MASTER_GROUP, 'user_course.user_id' => $this->user_id])
                                ->andWhere(['course.subject' => $course->subject, 'course.exam' => $course->exam])
                                ->andWhere(['between', 'course.date_end', strtotime($prevStart), strtotime($prevEnd)])
                                ->count();

                            if ($countUserCourseMasterGroup) {//Если есть предыдущий месяце Мастер группа
                                $countPrevMasterGroup++;
                            }
                        }
                    }
                    if ($countAllMasterGroup && $countPrevMasterGroup == $countAllMasterGroup) {//Если в выставление счета есть все предметы прошлом месяца месяца, то связку с РОПМ не создаем
                        $bInvoice = true;
                    }
                }

                $stringCourse = '';
                $countCourses = count($courses);
                if ($invoice->type == Invoice::TYPE_SURCHARGE) {//Если тип счета "доплата" - связка должна создаться
                    $invoiceCreatorManager = true;
                }

                $courseIds = [];
                foreach ($courses as $key => $course) {
                    /** @var Course $course */
                    $courseIds[] = $course->id;
                    $stringCourse .= Course::$typeList[$course->type] . ' по ' . Course::$subjectList[$course->subject];
                    if($countCourses != ($key + 1)) {
                        $stringCourse .= ', ';
                    }

                    $invoiceModel = null;
                    $date = strtotime(date('t.m.Y 23:59:59'));
                    if (($course->type == Course::TYPE_MASTER_GROUP && strtotime(date('d.m.Y 23:59:59', $course->date_end)) >= $date) && !$bInvoice) {
                        $invoiceModel = $invoice;
                    }

                    $paymentType = null;
                    if ($course->type == Course::TYPE_SPEEDRUN) {
                        $invoiceCourse = InvoiceCourse::findOne(['course_id' => $course->id, 'invoice_id' => $this->invoice_id]);
                        $paymentType = $invoiceCourse->payment_type;
                    }

                    if ($user->activateCourse($course, $invoiceCreatorManager, $bInvoice, true, $paymentType, $course->rate) && $this->saveStrict()) {
                        $user->createNotification(Notification::TYPE_SUCCESS,
                            'Активирован "' . Course::$typeList[$course->type] . ' по ' . Course::$subjectList[$course->subject] . '"',
                            ['user-course/view', 'id' => $user->getUserCourse($course->id)->id]
                        );

                        if ($invoiceModel) {
                            $bInvoice = true;
                        }

//                        $user->actualPayCourseSubscription($course, $this);
                    }

                    if ($user->isPreviouslyBought(null, $courseIds)) {
                        $isProlong = true;
                    }

                    $countOrderCourse = OrderCourse::find()->where(['order_id' => $this->id, 'course_id' => $course->id])->count();
                    if (!$countOrderCourse) {
                        $orderCourse = new OrderCourse([
                            'order_id' => $this->id,
                            'course_id' => $course->id,
                        ]);

                        $orderCourse->saveStrict();
                    }
                }

                $invoice->user_id = $user->id;

                if (!in_array($invoice->status, [Invoice::STATUS_AUTO_RENEWAL, Invoice::STATUS_VERIFICATION])) {
                    $invoice->status = Invoice::STATUS_PAYMENT;
                }
                $invoice->scenario = Invoice::SCENARIO_ACCEPT;
                $invoice->saveStrict();

                if ($invoice->cart_id) {
                    $cart = $invoice->cart;
                    $cart->status = Cart::STATUS_ACCEPTED;
                    $cart->saveStrict();
                }

                $notReferral = false;
                if (!$invoice->cart_id && ($invoice->prepayment || $invoice->creatorUser->isRole(User::ROLE_PERSONAL_MANAGER))) $notReferral = true;
//                $parent1 = $user->parentsQuery()->andWhere('user_levels.depth = ul.depth - 1')->one();
                //Начисление реферальных вознаграждений
//                if ($parent1 && !$notReferral) {
//                    /**
//                     * @var $parent1 User
//                     */
//                    $description = 'Вознаграждение по реферальной программе за покупку по выставлению счета "' . $stringCourse . '". Купил ' . $user->getFullUserName() . '.';
//                    if ($parent1->augmentBalance($invoice->sum * 0.1, $description . ' Линия 1.')) {
//                        $parent1->createNotification(Notification::TYPE_INFO, 'Получено вознаграждение по реферальной программе', ['finance/']);
//                        if ($parent2 = $user->parentsQuery()->andWhere('user_levels.depth = ul.depth - 2')->one()) {
//                            /**
//                             * @var $parent2 User
//                             */
//                            if (!$parent2->augmentBalance($course->price * 0.05, $description . ' Линия 2.')) {
//                                throw new \Exception('Can not give remuneration to user ' . $parent2->id);
//                            }
//
//                            $parent2->createNotification(Notification::TYPE_INFO, 'Получено вознаграждение по реферальной программе', ['finance/']);
//                        }
//                    } else {
//                        throw new \Exception('Can not give remuneration to user ' . $parent1->id);
//                    }
//                }

//                CloudKassir::getInvoice($this);
                if($invoice && in_array($invoice->status, [Invoice::STATUS_VERIFICATION, Invoice::STATUS_AUTO_RENEWAL]) && !$invoice->is_first && !$invoice->no_autoreneval) {
                    $transaction->commit();
                    return true;
                }
                if ($isProlong) {
                    $telegramOld = Yii::$app->telegramOld;
                    /** @var TelegramOld $telegramOld */
                    !$invoice->creator ? $telegramOld->sendMessageAboutPayInvoiceCatalog($user, $invoice) : $telegramOld->sendMessageAboutPayInvoice($user, $invoice);
                } else {
                    $telegramNew = Yii::$app->telegramNew;
                    /** @var TelegramNew $telegramNew */
                    !$invoice->creator ? $telegramNew->sendMessageAboutPayInvoiceCatalog($user, $invoice) : $telegramNew->sendMessageAboutPayInvoice($user, $invoice);
                }

                $transaction->commit();
                return true;
            }
            if ($this->saveStrict()) {
                $description = 'Попaолнение баланса по заказу №' . $this->id;
                if ($user->augmentBalance($this->amount, $description)) {
                    $user->createNotification(Notification::TYPE_SUCCESS, 'Пополнен баланс на сумму ' . $this->amount . ' р.', ['finance/']);
//                    CloudKassir::replenishmentBalance($this);
                    if ($user->isPreviouslyBought()) {
                        Yii::$app->telegramOld->sendMessageAboutAugmentBalance($user, $this);
                    } else {
                        Yii::$app->telegramNew->sendMessageAboutAugmentBalance($user, $this);
                    }
                    $transaction->commit();
                    return true;
                } else {
                    throw new \Exception('Can not augment balance');
                }
            }
            throw new \Exception('Can not saved order on accepted. Validate error: ' . json_encode($this->errors));
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            $transaction->rollBack();
            return false;
        }
    }

    /**
     * @brief Генерация уникального url для Invoice
     * @return string
     * @throws \yii\base\Exception
     */
    public static function generateUrlInvoice(): string
    {
        $urlHash = Yii::$app->security->generateRandomString(24);
        if (Invoice::findOne(['url' => $urlHash])) {
            return self::generateUrlInvoice();
        }

        return $urlHash;
    }

    /**
     * @brief Генерирует хэш ордера
     * @return string
     * @throws \yii\base\Exception
     */
    public static function generateHash()
    {
        $hash = Yii::$app->security->generateRandomString(255);
        if (Order::findOne(['hash' => $hash])) {
            return self::generateHash();
        }

        return $hash;
    }

    /**
     * @brief url для оплаты
     * @return string|null
     */
    public function getUrlToPay()
    {
        if ($this->hash) {
            return Yii::$app->params['baseUrl'] . '/order?hash=' . $this->hash;
        }

        return null;
    }

    /**
     * @brief Вернет Public key cloud payments
     * @return string
     */
    public function pk()
    {
        if (Yii::$app->params['dev']) {
            return PaymentOrganization::$defaultTest['pk'];
        }
        if ($this->course_id) {
            return $this->course->pk();
        } elseif ($this->invoice_id) {
            return $this->invoice->pk();
        } else {
            return PaymentOrganization::$default['pk'];
        }
    }

    /**
     * @brief Вернет Api secret cloud payments
     * @return string
     */
    public function secret()
    {
        if (Yii::$app->params['dev']) {
            return PaymentOrganization::$defaultTest['apiSecret'];
        }
        if ($this->course_id) {
            return $this->course->secret();
        } elseif ($this->invoice_id) {
            return $this->invoice->secret();
        } else {
            return PaymentOrganization::$default['apiSecret'];
        }
    }

    /**
     * @brief Получить курсы по заказу
     * @return OrderCourse[]
     */
    public function getOrderCourse():array
    {
        return OrderCourse::findAll(['order_id' => $this->id]);
    }

    /**
     * @return CloudPaymentsCallback|null
     */
    public function getCloudPaymentCallback():?CloudPaymentsCallback
    {
        return CloudPaymentsCallback::findOne($this->cloud_payment_callback_id);
    }

    /**
     * @brief Оплата привязанной карты
     * @param string $email
     * @param CardCloudPayment $cardCloudPayment
     * @param bool $autoRenewal
     * @return bool
     * @throws \Exception
     */
    public function paymentLinkedCard(string $email, CardCloudPayment $cardCloudPayment, bool $autoRenewal):bool
    {
        $transaction = Yii::$app->db->beginTransaction();

        try {
            if ($autoRenewal) {
                $subscription = $this->getSubscriptionOrCreate($cardCloudPayment);
                if (!$subscription->setOrder($this)) throw new \Exception('Не удалось связать подписку с заказом');
                if ($this->invoice_id) {
                    $invoice = $this->invoice;
                    $invoice->status = Invoice::STATUS_AUTO_RENEWAL;
                    $invoice->saveStrict();
                    foreach (json_decode($this->invoice->course) as $course) {
                        $courseModel = Course::findOne($course);
                        if (!$courseModel) continue;
                        $invoiceCourse = InvoiceCourse::findOne(['course_id' => $courseModel->id, 'invoice_id' => $invoice->id]);

                        if (!$subscription->subscription($courseModel, $invoiceCourse->rate, $invoiceCourse->payment_type)) {
                            throw new \Exception('Не удалось привязать курс к подписке');
                        }
                    }
                } elseif ($this->course_id) {
                    if (!$subscription->subscription($this->course)) {
                        throw new \Exception('Не удалось привязать курс к подписке');
                    }
                }
                if (!$this->setSubscriptionHistory($subscription)) {
                    throw new \Exception('Не удалось цену назначить у подписки');
                }
            }

            $component = new CloudPaymentComponent([
                'pk' => $this->pk(),
                'secret' => $this->secret(),
            ]);
            if (!$component->payment($this->amount, $cardCloudPayment, $this, 'Оплата заказа №' . $this->id, $email)) {
                throw new \Exception('Произошла ошибка при покупки привязанной карты');
            }

            $transaction->commit();
            return true;
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            $transaction->rollBack();
            return false;
        }
    }

    /**
     * @brief Получить привязку
     * @return bool
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function getBindOrder():bool
    {
        $bindCard = BindCard::findOne(['order_id' => $this->id]);

        if (!$bindCard) {
            $bindOrder = new BindCard([
                'order_id' => $this->id,
            ]);
        }

        return !$bindOrder->bindCard($this);
    }

    /**
     * @brief Подписаться на подписку
     * @return Subscription|null
     */
    public function setSubscription():?Subscription
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $subscription = $this->getSubscriptionOrCreate();

            if (!$subscription->setOrder($this)) throw new \Exception('Не удалось связать подписку с заказом');

            if ($this->invoice_id) {
                $invoice = $this->invoice;
                $invoice->status = Invoice::STATUS_AUTO_RENEWAL;
                $invoice->scenario = Invoice::SCENARIO_PAY_USER;
                $invoice->saveStrict();
                foreach ($invoice->invoiceCourse() as $invoiceCourse) {
                    $course = $invoiceCourse->course;

                    if (!$subscription->subscription($course, $invoiceCourse->rate, $invoiceCourse->payment_type)) {
                        throw new \Exception('Не удалось привязать курс к подписке');
                    }
                }
            } elseif ($this->course_id) {
                if (!$subscription->subscription($this->course)) {
                    throw new \Exception('Не удалось привязать курс к подписке');
                }
            }
            if (!$this->setSubscriptionHistory($subscription)) {
                throw new \Exception('Не удалось цену назначить у подписки');
            }
            $transaction->commit();
            return $subscription;
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            $transaction->rollBack();
            return null;
        }
    }

    /**
     * @brief Установить цену у подписки
     * @param Subscription $subscription
     * @return bool
     * @throws \Exception
     */
    public function setSubscriptionHistory(Subscription $subscription):bool
    {
        $subscriptionHistory = SubscriptionHistory::find()
            ->where(['subscription_id' => $subscription->id])
            ->orderBy(['created_at' => SORT_DESC])
            ->one();
        /** @var SubscriptionHistory $subscriptionHistory */

        $amount = 0;
        if ($this->card_confirmation) {
            foreach (json_decode($this->invoice->course) as $id) {
                $course = Course::findOne($id);
                $amount += $course->price;
            }
        } else {
            $amount = $this->amount;
        }
        if ($subscriptionHistory) {
            $subscriptionHistory->price = $subscriptionHistory->price + $amount;
        } else {
            $subscriptionHistory = new SubscriptionHistory([
                'subscription_id' => $subscription->id,
                'price' => $amount,
                'date' => strtotime(date('25.m.Y 00:00:00')),
            ]);
        }

        if (!$subscriptionHistory->saveStrict()) return false;

        $invoice = $this->invoice;
        $invoice->status = Invoice::STATUS_AUTO_RENEWAL;
        $invoice->subscription_history_id = $subscriptionHistory->id;
        return $invoice->saveStrict();
    }

    /**
     * @brief Получить или создать подписку
     * @param CardCloudPayment|null $cardCloudPayment
     * @return Subscription
     * @throws \Exception
     */
    public function getSubscriptionOrCreate(CardCloudPayment $cardCloudPayment = null):Subscription
    {
        $subscription = Subscription::find()->where(['user_id' => $this->user_id])
            ->andWhere('deleted_at IS NULL')
            ->one();
        /** @var Subscription $subscription */

        if (!$subscription) {
            $subscription = new Subscription([
                'user_id' => $this->user_id,
            ]);

            if ($cardCloudPayment) {
                $subscription->card_id = $cardCloudPayment->id;
            }
            if (!$subscription->saveStrict()) {
                throw new \Exception('Не удалось создать подписку');
            }
        }
        return $subscription;
    }

    /**
     * @brief Получить описание платежа для сбера
     * @return string
     */
    public function getDescriptionSber()
    {
        $description = "Покупка";

        $course = $this->course;
        if (!$this->invoice_id && $course->type == Course::TYPE_MASTER_GROUP) {
            $description .= ' ' . RussianFormatter::month(date('n', $course->date_start));
        }

        if ($this->invoice_id) {
            $courses = $this->invoice->getCourseInvoice();

            if (count($courses) == 1) {
                $course = current($courses);
                $description .= $course->type == Course::TYPE_MASTER_GROUP ? ' ' . RussianFormatter::month(date('n', $course->date_start)) : '';
            } else {
                $arrDate = [];
                foreach ($courses as $course) {
                    if ($course->type == Course::TYPE_MASTER_GROUP && !in_array($course->date_start, $arrDate)) {
                        $arrDate[] = $course->date_start;
                    }
                }
                asort($arrDate);

                if ($arrDate) {
                    if (count($arrDate) == 1) {
                        $description .= ' ' . RussianFormatter::month(date('n', current($arrDate)));
                    } else {
                        $description .= ' ' . RussianFormatter::month(date('n', current($arrDate))) . ' - ' . RussianFormatter::month(date('n', end($arrDate)));
                    }
                }
            }
        }

        return $description;
    }

    /**
     * @brief Установить платеж
     * @param OrderYandex|OrderStatus $response
     * @return bool
     */
    public function setPayment(OrderYandex|OrderStatus $response):bool
    {
        if ($this->accepted_at) return true;
        try {

            $description = $this->description();

            if ($response instanceof OrderStatus) {
                $amount = $response->amount;
                $this->email = $response->email;
            } else {
                $amount = $response->orderAmount;
            }

            if ($this->amount < $amount / 100) {
                $this->amount = $amount / 100;
            }

            if (!$this->save()) {
                throw new Exception('Не удалось сохранить счет ' . $this->id);
            }
            $payment = Payment::findOne(['order_id' => $this->id]);
            if (!$payment) {
                $payment = new Payment(['order_id' => $this->id, 'description' => $description, 'amount' => $this->amount]);
                if (!$payment->saveStrict()) {
                    ErrorLog::createLog(new \Exception('Не удалось создать задачу по привязки карты id ' . $payment->id));
                }
                $advCake = new AdvCakeComponent();
                $invoice = $this->invoice;
                if ($invoice) {
                    $utm = $this->user->getUtmLastCpa();
                    if ($utm) {
                        if ($utm->utm_source == 'advcake' ||
                            $utm->utm_source == 'edpartners' ||
                            $utm->utm_source == 'salid' ||
                            $utm->utm_source == 'saleads' ||
                            $utm->utm_source == 'cityads' ||
                            $utm->utm_source == 'advertise' ||
                            $utm->utm_source == 'admitad') {
                            if ($utm->utm_medium == 'cpa' && $utm->utm_term && $utm->utm_term !== 'null') {
                                $advCake->advcake_track_url = $_COOKIE['advcake_track_url'] ?? null;
                                $advCake->advcake_track_id = $_COOKIE['advcake_track_id'] ?? null;
                                try {
                                    $advCake->send($this->invoice, $utm->utm_term, $this->amount, $utm);
                                } catch (\Exception $e) {
                                    \common\models\ErrorLog::createLog($e);
                                }
                            }
                        }
                    }
                }

            }

            return true;
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            return false;
        }
    }

    /**
     * @brief Создание процесс для воркера на оплату
     * @param float $amount Сумма с платежной суммой в формате 0.00
     * @param bool $sendPostback Нужно ли отправлять постбеки
     * @param int|null $transactionId
     * @return bool
     * @throws \Throwable
     */
    public function createPayment(float $amount, bool $sendPostback = false, int $transactionId = null):bool
    {
        try {
            $user = $this->user;
            $description = $this->description();

            if ($this->amount < $amount) {
                $this->amount = $amount;
            }

            if ($this->card_confirmation) {
                $cloudPaymentComponent = new CloudPaymentComponent();
                $cloudPaymentComponent->cancelTransaction($transactionId, $amount);
                $this->accepted_at = time();
                $this->setSubscription();
            }

            $invoice = $this->invoice;
            if ($invoice && $invoice->cart) {
                $cart = $invoice->cart;
                $cart->status = Cart::STATUS_PAYMENT;
                $cart->saveStrict();
            }
            if (!$this->save()) {
                throw new Exception('Не удалось сохранить счет ' . $this->id);
            }
            $payment = Payment::findOne(['order_id' => $this->id]);
            if (!$payment) {
                $payment = new Payment(['order_id' => $this->id, 'description' => $description, 'amount' => $this->amount]);
                if (!$payment->saveStrict()) {
                    ErrorLog::createLog(new \Exception('Не удалось создать задачу по привязки карты id ' . $this->id));
                }

                $data['status'] = 'paid';

                if ($centrifugoToken = $user->centrifugoToken()) {
                    $centrifugoToken->connectToChannel(CentrifugoChannel::CHANNEL_PAYMENT_NOTIFY . '-' . $user->id)->uuid;
                    $centrifugoToken->sendPublish(CentrifugoChannel::CHANNEL_PAYMENT_NOTIFY . '-' . $user->id, [
                        'type' => CentrifugoChannel::TYPE_PAYMENT_NOTIFY,
                        'data' => $data,
                    ]);
                }
                if ($sendPostback) {
                    $advCake = new AdvCakeComponent();
                    $invoice = $this->invoice;
                    if ($invoice) {
                        $utm = $user->getUtmLastCpa();
                        if ($utm) {
                            if ($utm->utm_source == 'advcake' ||
                                $utm->utm_source == 'edpartners' ||
                                $utm->utm_source == 'salid' ||
                                $utm->utm_source == 'saleads' ||
                                $utm->utm_source == 'cityads' ||
                                $utm->utm_source == 'advertise' ||
                                $utm->utm_source == 'admitad') {
                                if ($utm->utm_medium == 'cpa' && $utm->utm_term && $utm->utm_term !== 'null') {
                                    $advCake->advcake_track_url = $_COOKIE['advcake_track_url'] ?? null;
                                    $advCake->advcake_track_id = $_COOKIE['advcake_track_id'] ?? null;
                                    try {
                                        $advCake->send($this->invoice, $utm->utm_term, $this->amount, $utm);
                                    } catch (\Exception $e) {
                                        ErrorLog::createLog($e);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            return true;
        } catch (\Exception $e) {
            ErrorLog::createLog($e);
            return false;
        }
    }

    /**
     * @brief Описание в фин операциях, что пользотель купил
     * @return string
     * @throws \Exception
     */
    private function description():string
    {
        $description = '';
        if (!$this->card_confirmation) {
            if ($this->course_id) {
                $course = $this->course;
                $description = 'Покупка "' . $course->fullName(true) . '"';

                if ($course->type == Course::TYPE_SPEEDRUN) {
                    $description .= $this->payment_type == Course::PAYMENT_TYPE_FULL ? ' (Полный)' : ' (Половина)';
                }
            } elseif ($this->invoice_id) {
                $invoice = $this->invoice;
                $courses = $invoice->getCourseInvoice(false, true);
                $string = '';

                $countArr = count($courses);
                $arrSpeedruns = [];
                foreach ($courses as $key => $course) {
                    if ($course->type == Course::TYPE_MASTER_SPEEDRUN) {
                        $arrSpeedruns[] = $course;
                        continue;
                    }
                    $invoiceCourse = InvoiceCourse::findOne(['course_id' => $course->id, 'invoice_id' => $invoice->id]);

                    $string .= $course->fullName(true);
                    if ($course->type == Course::TYPE_SPEEDRUN) {
                        $string .= $invoiceCourse?->payment_type == Course::PAYMENT_TYPE_FULL ? ' (Полный)' : ' (Половина)';
                    }

                    if ($course->type == Course::TYPE_MASTER_GROUP) {
                        $userCourse = $this->user->getUserCourse($course->id);
                        if ($userCourse) {
                            $string .= ' - (Повышение тарифа ' . (UserCourse::$rateList[$userCourse->rate] ?? '')
                                . ' => ' . (UserCourse::$rateList[$invoiceCourse->rate] ?? '') . ') ';
                        } else {
                            $string .= ' - ' . (UserCourse::$rateList[$invoiceCourse->rate] ?? '');
                        }
                    }

                    if (($key + 1) != $countArr && !count($arrSpeedruns)) {
                        $string .= ', ';
                    }
                }

                $arrParts = [];
                $countArrSpeedrun = count($arrSpeedruns);
                foreach ($arrSpeedruns as $key => $speedrun) {
                    if (in_array($speedrun->id, $arrParts)) continue;
                    if (($key + 1) != $countArrSpeedrun && $key) {
                        $string .= ', ';
                    }
                    $program = $speedrun->parent;
                    $string .= $program->fullName(true, false) . " Части: " . $speedrun->part;
                    $arrParts[] = $speedrun->id;
                    foreach ($arrSpeedruns as $speedrunPart) {
                        if (in_array($speedrunPart->id, $arrParts)) continue;
                        if ($speedrunPart->parent_id != $program->id) continue;
                        $string .= ', ' . $speedrunPart->part;
                        $arrParts[] = $speedrunPart->id;
                    }
                }
                $description = 'Покупка курсов "' . $string . '"';
            }
        }
        return $description;
    }
}
