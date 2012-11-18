<?php
defined ('_JEXEC') or die('Restricted access');
/**
 * a special type of 'swedbank pangalink':
 */
if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentSwedbank extends vmPSPlugin {
	// instance of class
	public static $_this = FALSE;
	function __construct (& $subject, $config) {		parent::__construct ($subject, $config);		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array('my_private_key'  => array('', 'char'),							'my_private_key_password' => array('', 'char'),							'bank_certificate' 		 => array('', 'char'),							'my_id' 				 => array('', 'char'),							'account_number'  		 => array('', 'char'),							'account_owner'  		 => array('', 'char'),							'testing' 				 => array(0, 'int'),
		                    'payment_currency'       => array('', 'int'),		                    'email_currency'         => array('', 'int'),		                    'payment_logos'          => array('', 'char'),		                    'status_pending'         => array('', 'char'),		                    'status_success'         => array('', 'char'),		                    'status_canceled'        => array('', 'char'),		                    'countries'              => array('', 'char'),		                    'min_amount'             => array('', 'int'),		                    'max_amount'             => array('', 'int'),		                    'cost_per_transaction'   => array('', 'int'),		                    'cost_percent_total'     => array('', 'int'),		                    'tax_id'                 => array(0, 'int')		);

		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);	}

	/**
	 * @return string
	 */
	public function getVmPluginCreateTableSQL () {
		return $this->createTableSQL ('Payment Swedbank Table');
	}

	/**
	 * @return array
	 */
	function getTableSQLFields () {

		$SQLfields = array(
			'id'                                   => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'                  => 'int(1) UNSIGNED',
			'order_number'                         => 'char(64)',
			'virtuemart_paymentmethod_id'          => 'mediumint(1) UNSIGNED',
			'payment_name'                         => 'varchar(5000)',
			'payment_order_total'                  => 'decimal(15,5) NOT NULL',
			'payment_currency'                     => 'smallint(1)',
			'email_currency'                       => 'smallint(1)',
			'cost_per_transaction'                 => 'decimal(10,2)',
			'cost_percent_total'                   => 'decimal(10,2)',
			'tax_id'                               => 'smallint(1)',
			'swed_custom'                          => 'varchar(255)',
			'swed_response_mc_gross'               => 'decimal(10,2)',
			'swed_response_mc_currency'            => 'char(10)',
			'swed_response_invoice'                => 'char(32)',
			'swed_response_protection_eligibility' => 'char(128)',
			'swed_response_payer_id'               => 'char(13)',
			'swed_response_tax'                    => 'decimal(10,2)',
			'swed_response_payment_date'           => 'char(28)',
			'swed_response_payment_status'         => 'char(50)',
			'swed_response_pending_reason'         => 'char(50)',
			'swed_response_mc_fee'                 => 'decimal(10,2)',
			'swed_response_payer_email'            => 'char(128)',
			'swed_response_last_name'              => 'char(64)',
			'swed_response_first_name'             => 'char(64)',
			'swed_response_business'               => 'char(128)',
			'swed_response_receiver_email'         => 'char(128)',
			'swed_response_transaction_subject'    => 'char(128)',
			'swed_response_residence_country'      => 'char(2)',
			'swed_response_case_creation_date'     => 'char(32)',
			'swed_response_case_id'                => 'char(32)',
			'swed_response_case_type'              => 'char(32)',
			'swed_response_reason_code'            => 'char(32)',
			'swedbankresponse_raw'                 => 'varchar(512)',
		);
		return $SQLfields;
	}

	/**
	 * @param $cart
	 * @param $order
	 * @return bool|null
	 */
	function plgVmConfirmedOrder ($cart, $order) {

		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		$session = JFactory::getSession ();
		$return_context = $session->getId ();
		$this->_debug = $method->debug;
		$this->logInfo ('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists ('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}

		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists ('TableVendors')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		}
		$vendorModel = VmModel::getModel ('Vendor');
		$vendorModel->setId (1);
		$vendor = $vendorModel->getVendor ();
		$vendorModel->addImages ($vendor, 1);
		$this->getPaymentCurrency ($method);
		$email_currency = $this->getEmailCurrency ($method);
		$currency_code_3 = shopFunctions::getCurrencyByID ($method->payment_currency, 'currency_code_3');

		$paymentCurrency = CurrencyDisplay::getInstance ($method->payment_currency);
		$totalInPaymentCurrency = round ($paymentCurrency->convertCurrencyTo ($method->payment_currency, $order['details']['BT']->order_total, FALSE), 2);
		$cd = CurrencyDisplay::getInstance ($cart->pricesCurrency);
		if ($totalInPaymentCurrency <= 0) {
			vmInfo (JText::_ ('VMPAYMENT_SWED_PAYMENT_AMOUNT_INCORRECT'));
			return FALSE;
		}
		
		$quantity = 0;
		foreach ($cart->products as $key => $product) {
			$quantity = $quantity + $product->quantity;
		}
		$post_variables = Array(
			'cmd'               => '_ext-enter',
			'redirect_cmd'      => '_xclick',
			'upload'            => '1', 
			'business'          => $merchant_email,
			'receiver_email'    => $merchant_email,
			'order_number'      => $order['details']['BT']->order_number,
			"invoice"           => $order['details']['BT']->order_number,
			'custom'            => $return_context,
			'item_name'         => JText::_ ('VMPAYMENT_SWED_ORDER_NUMBER') . ': ' . $order['details']['BT']->order_number,
			"amount"            => $totalInPaymentCurrency,
			"currency_code"     => $currency_code_3,
			"address_override"  => isset($method->address_override) ? $method->address_override : 0, // 0 ??   Paypal does not allow your country of residence to ship to the country you wish to
			"first_name"        => $address->first_name,
			"last_name"         => $address->last_name,
			"address1"          => $address->address_1,
			"address2"          => isset($address->address_2) ? $address->address_2 : '',
			"zip"               => $address->zip,
			"city"              => $address->city,
			"state"             => isset($address->virtuemart_state_id) ? ShopFunctions::getStateByID ($address->virtuemart_state_id) : '',
			"country"           => ShopFunctions::getCountryByID ($address->virtuemart_country_id, 'country_2_code'),
			"email"             => $order['details']['BT']->email,
			"night_phone_b"     => $address->phone_1,
			"return"            => JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=swedresponse&task=swedresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . JRequest::getInt ('Itemid')),
			// Keep this line, needed when testing
			//"return" => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component'),
			"notify_url"        => JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component'),
			"cancel_return"     => JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . JRequest::getInt ('Itemid')),
			"ipn_test"          => $method->debug,
			"rm"                => '2', 
			"image_url"         => JURI::root () . $vendor->images[0]->file_url,
			"no_shipping"       => isset($method->no_shipping) ? $method->no_shipping : 0,
			"no_note"           => "1");

		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $order['details']['BT']->order_number;		$dbValues['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
		$dbValues['payment_name'] = $this->renderPluginName ($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['swed_custom'] = $return_context;		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;		$dbValues['cost_percent_total'] = $method->cost_percent_total;		$dbValues['payment_currency'] = $method->payment_currency;		$dbValues['email_currency'] = $email_currency;		$dbValues['payment_order_total'] = $totalInPaymentCurrency;		$dbValues['tax_id'] = $method->tax_id;		$this->storePSPluginInternalData ($dbValues);
		$url = $this->_getPaypalUrlHttps ($method);
		$currencysymbol = "";
		if ($method->payment_currency) {
		   $currencysymbol = $this->getCurrSymbol($method->payment_currency);
		}else {
		   $currencysymbol = "EUR";
		}
		$amount = $dbValues['payment_order_total'];
		$order_id = $dbValues['order_number'];		$order_id = $this->getVMOrderID($order_id);

		$macFields = array(
			'VK_SERVICE'    => '1001',
			'VK_VERSION'    => '008',
			'VK_SND_ID'     => $this->to_banklink_ch ($method->my_id),
			'VK_STAMP'      => $this->to_banklink_ch ($order_id),
			'VK_AMOUNT'     => $this->to_banklink_ch ($amount),
			'VK_CURR'       => $this->to_banklink_ch ($currencysymbol),
			'VK_ACC'        => $this->to_banklink_ch ($method->account_number),
			'VK_NAME'       => $this->to_banklink_ch ($method->account_owner),
			'VK_REF'        => '',
			'VK_MSG'        => 'ost',
			'VK_ENCODING'	=> 'UTF-8',			'VK_RETURN' => JROUTE::_ (JURI::root () . 'index.php?option=com_virtuemart&view=swedresponse&task=swedresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . JRequest::getInt ('Itemid'))
		);
		
		/**
		* Genereerime tehingu vaartustest signatuuri
		*/
		$key = openssl_pkey_get_private (
			file_get_contents ($method->my_private_key),
			$method->my_private_key_password
		);

		if (!openssl_sign($this->generateMACString($macFields), $signature, $key)) {
			trigger_error("Unable to generate signature", E_USER_ERROR);
		}		
		$macFields['VK_MAC'] = base64_encode ($signature);
		$html  = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$html .= '<form action="' . "https://" . $url . '" method="post" target="_self" name="swform">';
		foreach ($macFields as $f => $v) {			$html .= '<input type="hidden" name="' . $f . '" value="' . htmlspecialchars ($v) . '" />';		}
		$html .= '<script language="javascript" type="text/javascript">';
		$html .= 'document.swform.submit();';
		$html .= '</script>';
		$html .= '<noscript><input type="submit" value="submit"></noscript>';
		$html .= '</form>';
		$html .= '</div></body></html>';
		$cart->_confirmDone = FALSE;
		$cart->_dataValidated = FALSE;
		$cart->setCartIntoSession ();
		JRequest::setVar ('html', $html);
	}		function getVMOrderID($order_number){		$db = JFactory::getDBO ();		$q = "SELECT `virtuemart_order_id` FROM `#__virtuemart_payment_plg_swedbank` WHERE `order_number` = '$order_number'";		$db->setQuery ($q);		$id = $db->loadResult();				return $id;	}		function getCurrSymbol($curr_id){		$db = JFactory::getDBO ();		$q = "SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id` = $curr_id";		$db->setQuery ($q);		$sym = $db->loadResult();				return $sym;	}
	
	/**
     * Genereerib sisseantud massiivi vaartustest jada.
     *
     * Jadasse lisatakse iga vaartuse pikkus (kolmekohalise arvuna)
     * ning selle jarel vaartus ise.
     */
    function generateMACString ($macFields) {
        $banklinkCharset = $macFields['VK_ENCODING'];
        $requestNum = $macFields['VK_SERVICE'];
		$variableOrder = $this->variableOrder();
		
        $data = '';
		       
        foreach ((array)$variableOrder[$requestNum] as $key) {
            $v = $macFields[$key];
            $l = mb_strlen ($v, $banklinkCharset);
            $data .= str_pad ($l, 3, '0', STR_PAD_LEFT) . $v;
        }

        if($data == '')
        	return 'null';
        else
        	return $data;
    }
    
    function variableOrder() {
	    $VK_variableOrder = array(
	        1001 => array(
	            'VK_SERVICE','VK_VERSION','VK_SND_ID',
	            'VK_STAMP','VK_AMOUNT','VK_CURR',
	            'VK_ACC','VK_NAME','VK_REF','VK_MSG'
	        ),
	
	        1101 => array(
	            'VK_SERVICE','VK_VERSION','VK_SND_ID',
	            'VK_REC_ID','VK_STAMP','VK_T_NO','VK_AMOUNT','VK_CURR',
	            'VK_REC_ACC','VK_REC_NAME','VK_SND_ACC','VK_SND_NAME',
	            'VK_REF','VK_MSG','VK_T_DATE'
	        ),
	
	        1901 => array(
	            'VK_SERVICE','VK_VERSION','VK_SND_ID',
	            'VK_REC_ID','VK_STAMP','VK_REF','VK_MSG'
	        ),
	    );
	    return $VK_variableOrder;
    }
    
	/**
     * Teisendab vaartuse UTF-8 kodeeringust pangalingi kodeeringusse.
     */
    function to_banklink_ch ($v) {
        return mb_convert_encoding ($v, 'utf-8', 'utf-8');
    }

    /**
     * Teisendab vaartuse pangalingi kodeeringust UTF-8sse
     */
    function from_banklink_ch ($v) {
        return mb_convert_encoding ($v, 'utf-8', 'utf-8');
    }

	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
	function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		$this->getPaymentCurrency ($method);
		$paymentCurrencyId = $method->payment_currency;
	}

	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
	function plgVmgetEmailCurrency ($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId) {

		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		if (!($payments = $this->_getPaypalInternalData ($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		if (empty($payments[0]->email_currency)) {
			$vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
			$db = JFactory::getDBO ();
			$q = 'SELECT   `vendor_currency` FROM `#__virtuemart_vendors` WHERE `virtuemart_vendor_id`=' . $vendorId;
			$db->setQuery ($q);
			$emailCurrencyId = $db->loadResult ();
		} else {
			$emailCurrencyId = $payments[0]->email_currency;
		}

	}

	/**
	 * @param $html
	 * @return bool|null|string
	 */
	function plgVmOnPaymentResponseReceived (&$html) {

		if (!class_exists ('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists ('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$virtuemart_paymentmethod_id = JRequest::getInt ('pm', 0);
		$order_number = JRequest::getString ('on', 0);
		$vendorId = 0;
		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return NULL;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		$payment_name = $this->renderPluginName ($method);
		$html = $this->_getPaymentResponseHtml ($paymentTable, $payment_name);

		//We delete the old stuff
		// get the correct cart / session
		$cart = VirtueMartCart::getCart ();
		$cart->emptyCart ();
		return TRUE;
	}

	/**
	 * @return bool|null
	 */
	function plgVmOnUserPaymentCancel () {

		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}

		$order_number = JRequest::getString ('on', '');
		$virtuemart_paymentmethod_id = JRequest::getInt ('pm', '');
		if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId ($virtuemart_paymentmethod_id)) {
			return NULL;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			return NULL;
		}

		VmInfo (Jtext::_ ('VMPAYMENT_SWED_PAYMENT_CANCELLED'));
		$session = JFactory::getSession ();
		$return_context = $session->getId ();
		if (strcmp ($paymentTable->swed_custom, $return_context) === 0) {
			$this->handlePaymentUserCancel ($virtuemart_order_id);
		}
		return TRUE;
	}
	/**
	 * @return bool|null
	 */
	function plgVmOnPaymentNotification () {		//$this->_debug = true;
		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		$swed_data = JRequest::get ('post');
		if (!isset($swed_data['invoice'])) {
			return NULL;
		}
		$order_number = $swed_data['invoice'];
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($swed_data['invoice']))) {
			return NULL;
		}

		$vendorId = 0;
		if (!($payments = $this->getDatasByOrderId ($virtuemart_order_id))) {
			return NULL;
		}

		$method = $this->getVmPluginMethod ($payments[0]->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		if (isset($method->log_ipn) and $method->log_ipn) {
			$this->logIpn ();
		}
		$this->_debug = $method->debug;

		$this->logInfo ('swed_data ' . implode ('   ', $swed_data), 'message');
		$modelOrder = VmModel::getModel ('orders');
		$order = array();
		$error_msg = $this->_processIPN ($swed_data, $method);

		$this->logInfo ('process IPN ' . $error_msg, 'message');

		if (!(empty($error_msg))) {
			$this->logInfo ('process IPN ' . $error_msg . ' ' . $order['order_status'], 'ERROR');
			return NULL;
		} else {
			$this->logInfo ('process IPN OK', 'message');
		}
		if (empty($swed_data['payment_status']) || ($swed_data['payment_status'] != 'Completed' && $swed_data['payment_status'] != 'Pending')) {
			//return false;
		}
		$lang = JFactory::getLanguage ();
		$order['customer_notified'] = 1;

		// 1. check the payment_status is Completed
		if (strcmp ($swed_data['payment_status'], 'Completed') == 0) {
			// 2. check that txn_id has not been previously processed
			if ($this->_check_txn_id_already_processed ($payments, $swed_data['txn_id'], $method)) {
				return;
			}
			// 3. check email and amount currency is correct
			if (!$this->_check_email_amount_currency ($payments, $this->_getMerchantEmail ($method), $swed_data)) {
				return;
			}
			// now we can process the payment
			$order['order_status'] = $method->status_success;
			$order['comments'] = JText::sprintf ('VMPAYMENT_swed_PAYMENT_STATUS_CONFIRMED', $order_number);
		} elseif (strcmp ($swed_data['payment_status'], 'Pending') == 0) {
			$key = 'VMPAYMENT_swed_PENDING_REASON_FE_' . strtoupper ($swed_data['pending_reason']);
			if (!$lang->hasKey ($key)) {
				$key = 'VMPAYMENT_swed_PENDING_REASON_FE_DEFAULT';
			}
			$order['comments'] = JText::sprintf ('VMPAYMENT_swed_PAYMENT_STATUS_PENDING', $order_number) . JText::_ ($key);
			$order['order_status'] = $method->status_pending;
		} elseif (isset ($swed_data['payment_status'])) {
			$order['order_status'] = $method->status_canceled;
		} else {
			/*
			* a notification was received that concerns one of the payment (since $swed_data['invoice'] is found in our table),
			* but the IPN notification has no $swed_data['payment_status']
			* We just log the info in the order, and do not change the status, do not notify the customer
			*/
			$order['comments'] = JText::_ ('VMPAYMENT_swed_IPN_NOTIFICATION_RECEIVED');
			$order['customer_notified'] = 0;
		}
		$this->_storePaypalInternalData ($method, $swed_data, $virtuemart_order_id, $payments[0]->virtuemart_paymentmethod_id);
		$this->logInfo ('plgVmOnPaymentNotification return new_status:' . $order['order_status'], 'message');

		$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
		//// remove vmcart
		if (isset($swed_data['custom'])) {
			$this->emptyCart ($swed_data['custom'], $order_number);
		}
		//die();
	}

	function logIpn () {

		$file = JPATH_ROOT . "/logs/swed-ipn.log";
		$date = JFactory::getDate ();

		$fp = fopen ($file, 'a');
		fwrite ($fp, "\n\n" . $date->toFormat ('%Y-%m-%d %H:%M:%S'));
		fwrite ($fp, "\n" . var_export ($_POST, TRUE));
		fclose ($fp);
	}

	/**
	 * @param $method
	 * @param $swed_data
	 * @param $virtuemart_order_id
	 */
	function _storeSwedbankInternalData ($method, $swed_data, $virtuemart_order_id, $virtuemart_paymentmethod_id) {

		// get all know columns of the table
		$db = JFactory::getDBO ();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery ($query);
		$columns = $db->loadResultArray (0);
		$post_msg = '';
		foreach ($swed_data as $key => $value) {
			$post_msg .= $key . "=" . $value . "<br />";
			$table_key = 'swed_response_' . $key;
			if (in_array ($table_key, $columns)) {
				$response_fields[$table_key] = $value;
			}
		}
		$response_fields['payment_name'] = $this->renderPluginName ($method);
		$response_fields['paypalresponse_raw'] = $post_msg;
		$response_fields['order_number'] = $swed_data['invoice'];
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;
		$response_fields['swed_custom'] = $swed_data['custom'];
		$this->storePSPluginInternalData ($response_fields);
	}

	/**
	 * Display stored payment data for an order
	 *
	 * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
	 */
	function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $payment_method_id) {

		if (!$this->selectedThisByMethodId ($payment_method_id)) {
			return NULL; // Another method was selected, do nothing
		}

		if (!($payments = $this->_getPaypalInternalData ($virtuemart_order_id))) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}

		$html = '<table class="adminlist" width="50%">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$code = "swed_response_";
		$first = TRUE;
		foreach ($payments as $payment) {
			$html .= '<tr class="row1"><td>' . JText::_ ('VMPAYMENT_swed_DATE') . '</td><td align="left">' . $payment->created_on . '</td></tr>';
			// Now only the first entry has this data when creating the order
			if ($first) {
				$html .= $this->getHtmlRowBE ('swed_PAYMENT_NAME', $payment->payment_name);
				// keep that test to have it backwards compatible. Old version was deleting that column  when receiving an IPN notification
				if ($payment->payment_order_total and  $payment->payment_order_total != 0.00) {
					$html .= $this->getHtmlRowBE ('swed_PAYMENT_ORDER_TOTAL', $payment->payment_order_total . " " . shopFunctions::getCurrencyByID ($payment->payment_currency, 'currency_code_3'));
				}
				if ($payment->email_currency and  $payment->email_currency != 0) {
					$html .= $this->getHtmlRowBE ('swed_PAYMENT_EMAIL_CURRENCY', shopFunctions::getCurrencyByID ($payment->email_currency, 'currency_code_3'));
				}
				$first = FALSE;
			}
			foreach ($payment as $key => $value) {
				// only displays if there is a value or the value is different from 0.00 and the value
				if ($value) {
					if (substr ($key, 0, strlen ($code)) == $code) {
						$html .= $this->getHtmlRowBE ($key, $value);
					}
				}
			}

		}
		$html .= '</table>' . "\n";
		return $html;
	}

	/**
	 * @param        $virtuemart_order_id
	 * @param string $order_number
	 * @return mixed|string
	 */
	function _getSwedbankInternalData ($virtuemart_order_id, $order_number = '') {

		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
			$q .= " `order_number` = '" . $order_number . "'";
		} else {
			$q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}

		$db->setQuery ($q);
		if (!($payments = $db->loadObjectList ())) {
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		return $payments;
	}

	function _check_email_amount_currency ($payments, $email, $swed_data) {

		/*
		 * TODO Not checking yet because config do not have primary email address
		* Primary email address of the payment recipient (that is, the merchant).
		* If the payment is sent to a non-primary email address on your PayPal account,
		* the receiver_email is still your primary email.
		*/
		/*
		if ($payments[0]->payment_order_total==$email) {
			return true;
		}
		*/
		if ($payments[0]->payment_order_total == $swed_data['mc_gross']) {
			return TRUE;
		}
		$currency_code_3 = shopFunctions::getCurrencyByID ($payments[0]->payment_currency, 'currency_code_3');
		if ($currency_code_3 == $swed_data['mc_currency']) {
			return TRUE;
		}
		$mailsubject = "PayPal Transaction";
		$mailbody = "Hello,
		An IPN notification was received with an invalid amount or currency
		----------------------------------
		IPN Notification content:
		";
		foreach ($swed_data as $key => $value) {
			$mailbody .= $key . " = " . $value . "\n\n";
		}
		$this->sendEmailToVendorAndAdmins ($mailsubject, $mailbody);

		return FALSE;
	}

	/**
	 * @param $method
	 * @return string
	 */
	function _getPaypalUrl ($method) {

		$url = $method->testing ? 'pangalink.net/banklink/008/swedbank' : 'www.swedbank.ee/banklink';

		return $url;
	}

	/**
	 * @param $method
	 * @return string
	 */
	function _getPaypalUrlHttps ($method) {

		$url = $this->_getPaypalUrl ($method);
		//$url = $url . '/cgi-bin/webscr';

		return $url;
	}

	/*
		 * CheckPaypalIPs
		 * Cannot be checked with Sandbox
		 * From VM1.1
		 */

	/**
	 * @param $test_ipn
	 * @return mixed
	 */
	function checkPaypalIps ($test_ipn) {

		return;
		// Get the list of IP addresses for www.paypal.com and notify.paypal.com

		$swed_iplist = gethostbynamel ('www.paypal.com');
		$swed_iplist2 = gethostbynamel ('notify.paypal.com');
		$swed_iplist3 = array('216.113.188.202', '216.113.188.203', '216.113.188.204', '66.211.170.66');
		$swed_iplist = array_merge ($swed_iplist, $swed_iplist2, $swed_iplist3);

		$swed_sandbox_hostname = 'ipn.sandbox.paypal.com';
		$remote_hostname = gethostbyaddr ($_SERVER['REMOTE_ADDR']);

		$valid_ip = FALSE;

		if ($swed_sandbox_hostname == $remote_hostname) {
			$valid_ip = TRUE;
			$hostname = 'www.sandbox.paypal.com';
		} else {
			$ips = "";
			// Loop through all allowed IPs and test if the remote IP connected here
			// is a valid IP address
			if (in_array ($_SERVER['REMOTE_ADDR'], $swed_iplist)) {
				$valid_ip = TRUE;
			}
			$hostname = 'www.paypal.com';
		}

		if (!$valid_ip) {

			$mailsubject = "PayPal IPN Transaction on your site: Possible fraud";
			$mailbody = "Error code 506. Possible fraud. Error with REMOTE IP ADDRESS = " . $_SERVER['REMOTE_ADDR'] . ".
                        The remote address of the script posting to this notify script does not match a valid PayPal ip address\n
            These are the valid IP Addresses: $ips

            The Order ID received was: $invoice";

			$this->sendEmailToVendorAndAdmins ($mailsubject, $mailbody);

			exit();
		}

		if (!($hostname == "www.sandbox.paypal.com" && $test_ipn == 1)) {
			$res = "FAILED";
			$mailsubject = "PayPal Sandbox Transaction";
			$mailbody = "Hello,
		A fatal error occurred while processing a paypal transaction.
		----------------------------------
		Hostname: $hostname
		URI: $uri
		A Paypal transaction was made using the sandbox without your site in Paypal-Debug-Mode";
			//vmMail($mosConfig_mailfrom, $mosConfig_fromname, $debug_email_address, $mailsubject, $mailbody );
			$this->sendEmailToVendorAndAdmins ($mailsubject, $mailbody);
			exit();
		}
	}

	/**
	 * @param $paypalTable
	 * @param $payment_name
	 * @return string
	 */
	function _getPaymentResponseHtml ($swedTable, $payment_name) {

		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow ('SWED_PAYMENT_NAME', $payment_name);
		if (!empty($paypalTable)) {
			$html .= $this->getHtmlRow ('SWED_ORDER_NUMBER', $swedTable->order_number);
		}
		$html .= '</table>' . "\n";

		return $html;
	}

	/**
	 * @param VirtueMartCart $cart
	 * @param                $method
	 * @param                $cart_prices
	 * @return int
	 */
	function getCosts (VirtueMartCart $cart, $method, $cart_prices) {

		if (preg_match ('/%$/', $method->cost_percent_total)) {
			$cost_percent_total = substr ($method->cost_percent_total, 0, -1);
		} else {
			$cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 *
	 * @author: Valerie Isaksen
	 *
	 * @param $cart_prices: cart prices
	 * @param $payment
	 * @return true: if the conditions are fulfilled, false otherwise
	 *
	 */
	protected function checkConditions ($cart, $method, $cart_prices) {

		$this->convert ($method);

		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
			OR
			($method->min_amount <= $amount AND ($method->max_amount == 0)));

		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}
		// probably did not gave his BT:ST address
		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (in_array ($address['virtuemart_country_id'], $countries) || count ($countries) == 0) {
			if ($amount_cond) {
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * @param $method
	 */
	function convert ($method) {

		$method->min_amount = (float)$method->min_amount;
		$method->max_amount = (float)$method->max_amount;
	}

	/**
	 * We must reimplement this triggers for joomla 1.7
	 */

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @author Valérie Isaksen
	 *
	 */
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @author Max Milbers
	 * @author Valérie isaksen
	 *
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
	 *
	 */
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {

		return $this->OnSelectCheck ($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object  $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on succes, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 * @author Valerie Isaksen
	 * @author Max Milbers
	 */
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		return $this->displayListFE ($cart, $selected, $htmlIn);
	}

	/*
		 * plgVmonSelectedCalculatePricePayment
		 * Calculate the price (value, tax_id) of the selected method
		 * It is called by the calculator
		 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
		 * @author Valerie Isaksen
		 * @cart: VirtueMartCart the current cart
		 * @cart_prices: array the new cart prices
		 * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
		 *
		 *
		 */

	/**
	 * @param VirtueMartCart $cart
	 * @param array          $cart_prices
	 * @param                $cart_prices_name
	 * @return bool|null
	 */
	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 *
	 * @author Valerie Isaksen
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 * @author Max Milbers
	 * @author Valerie Isaksen
	 */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * This event is fired during the checkout process. It can be used to validate the
	 * method data as entered by the user.
	 *
	 * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
	 * @author Max Milbers

	public function plgVmOnCheckoutCheckDataPayment($psType, VirtueMartCart $cart) {
	return null;
	}
	 */

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	/**
	 * Save updated order data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not actived.
	 * @author Oscar van Eijk

	public function plgVmOnUpdateOrderPayment(  $_formData) {
	return null;
	}
	 */
	/**
	 * Save updated orderline data to the method specific table
	 *
	 * @param array $_formData Form data
	 * @return mixed, True on success, false on failures (the rest of the save-process will be
	 * skipped!), or null when this method is not actived.
	 * @author Oscar van Eijk

	public function plgVmOnUpdateOrderLine(  $_formData) {
	return null;
	}
	 */
	/**
	 * plgVmOnEditOrderLineBE
	 * This method is fired when editing the order line details in the backend.
	 * It can be used to add line specific package codes
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise
	 * @author Oscar van Eijk

	public function plgVmOnEditOrderLineBE(  $_orderId, $_lineId) {
	return null;
	}
	 */

	/**
	 * This method is fired when showing the order details in the frontend, for every orderline.
	 * It can be used to display line specific package codes, e.g. with a link to external tracking and
	 * tracing systems
	 *
	 * @param integer $_orderId The order ID
	 * @param integer $_lineId
	 * @return mixed Null for method that aren't active, text (HTML) otherwise
	 * @author Oscar van Eijk

	public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
	return null;
	}
	 */
	function plgVmDeclarePluginParamsPayment ($name, $id, &$data) {

		return $this->declarePluginParams ('payment', $name, $id, $data);
	}

	/**
	 * @param $name
	 * @param $id
	 * @param $table
	 * @return bool
	 */
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}

}

// No closing tag
