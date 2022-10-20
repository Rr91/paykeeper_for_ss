<?php
/*
Plugins "PayKeeper" for Shop-Script

 * @author PayKeeper
 * @name PayKeeper
 * @description Плагин оплаты через PayKepeer.
 *
 * Поля, доступные в виде параметров настроек плагина, указаны в файле lib/config/settings.php.
 * @property-read string $pk_url_link
 * @property-read string $pk_secret_key
*/
class paykeeperPayment extends waPayment implements waIPayment 
{

    private $fiscal_cart = array(); //fz54 cart
    private $order_total = 0; //order total sum
    private $shipping_price = 0; //shipping price
    private $use_taxes = false;
    private $use_delivery = false;
    private $order_params = NULL;
    private $discounts = array();
    private $submit_button_class = "";

    //currency (only RUB available)
    public function allowedCurrency()
    {
        return $currency = 'RUB';
    }


    //redirect to payment form page
    public function payment($payment_form_data, $order_data, $form_html = true)
    {
	        //required
        if (empty($order_data['description'])) {
            $order_data['description'] = 'Заказ '.$order_data['order_id'];
        }

        //initialize order object
        $order = waOrder::factory($order_data);

        $customer = new waContact($order_data["contact_id"]);

        //set order parameters
        $orderid = $order_data["id_str"] . "|" .
                   $order_data["order_id"] . "|" .
                   $this->app_id . "|" .
                   $this->merchant_id;

        $email_data = $customer->get("email");
        $email = $email_data[0]["value"];
        $phone_data = $customer->get("phone");
        $phone = $phone_data[0]["value"];

        $this->setOrderParams($order->total,                        //sum
                              $customer->get("name"),               //clientid
                              $orderid,                             //orderid
                              $email,                               //client_email
                              $phone,                               //client_phone
                              "",                                   //service_name
                              $this->pk_url_link,                   //payment form url
                              $this->pk_secret_key                  //secret key
        );

        //GENERATE FZ54 CART
        $product_cart_sum = 0;

		$cart_data = $order_data["items"];

        foreach ($cart_data as $cart_item) {
            $name = $cart_item["name"];
            $qty = $cart_item["quantity"];
            $price = $cart_item["price"];
            $sum = number_format($price*$qty, 2, ".", "");
            $product_cart_sum += $sum;

            $tax_rate = $cart_item["tax_rate"];
            $taxes = $this->setTaxes($sum, $tax_rate);
            $this->updateFiscalCart($this->getPaymentFormType(), 
                                    $name, $price, $qty, $sum, $taxes["tax"], $taxes["tax_sum"]);
        }

        //add shipping parameters to cart
        $shipping_tax_rate = 0;
        $shipping_taxes = array("tax" => "none", "tax_sum" => 0);

        $this->setShippingPrice($order["shipping"]);
        $shipping_tax_rate = $order["shipping_tax_rate"];
        $shipping_taxes = $this->setTaxes($this->getShippingPrice(), $shipping_tax_rate);
        $shipping_name = $order["shipping_name"];
        if ($this->getShippingPrice() > 0) {
            $this->setUseDelivery(); //for precision correct check
            $this->updateFiscalCart($this->getPaymentFormType(),
                                    $shipping_name,
                                    $this->getShippingPrice(), 1, 
                                    $this->getShippingPrice(), 
                                    $shipping_taxes["tax"],
                                    $shipping_taxes["tax_sum"]);
        }

        //set discounts
        $this->setDiscounts($product_cart_sum, (float) $order["discount"]);

        //handle possible precision problem
        $this->correctPrecision($product_cart_sum);

        $fiscal_cart_encoded = json_encode($this->getFiscalCart());

        //generate payment form
        $form = "";

        if ($this->getPaymentFormType() == "create") { //create form
            $to_hash = $this->getOrderTotal(True)                .
                       $this->getOrderParams("clientid")     .
                       $this->getOrderParams("orderid")      .
                       $this->getOrderParams("service_name") .
                       $this->getOrderParams("client_email") .
                       $this->getOrderParams("client_phone") .
                       $this->getOrderParams("secret_key");
            $sign = hash ('sha256' , $to_hash);
            $this->setSubmitButtonCSSClass("large bold");
            $form = '
                <h3>Сейчас Вы будете перенаправлены на страницу банка.</h3> 
                <form name="payment" id="pay_form" action="'.$this->getOrderParams("form_url").'" accept-charset="utf-8" method="post">
                <input type="hidden" name="sum" value = "'.$this->getOrderTotal(True).'"/>
                <input type="hidden" name="orderid" value = "'.$this->getOrderParams("orderid").'"/>
                <input type="hidden" name="clientid" value = "'.$this->getOrderParams("clientid").'"/>
                <input type="hidden" name="client_email" value = "'.$this->getOrderParams("client_email").'"/>
                <input type="hidden" name="client_phone" value = "'.$this->getOrderParams("client_phone").'"/>
                <input type="hidden" name="service_name" value = "'.$this->getOrderParams("service_name").'"/>
                <input type="hidden" name="cart" value = \''.$fiscal_cart_encoded.'\' />
                <input type="hidden" name="sign" value = "'.$sign.'"/>
                <input type="submit" class="'.$this->getSubmitButtonCSSClass().'" value="Оплатить"/>
                </form>
                <script type="text/javascript">
                window.onload=function(){
                    setTimeout(fSubmit, 2000);
                }
                function fSubmit() {
                    document.forms["pay_form"].submit();
                }
                </script>';
        }
        else { //order form
            $payment_parameters = array(
                "clientid"=>$this->getOrderParams("clientid"), 
                "orderid"=>$this->getOrderParams('orderid'), 
                "sum"=>$this->getOrderTotal(), 
                "phone"=>$this->getOrderParams("phone"), 
                "client_email"=>$this->getOrderParams("client_email"), 
                "cart"=>$fiscal_cart_encoded);
            $query = http_build_query($payment_parameters);
            $err_num = $err_text = NULL;
            if( function_exists( "curl_init" )) { //using curl
                $CR = curl_init();
                curl_setopt($CR, CURLOPT_URL, $this->getOrderParams("form_url"));
                curl_setopt($CR, CURLOPT_POST, 1);
                curl_setopt($CR, CURLOPT_FAILONERROR, true); 
                curl_setopt($CR, CURLOPT_POSTFIELDS, $query);
                curl_setopt($CR, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($CR, CURLOPT_SSL_VERIFYPEER, 0);
                $result = curl_exec( $CR );
                $error = curl_error( $CR );
                if( !empty( $error )) {
                    $form = "<br/><span class=message>"."INTERNAL ERROR:".$error."</span>";
                    return false;
                }
                else {
                    $form = $result;
                }
                curl_close($CR);
            }
            else { //using file_get_contents
                if (!ini_get('allow_url_fopen')) {
                    $form_html = "<br/><span class=message>"."INTERNAL ERROR: Option allow_url_fopen is not set in php.ini"."</span>";
                }
                else {
                    $form = file_get_contents($server, false, $context);
                }
            }
        }
        if ($form  == "") {
            $form = '<h3>Произошла ошибка при инциализации платежа</h3><p>$err_num: '.htmlspecialchars($err_text).'</p>';
        }

		//render form
        $view = wa()->getView();
		$view->assign('form_html', $form);
		return $view->fetch($this->path.'/templates/payment.html');
	}
	//
	//Инициируем callBack Функцию и подготавливаем переменные
	//
    protected function callbackInit($request)
    {
        if (!empty($request['id']) && !empty($request['sum']) && !empty($request['clientid']) && !empty($request['orderid']) && !empty($request['key'])) {

			//parse orderid
            $orderid_data = $this->parseOrderId($request["orderid"]);
		 	$this->order_id = $orderid_data[1]; 
    		$this->app_id = $orderid_data[2]; 
    		$this->merchant_id = $orderid_data[3];
		 } else {
            self::log($this->id, array('error' => 'empty required field(s)'));
            throw new waPaymentException('Empty required field(s)');
        }
      return parent::callbackInit($request);  
    }

 	//
	//Выполняем проверку и выводим сообщения
	//Так же пишем в лог, или обновляем статус заказа в случае успеха
	//
 protected function callbackHandler($request)
    {
        // приводим данные о транзакции к универсальному виду
        $transaction_data = $this->formalizeData($request);

		
		//ВЫПОЛНЯЕМ ПРОВЕРКИ
		$pk_create  = md5($request['id'].number_format($request['sum'], 2, '.', '').$request['clientid'].$request['orderid'].$this->pk_secret_key);
		
        $orderid_data = $this->parseOrderId($request["orderid"]);
        $request["orderid"] = $orderid_data[1];
		
		if(strcmp($pk_create, $request['key']) == 0) {
		#
		#
		#
		//Если все хорошо выполняем и выодим ответ для ПС
		$transaction_data['type'] = self::OPERATION_AUTH_CAPTURE;
		$transaction_data['state'] = self::STATE_CAPTURED;
		$callback_method = self::CALLBACK_PAYMENT;	
		$transaction_data = $this->saveTransaction($transaction_data, $request);
		$result = $this->execAppCallback($callback_method,$transaction_data);

        if (!empty($result['result'])) {
            self::log($this->id, array('result' => 'success'));
            echo $message = 'OK '.md5($request['id'].$this->pk_secret_key);
			exit();
        } else {
            $message = !empty($result['error']) ? $result['error'] : 'wa transaction error';
            self::log($this->id, array('error' => $message));
            //header("HTTP/1.0 403 Forbidden");
			echo $message;
			exit();
        }
		#
		#
		#
		} else {}
		//
			$message = 'Message digest incorrect';
            self::log($this->id, array('error' => $message));
            //header("HTTP/1.0 403 Forbidden");
			echo $message;
			exit();
    }

	#
	//
	//Подготавливаем массив для вставки в БД и определяем тип
	//
	#
    protected function formalizeData($request) {
	// формируем полный список полей, относящихся к транзакциям, которые обрабатываются платежной системой payKeeper
	$fields = array(
            'id',
            'sum',
            'orderid',
            'clientid',
            'key',

        ); 
        foreach ($fields as $f) {
            if (!isset($request[$f])) {
                $request[$f] = null;
            }
        }
	
	// выполняем базовую обработку данных
	$transaction_data = parent::formalizeData($request);
	//Тип транзацкии
	$transaction_data['type'] = self::OPERATION_AUTH_CAPTURE;
	// идентификатор транзакции, присвоенный платежной системой
	$transaction_data['native_id'] = $request['id'];
	// номер заказа
	//$pk_new_param = explode(':::',$request['orderid']);
    $orderid_data = $this->parseOrderId($request["orderid"]);
	$transaction_data['order_id'] = $orderid_data[1];
	// сумма заказа
	$transaction_data['amount'] = number_format($request['sum'], 2, '.', '');
	// идентификатор валюты заказа
	$transaction_data['currency_id'] = 'RUB';
	$transaction_data['result'] = 1;
	$transaction_data['view_data'] = 'Оплачено через PayKeeper - статус ответа: success';
	$transaction_data['state'] = self::STATE_CAPTURED;
	
	return $transaction_data;
	}
	
	
    protected function setOrderParams($order_total = 0, $clientid="", $orderid="", $client_email="",
                                    $client_phone="", $service_name="", $form_url="", $secret_key="")
    {
       $this->setOrderTotal($order_total);
       $this->order_params = array(
           "sum" => $order_total,
           "clientid" => $clientid,
           "orderid" => $orderid,
           "client_email" => $client_email,
           "client_phone" => $client_phone,
           "phone" => $client_phone,
           "service_name" => $service_name,
           "form_url" => $form_url,
           "secret_key" => $secret_key,
       );
    }

    protected function getOrderParams($value)
    {
        return array_key_exists($value, $this->order_params) ? $this->order_params["$value"] : False;
    }

    protected function updateFiscalCart($ftype, $name="", $price=0, $quantity=0, $sum=0, $tax="none", $tax_sum=0)
    {
        //update fz54 cart
        if ($ftype === "create") {
            $name = str_replace("\n ", "", $name);
            $name = str_replace("\r ", "", $name);
            $name = preg_replace('/"{1,}/','\'',$name);
            $name = htmlspecialchars($name);
        }
        $this->fiscal_cart[] = array(
            "name" => $name,
            "price" => $price,
            "quantity" => $quantity,
            "sum" => $sum,
            "tax" => $tax,
            "tax_sum" => number_format($tax_sum, 2, ".", "")
        );
    }

    protected function getFiscalCart()
    {
        return $this->fiscal_cart;
    }

    protected function setDiscounts($cart_sum, $discount_value)
    {
        //set discounts
        if ($discount_value > 0) {

            $fiscal_cart_count = count($this->getFiscalCart())-1;
            $discount_modifier_value = ($this->getOrderTotal() - $this->getShippingPrice())/$cart_sum;
            //iterate fiscal cart without shipping
            for ($pos=0; $pos<$fiscal_cart_count; $pos++) {
                $this->fiscal_cart[$pos]["sum"] *= $discount_modifier_value;
                $this->fiscal_cart[$pos]["price"] = $this->fiscal_cart[$pos]["sum"]/$this->fiscal_cart[$pos]["quantity"];
                //formatting
                $this->fiscal_cart[$pos]["price"] = number_format($this->fiscal_cart[$pos]["price"], 3, ".", "");
                $this->fiscal_cart[$pos]["sum"] = number_format($this->fiscal_cart[$pos]["sum"], 2, ".", "");
                //recalculate taxes
                $this->recalculateTaxes($pos);
            }
        }
    }

    protected function correctPrecision($cart_sum)
    {
        //handle possible precision problem
        $fiscal_cart_sum = $cart_sum;
        $total_sum = $this->getOrderTotal(True);
        //add shipping sum to cart sum
        if ($this->getShippingPrice() > 0)
            $fiscal_cart_sum += $this->fiscal_cart[count($this->fiscal_cart)-1]['sum'];
        //debug_info
        //echo "total: " . $total_sum . " - cart: " . $cart_sum;
        $diff_sum = $fiscal_cart_sum - $total_sum;
        if (abs($diff_sum) <= 0.01) {
            $this->setOrderTotal(number_format($total_sum+$diff_sum, 2, ".", ""));
        }
        else {
            if ($this->getUseDelivery() && ($fiscal_cart_sum < $total_sum)) {
                $this->setOrderTotal(number_format($total_sum+$diff_sum, 2, ".", ""));
                $delivery_pos = count($this->getFiscalCart())-1;
                $this->fiscal_cart[$delivery_pos]["price"] = number_format(
                                   $this->fiscal_cart[$delivery_pos]["price"]+$diff_sum, 2, ".", "");
                $this->fiscal_cart[$delivery_pos]["sum"] = number_format(
                                   $this->fiscal_cart[$delivery_pos]["sum"]+$diff_sum, 2, ".", "");
                $this->recalculateTaxes($delivery_pos);
            }
        }
    }

    protected function setOrderTotal($value)
    {
        $this->order_total = $value;
    }

    protected function getOrderTotal($format=False)
    {
        return ($format) ? number_format($this->order_total, 2, ".", "") : 
                                         $this->order_total;
    }

    protected function setShippingPrice($value)
    {
        $this->shipping_price = $value;
    }

    protected function getShippingPrice()
    {
        return $this->shipping_price;
    }

    protected function getPaymentFormType()
    {
        if (strpos($this->order_params["form_url"], "/order/inline") == True)
            return "order";
        else
            return "create";
    }

    protected function setUseTaxes()
    {
        $this->use_taxes = True;
    }

    protected function getUseTaxes()
    {
        return $this->use_taxes;
    }

    protected function setUseDelivery()
    {
        $this->use_delivery = True;
    }

    protected function getUseDelivery()
    {
        return $this->use_delivery;
    }

    protected function recalculateTaxes($item_pos)
    {
        //recalculate taxes
        switch($this->fiscal_cart[$item_pos]['tax']) {
            case "vat10":
                $this->fiscal_cart[$item_pos]['tax_sum'] = round((float)
                    (($this->fiscal_cart[$item_pos]['sum']/110)*10), 2);
                break;
            case "vat18":
                $this->fiscal_cart[$item_pos]['tax_sum'] = round((float)
                    (($this->fiscal_cart[$item_pos]['sum']/118)*18), 2);
                break;
        }
    }

    protected function setTaxes($sum, $tax_rate)
    {
        $taxes = array("tax" => "none", "tax_sum" => 0);
        if ($tax_rate != Null) {
            switch(number_format($tax_rate, 0, ".", "")) {
                case 0:
                    $taxes["tax"] = "vat0";
                    $taxes["tax_sum"] = number_format(0, 2, ".", "");
                    break;
                case 10:
                    $taxes["tax"] = "vat10";
                    $taxes["tax_sum"] = round((float)(($sum/110)*10), 2);
                    break;
                case 18:
                    $taxes["tax"] = "vat18";
                    $taxes["tax_sum"] = round((float)(($sum/118)*18), 2);
                    break;
            }
        }
        return $taxes;
    }

    protected function showDebugInfo($obj_to_debug)
    {
        echo "<pre>";
        var_dump($obj_to_debug);
        echo "</pre>";
    }

    protected function setSubmitButtonCSSClass($class_string)
    {
        $this->submit_button_class = $class_string;
    }

    protected function getSubmitButtonCSSClass()
    {
        return $this->submit_button_class;
    }

    protected function parseOrderId($orderid)
    {
        return explode("|", $orderid);
    }
}
