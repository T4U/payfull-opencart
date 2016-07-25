<?php

set_time_limit(0);

class ModelPaymentPayfull extends Model {

	public function getInstallments(){
		$params = array(
		    //"merchant"        => 'api_merch',
		    "type"            => 'Get',
		    "get_param"       => 'Installments',
		    "language"        => 'tr',
		    "client_ip"       => $_SERVER['REMOTE_ADDR']
		);

		return $this->call($params);
	}

	public function get_card_info(){

		$params = array(
		    "type"            => 'Get',
		    "get_param"       => 'Issuer',
		    "bin"             =>  substr($this->request->post['cc_number'], 0, 6),//'435508',
		    "language"        => 'tr',
		    "client_ip"       => $_SERVER['REMOTE_ADDR'],
		);

		return $this->call($params);
	}

	//save transaction history 
	public function saveResponse($data){

		$sql = "insert into `".DB_PREFIX."payfull_order` SET 
		  `order_id` = '".$data['passive_data']."',
		  `transaction_id` = '".$data['transaction_id']."',
		  `bank_id` = '".$data['bank_id']."',
		  `status` = '".$data['status']."',
		  `use3d` = '".$data['use3d']."',
		  `client_ip` = '".$_SERVER['REMOTE_ADDR']."',
		  `installments` = '".$data['installments']."',
		  `ErrorMSG` = '".$data['ErrorMSG']."',
		  `ErrorCode` = '".$data['ErrorCode']."',
		  `conversion_rate` = '".$data['conversion_rate']."',
		  `try_total` = '".$data['total']."',
		  `original` = '".json_encode($data)."',
		  `date_added` = NOW()";

		$this->db->query($sql);
	}

	//send data to bank 
	public function send(){

		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		$total = $this->currency->format($order_info['total'], $order_info['currency_code'], false, false);

		if(isset($this->request->post['use3d'])){
			$user3d = $this->request->post['use3d'];
		}else{
			$user3d = 0;
		}

		$params = array(		    
		    "type"            => 'Sale',//[mandatory]
		    "total"           => $total,//[mandatory]
		    "cc_name"         => isset($this->request->post['cc_name'])?$this->request->post['cc_name']:'',//[mandatory]
		    "cc_number"       => isset($this->request->post['cc_number'])?$this->request->post['cc_number']:'',//'4355084355084358'[mandatory]
		    "cc_month"        => isset($this->request->post['cc_month'])?$this->request->post['cc_month']:'',//[mandatory]
		    "cc_year"         => isset($this->request->post['cc_year'])?$this->request->post['cc_year']:'',//[mandatory]
		    "cc_cvc"          => isset($this->request->post['cc_cvc'])?$this->request->post['cc_cvc']:'',//[mandatory]

		    "currency"        => $order_info['currency_code'],//'TRY',//[mandatory]
		    "language"        => 'tr',//[mandatory]
		    "client_ip"       => $_SERVER['REMOTE_ADDR'],//[mandatory] //your client IP
		    "payment_title"   => 'Order #'.$order_info['order_id'],//[mandatory]
		    "use3d"           => $user3d, ////[optional]  0 3D off / 1 3D on
		    "return_url"      => $this->url->link('payment/payfull/callback'),//[optional] set return_url 3D secure mode

		    "customer_firstname" => $order_info['firstname'],//[mandatory]
		    "customer_lastname"  => $order_info['lastname'],//[mandatory]
		    "customer_email"     => $order_info['email'],//[mandatory]
		    "customer_phone"     => $order_info['telephone'],//[mandatory]
		   // "customer_tc"        => '1122211122',//[optional]

		    "passive_data"  => $order_info['order_id'],//'####aaaa',//[optional]
		);

		if(isset($this->request->post['installments'])) {
			$params["installments"] = $this->request->post['installments'];//[mandatory]			
		}else{
			$params["installments"] = 1;			
		}
		    
		if(isset($this->session->data['bank_id'])){
			$params["bank_id"] = $this->session->data['bank_id'];//[optional] set bank_id if the installments more than 1
		}
		
		if(isset($this->session->data['gateway'])){
			$params["gateway"] = $this->session->data['gateway'];//'160',//[optional] set bank_id if the installments more than 1
		}

		return $this->call($params);
	}

	public function call($params){

		$merchantPassword = $this->config->get('payfull_password');

		$params["merchant"] = $this->config->get('payfull_username');//[mandatory]

		$api_url = $this->config->get('payfull_endpoint');
		
		//begin HASH calculation
		ksort($params);
		$hashString = "";
		foreach ($params as $key=>$val) {
		    $hashString .= strlen($val) . $val;
		}

		$params["hash"] = hash_hmac("sha1", $hashString, $merchantPassword);
		//end HASH calculation

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api_url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		$response = curl_exec($ch);

		$curlerrcode = curl_errno($ch);
		$curlerr = curl_error($ch);

		return $response;

		/*echo '<pre>';
		var_dump(($response));
		var_dump(json_decode($response));
		echo '</pre>';
		die; */
	}

	public function getMethod($address, $total) {
		$this->load->language('payment/payfull');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('cod_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('payfull_total') > 0 && $this->config->get('payfull_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('payfull_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$method_data = array(
				'code'       => 'payfull',
				'title'      => $this->language->get('text_payfull'),
				'terms'      => '',
				'sort_order' => $this->config->get('payfull_sort_order')
			);
		}

		return $method_data;
	}
}