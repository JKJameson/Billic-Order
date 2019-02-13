<?php
class Order {
	public $settings = array(
		'name' => 'Order',
		'description' => 'This is the default ordering system which allows your users to place orders.',
	);
	function fraud_warning() {
		global $billic, $db;
		echo '<div class="row" style="clear:both"><div class="alert alert-info" role="alert" style="margin:auto"><span class="label label-warning"><i class="icon-lock"></i> Warning</span> Your order is subject to anti-fraud checks. We have logged your IP address ' . $_SERVER['REMOTE_ADDR'] . ' which will be checked before your order is activated.</div></div>';
	}
	function user_area() {
		global $billic, $db;
		$billic->module('FormBuilder');
		if (session_id() == "") { // Start the session if it does not exist
			session_start();
		}
		if (isset($_SESSION['order_save'])) {
			$save = json_decode($_SESSION['order_save'], true);
			$_GET = $save['get'];
			$_POST = $save['post'];
			unset($_SESSION['order_save'], $save);
		}
		$billic->module('Invoices');
		if ($billic->user['blockorders'] == 1) {
			err('You are not allowed to place any new orders. Please contact support if you believe this is a mistake.');
		}
		$license_data = $billic->get_license_data();
		if ($license_data['desc']!='Unlimited') {
			$lic_count = $db->q('SELECT COUNT(*) FROM `services`');
			if ($lic_count[0]['COUNT(*)'] >= $license_data['services']) {
				err('Unable to accept new orders due to capacity. Please contact support.');
			}
		}
		$plan = $db->q('SELECT * FROM `plans` WHERE `name` = ?', urldecode($_GET['Plan']));
		$plan = $plan[0];
		if (empty($plan)) {
			err('The service you are trying to order is no longer available.');
		}
		if (empty($plan['billingcycles'])) {
			err('The plan does not have any Billing Cycles assigned.');
		}
		$plan['billingcycles'] = explode(',', $plan['billingcycles']);
		$orderform = $db->q('SELECT * FROM `orderforms` WHERE `id` = ?', $plan['orderform']);
		$orderform = $orderform[0];
		if (empty($orderform)) {
			err('The order form does not exist.');
		}
		$billic->module($orderform['module']);
		// Base Price
		if (method_exists($billic->modules[$orderform['module']], 'orderprice')) {
			$baseprice = $billic->modules[$orderform['module']]->orderprice($plan);
		} else {
			$baseprice = $plan['price'];
		}
		if ($baseprice == '') {
			$baseprice = 0;
		}
		$billic->set_title('Order ' . safe($plan['name']));
		echo '<h1>Order ' . safe($plan['name']) . '</h1>';
		$form_order = array();
		$orderformitems = $db->q('SELECT * FROM `orderformitems` WHERE `parent` = ? ORDER BY `order` ASC', $plan['orderform']);
		if (empty($orderformitems)) {
			$_POST['Continue'] = true;
		} else {
			foreach ($orderformitems as $r) {
				$form_order[$r['id']] = array(
					'label' => $r['name'],
					'type' => $r['type'],
					'requirement' => $r['requirement'],
					'img' => $r['img'],
					'price' => $r['price'],
				);
				if ($r['type'] == 'slider') {
					$form_order[$r['id']]['min'] = $r['min'];
					$form_order[$r['id']]['max'] = $r['max'];
					$form_order[$r['id']]['step'] = $r['step'];
				}
				if ($r['type'] == 'checkbox') {
					$form_order[$r['id']]['description'] = get_config('billic_currency_prefix') . $r['price'] . get_config('billic_currency_suffix');
				}
				if ($r['type'] == 'dropdown') {
					$options = $db->q('SELECT `id`, `name`, `price` FROM `orderformoptions` WHERE `parent` = ? ORDER BY `order` ASC', $r['id']);
					foreach ($options as $option) {
						$form_order[$r['id']]['options'][$option['id']] = $option['name'] . ($option['price'] > 0 ? ' = ' . get_config('billic_currency_prefix') . $option['price'] . get_config('billic_currency_suffix') : '');
					}
				}
			}
		}
		$form_check = array();
		$form_check['captcha'] = true;
		if ((isset($_POST['Continue']) || isset($_POST['Order'])) && (empty($_POST['changeOrder']) || empty($orderformitems))) {
			if (isset($_POST['base64'])) {
				foreach ($form_order as $key => $opts) {
					if (!empty($_POST[$key])) {
						$_POST[$key] = base64_decode($_POST[$key]);
					}
				}
			}
			if ($_POST['Continue'] !== true) { // true if no orderformitems
				$billic->modules['FormBuilder']->check_everything(array(
					'form' => $form_order,
				));
			}
			/*
				Build $vars to pass to the module
			*/
			$module_vars = array();
			foreach ($orderformitems as $r) {
				if (!empty($r['module_var'])) {
					if ($r['type'] == 'dropdown') {
						$option = $db->q('SELECT `module_var`, `name`, `price` FROM `orderformoptions` WHERE `id` = ?', $_POST[$r['id']]);
						$option = $option[0];
						if (empty($option['module_var'])) {
							$module_vars[$r['module_var']] = $option['name'];
						} else {
							$module_vars[$r['module_var']] = $option['module_var'];
						}
					} else if ($r['type'] == 'slider') {
						$option = $db->q('SELECT `module_var`, `name`, `price` FROM `orderformoptions` WHERE `id` = ?', $_POST[$r['id']]);
						$option = $option[0];
						if (empty($option['module_var'])) {
							$module_vars[$r['module_var']] = $option['name'];
						} else {
							$module_vars[$r['module_var']] = $option['module_var'];
						}
					} else {
						$module_vars[$r['module_var']] = $_POST[$r['id']];
					}
				}
			}
			$plan_vars = json_decode($plan['options'], true);
			if (!empty($plan_vars)) {
				foreach ($plan_vars as $module_var => $options) {
					if (array_key_exists($module_var, $module_vars)) {
						continue;
					}
					if (isset($options['autogen']) && strpos($options['value'], '{$id}') !== false) {
						$module_vars[$module_var] = str_replace('{$id}', '00000', $options['value']);
					}
				}
			}
			$billic->module($orderform['module']);
			if (empty($billic->errors)) {
				$array = array(
					'vars' => $module_vars,
					'plan' => $plan,
				);
				$domain = call_user_func(array(
					$billic->modules[$orderform['module']],
					'ordercheck'
				) , $array);
				if (empty($billic->errors) && empty($domain)) {
					err('The module ' . $orderform['module'] . ' did not return the domain from ordercheck()');
				}
			}
			/*
				Work out the total price
			*/
			if (empty($billic->errors)) {
				if (method_exists($billic->modules[$orderform['module']], 'orderprice')) {
					$total = $billic->modules[$orderform['module']]->orderprice($plan);
				} else {
					$total = $plan['price'];
				}
				//echo 'Base Price: '.$total.'<br>';
				foreach ($orderformitems as $item) {
					if ($item['type'] == 'dropdown') {
						$option = $db->q('SELECT `id`, `name`, `price` FROM `orderformoptions` WHERE `id` = ?', $_POST[$item['id']]);
						$option = $option[0];
						$total+= $option['price'];
						//echo 'Dropdown Item "'.$opt.'": '.$option['price'].'<br>';
						
					} else if ($item['type'] == 'slider') {
						$total+= $item['price'] * $_POST[$item['id']];
					} else if ($item['type'] == 'checkbox' && isset($_POST[$item['id']])) {
						$total+= $item['price'];
					}
				}
			}
			if (empty($billic->errors) && isset($_POST['Order'])) {
				if ($billic->module_exists('Coupons') && (isset($_POST['coupon']) || isset($_POST['apply_coupon']))) {
					if (empty($_POST['coupon'])) {
						if (isset($_POST['apply_coupon'])) {
							$billic->error('No coupon was entered', 'coupon');
						}
					} else if (empty($billic->errors)) {
						$coupon = $db->q('SELECT * FROM `coupons` WHERE `name` = ?', $_POST['coupon']);
						$coupon = $coupon[0];
						if (empty($coupon)) {
							$billic->error('Invalid coupon', 'coupon');
						} else {
							// Does the coupon include this plan?
							$plans = explode('|', $coupon['plans']);
							if (!in_array($plan['name'], $plans)) {
								$billic->error('The coupon ' . safe($coupon['name']) . ' does not apply to the plan ' . safe($plan['name']) . '.', 'coupon');
							} else {
								$coupon['data'] = json_decode($coupon['data'], true);
								// user_limit (Limit the use x times per user)
								$user_limit = $db->q('SELECT COUNT(*) FROM `services` WHERE `userid` = ? AND `coupon_name` = ?', $billic->user['id'], $coupon['name']);
								$user_limit = $user_limit[0]['COUNT(*)'];
								if ($user_limit > $coupon['data']['user_limit']) {
									$billic->error('You have used this coupon too many times', 'coupon');
								}
								// services_limit (Limit to users with no more than x active services)
								$services_limit = $db->q('SELECT COUNT(*) FROM `services` WHERE  `userid` = ?', $billic->user['id']);
								$services_limit = $services_limit[0]['COUNT(*)'];
								if ($user_limit > $coupon['data']['user_limit']) {
									$billic->error('You have too many services to qualify for this coupon', 'coupon');
								}
								// limit users registered between between registered_date_start and registered_date_end
								if ($coupon['data']['registered_date_start'] > $billic->user['datecreated'] || $coupon['data']['registered_date_end'] < $billic->user['datecreated']) {
									$billic->error('This coupon does not apply to your account because you were not registered between ' . $billic->date_display($coupon['data']['registered_date_start']) . ' and ' . $billic->date_display($coupon['data']['registered_date_end']) , 'coupon');
								}
								if (empty($errors)) {
									// limit billingcycles
									foreach ($plan['billingcycles'] as $k => $v) {
										if (!in_array($v, $coupon['data']['billingcycles'])) {
											unset($plan['billingcycles'][$k]);
										}
									}
									$coupon_discount = 0;
									if ($coupon['data']['setup_type'] == 'fixed') {
										$discount = $coupon['data']['setup'];
										$plan['setup']-= $discount;
										$coupon_discount+= $discount;
										if ($plan['setup'] < 0) {
											$plan['setup'] = 0;
										}
									} else if ($coupon['data']['setup_type'] == 'percent') {
										$discount = (($plan['setup'] / 100) * $coupon['data']['setup']);
										$plan['setup']-= $discount;
										$coupon_discount+= $discount;
										if ($plan['setup'] < 0) {
											$plan['setup'] = 0;
										}
									}
									if ($coupon['data']['recurring_type'] == 'fixed') {
										$discount = $coupon['data']['recurring'];
										$total-= $discount;
										$coupon_discount+= $discount;
										if ($total < 0) {
											$total = 0;
										}
									} else if ($coupon['data']['recurring_type'] == 'percent') {
										$discount = (($total / 100) * $coupon['data']['recurring']);
										$total-= $discount;
										$coupon_discount+= $discount;
										if ($total < 0) {
											$total = 0;
										}
									}
									if (isset($_POST['apply_coupon'])) {
										$billic->status = 'updated';
									}
								}
							}
						}
					}
					if (!empty($billic->errors) && array_key_exists('coupon', $billic->errors)) {
						unset($_POST['coupon']);
					}
				}
				if (!isset($_POST['apply_coupon'])) {
					$billic->modules['FormBuilder']->check_everything(array(
						'form' => $form_check,
					));
					if ($_POST['tos_agree'] != 1) {
						$billic->error('You must agree to the Terms and Conditions', 'tos_agree');
					}
					if (empty($billic->errors)) {
						if (empty($billic->user)) {
							if (!array_key_exists('order_save', $_SESSION)) {
								$post = array();
								foreach ($_POST as $k => $v) {
									$post[$k] = base64_encode($v);
								}
								$_SESSION['order_save'] = json_encode(array(
									'post' => $post,
									'get' => $_GET,
									'uri' => '/User/Order/Plan/' . urlencode($plan['name']) . '/',
								));
							}
							$billic->module('Register');
							echo '<div style="float:left;width:50%"><div style="padding:5px">';
							echo '<h1>Existing client</h1>';
							echo '<form method="POST" action="/Login/">';
							echo '<input type="hidden" name="redirect" value="' . base64_encode('/User/Order/Plan/' . urlencode($plan['name']) . '/') . '">';
							echo '<table class="table table-striped">';
							echo '<tr><th colspan="2">Login</th></tr>';
							echo '<tr><td style="width:100px">Email Address</td><td><input type="text" class="form-control" name="email"></td></tr>';
							echo '<tr><td>Password</td><td><input type="password" class="form-control" name="pass"></td></tr>';
							echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" value="Login &raquo;"></td></tr>';
							echo '</table>';
							echo '</form>';
							echo '</div></div>';
							echo '<div style="float:left;width:50%"><div style="padding:5px">';
							echo '<h1>Don\'t have an account?</h1>';
							echo '<form method="POST" action="/User/Register/">';
							echo '<table class="table table-striped">';
							echo '<tr><th colspan="2">Register an Account</th></tr>';
							echo $billic->modules['Register']->register_form();
							echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" name="register" value="Register &raquo;"></td></tr>';
							echo '</table>';
							echo '</form>';
							echo '</div></div>';
							echo '<div style="clear:both"></div>';
							exit;
						}
						if (empty($_POST['billingcycle']) || !in_array($_POST['billingcycle'], $plan['billingcycles'])) {
							$billic->error('Please select a Billing Cycle', 'billingcycle');
						}
						if (empty($billic->errors)) {
							unset($_SESSION['captcha']);
							$time = time();
							$service = array(
								'userid' => $billic->user['id'],
								'packageid' => $plan['id'],
								'plan' => $plan['name'],
								'regdate' => $time,
								'domain' => $domain,
								'amount' => $total,
								'setup' => ($plan['setup']>0?$plan['setup']:0),
								'billingcycle' => $_POST['billingcycle'],
								'nextduedate' => $time,
								'domainstatus' => 'Pending',
								'module' => $orderform['module'],
								'tax_group' => $plan['tax_group'],
								'import_data' => (empty($plan['import_hash']) ? '' : json_encode(array(
									'import_hash' => $plan['import_hash']
								))) ,
							);
							if ($billic->module_exists('Coupons') && !empty($coupon['name'])) {
								$service['coupon_name'] = $coupon['name'];
								$service['coupon_data'] = json_encode($coupon['data']);
							}
							$serviceid = $db->insert('services', $service);
							if (strpos($domain, '00000') !== false) {
								$domain = str_replace('00000', $serviceid, $domain);
								$db->q('UPDATE `services` SET `domain` = ? WHERE `id` = ?', $domain, $serviceid);
							}
							$serviceoptions = $orderformitems;
							$plan_vars = json_decode($plan['options'], true);
							if (!empty($plan_vars)) {
								foreach ($plan_vars as $module_var => $options) {
									if (empty($module_var)) {
										$module_var = $options['label'];
									}
									if (array_key_exists($module_var, $module_vars)) {
										continue;
									}
									if (isset($options['autogen']) && strpos($options['value'], '{$id}') !== false) {
										$options['value'] = str_replace('{$id}', $serviceid, $options['value']);
									}
									$serviceoptions[] = array(
										'type' => 'planstaticvar',
										'name' => $options['label'],
										'module_var' => $module_var,
										'value' => $options['value'],
										'show' => (empty($options['show'])?'':$options['show']),
									);
								}
							}
							foreach ($serviceoptions as $item) {
								if ($item['type'] == 'dropdown') {
									$orderformoption = $db->q('SELECT `name`, `module_var` FROM `orderformoptions` WHERE `id` = ?', $_POST[$item['id']]);
									$orderformoption = $orderformoption[0];
									$value = $orderformoption['module_var'];
									if (empty($value)) {
										$value = $orderformoption['name'];
									}
								} else if ($item['type'] == 'checkbox') {
									if (isset($_POST[$item['id']])) {
										$value = 'Yes';
									} else {
										$value = 'No';
									}
								} else if ($item['type'] == 'planstaticvar') {
									$value = $item['value'];
								} else {
									$value = $_POST[$item['id']];
								}
								$db->insert('serviceoptions', array(
									'serviceid' => $serviceid,
									'name' => $item['name'],
									'module_var' => $item['module_var'],
									'value' => ($value===null?'':$value),
									'show' => (empty($item['show'])?'':$item['show']),
								));
							}
							$service = $db->q('SELECT * FROM `services` WHERE `id` = ?', $serviceid);
							$service = $service[0];
							if (empty($service)) {
								err('service row does not exist');
							}
							if ($plan['prorata_day'] > 0) {
								$prorata_amount = $this->calc_prorata_price($total, $plan);
								$prorata_time = mktime(0, 0, 1, date('m') , $plan['prorata_day']);
							} else {
								$prorata_time = 0;
							}
							$invoiceid = $billic->modules['Invoices']->generate(array(
								'service' => $service,
								'user' => $billic->user,
								'prorata_day' => $plan['prorata_day'],
								'prorata_amount' => $prorata_amount,
								'prorata_time' => $prorata_time,
							));
							if ($invoiceid == 0) {
								$billic->redirect('/User/Services/ID/' . $service['id'] . '/');
							} else {
								$billic->redirect('/User/Invoices/ID/' . $invoiceid . '/');
							}
						}
					}
				}
			}
			if (empty($billic->errors) || isset($_POST['base64'])) {
				$billic->show_errors();
				echo '<table class="table table-striped"><tr><th colspan="3">Order Summary</th></tr>';
				echo '<tr><td width="20%">Plan</td><td>' . safe($plan['name']) . '</td><td>' . get_config('billic_currency_prefix') . number_format($baseprice, 2) . get_config('billic_currency_suffix') . '</td></tr>';
				foreach ($orderformitems as $item) {
					echo '<tr><td>' . safe($item['name']) . '</td>';
					if ($item['type'] == 'dropdown') {
						$option = $db->q('SELECT `id`, `name`, `price` FROM `orderformoptions` WHERE `id` = ?', $_POST[$item['id']]);
						$option = $option[0];
						echo '<td>' . safe($option['name']) . '</td>';
						echo '<td>' . get_config('billic_currency_prefix') . number_format($option['price'], 2) . get_config('billic_currency_suffix') . '</td>';
					} else if ($item['type'] == 'slider') {
						echo '<td>' . safe($_POST[$item['id']]) . '</td>';
						echo '<td>' . get_config('billic_currency_prefix') . number_format($item['price'] * $_POST[$item['id']], 2) . get_config('billic_currency_suffix') . '</td>';
					} else if ($item['type'] == 'checkbox') {
						if ($_POST[$item['id']] == 1) {
							echo '<td>Yes</td>';
							echo '<td>' . get_config('billic_currency_prefix') . number_format($option['price'], 2) . get_config('billic_currency_suffix') . '</td>';
						} else {
							echo '<td>No</td>';
							echo '<td>' . get_config('billic_currency_prefix') . '0.00' . get_config('billic_currency_suffix') . '</td>';
						}
					} else {
						echo '<td colspan="2">' . safe($_POST[$item['id']]) . '</td>';
					}
					echo '</tr>';
				}
				if (isset($coupon_discount)) {
					echo '<tr><td colspan="2" align="right">Coupon Discount:</td><td>- ' . get_config('billic_currency_prefix') . number_format($coupon_discount, 2) . get_config('billic_currency_suffix') . '</td></tr>';
				}
				if ($plan['setup'] > 0) {
					echo '<tr><td colspan="2" align="right">First month with setup fee:</td><td>' . get_config('billic_currency_prefix') . number_format($total + $plan['setup'], 2) . get_config('billic_currency_suffix') . '</td></tr>';
					echo '<tr><td colspan="2" align="right">After first month:</td><td><u>' . get_config('billic_currency_prefix') . number_format($total, 2) . get_config('billic_currency_suffix') . '</u></td></tr>';
					$total+= $plan['setup'];
				} else {
					echo '<tr><td colspan="2" align="right">Total:</td><td><u>' . get_config('billic_currency_prefix') . number_format($total, 2) . get_config('billic_currency_suffix') . '</u></td></tr>';
				}
				echo '</table><br><br>';
				if (empty($_POST['billingcycle'])) {
					$_POST['billingcycle'] = $plan['billingcycledefault'];
				}
				echo '<form method="POST" id="billingcycles" class="form-inline"><table class="table table-striped"><tr><th>Billing Cycle</th><th>Discount</th>' . ($plan['setup'] > 0 ? '<th>Setup Fee</th>' : '') . '<th>You save</th><th>Total due today</th></tr>';
				foreach ($plan['billingcycles'] as $billingcycle) {
					if (empty($plan['import_hash'])) {
						$billingcycle = $db->q('SELECT * FROM `billingcycles` WHERE `name` = ?', $billingcycle);
					} else {
						$billingcycle = $db->q('SELECT * FROM `billingcycles` WHERE `name` = ? AND `import_hash` = ?', $billingcycle, $plan['import_hash']);
					}
					$billingcycle = $billingcycle[0];
					$amount = ($total * $billingcycle['multiplier']);
					$discount = number_format(($amount / 100 * $billingcycle['discount']) , 2);
					$amount = number_format(($amount - $discount) , 2);
					if ($plan['prorata_day'] > 0) {
						$billingcycle_days = ($billingcycle['seconds'] / 24 / 60 / 60);
						if ($billingcycle_days < 28 || $billingcycle_days > 31) {
							echo '<tr><td colspan="20">The billing cycle "' . safe($billingcycle['displayname1']) . '" is not compatible with Pro Rata billing.</td></tr>';
							continue;
						}
						$amount_now = $this->calc_prorata_price($amount, $plan);
					} else {
						$amount_now = $amount;
					}
					echo '<tr><td' . $billic->highlight('billingcycle') . '><input type="radio" name="billingcycle" value="' . safe($billingcycle['name']) . '"' . ($_POST['billingcycle'] == $billingcycle['name'] ? ' checked' : '') . '> ' . $billingcycle['displayname1'] . ($plan['prorata_day'] > 0 ? ' (Pro rata)' : '') . '</td><td>' . $billingcycle['discount'] . '%</td><td>';
					if ($discount > 0) {
						echo '<span class="label label-success">';
					}
					echo get_config('billic_currency_prefix') . $discount . get_config('billic_currency_suffix');
					if ($discount > 0) {
						echo '</span>';
					}
					echo '</td>' . ($plan['setup'] > 0 ? '<th>' . get_config('billic_currency_prefix') . $plan['setup'] . get_config('billic_currency_suffix') . '</th>' : '') . '<td><b>' . get_config('billic_currency_prefix') . $amount_now . get_config('billic_currency_suffix') . ($plan['prorata_day'] > 0 ? ' (Then ' . get_config('billic_currency_prefix') . $amount . get_config('billic_currency_suffix') . ' on the ' . date('jS', mktime(0, 0, 1, date('m') , $plan['prorata_day'])) . ' of every month)' : '') . '</b></td></tr>';
				}
				echo '<input type="hidden" name="base64" value="1">';
				foreach ($form_order as $key => $opts) {
					if (isset($_POST[$key])) {
						echo '<input type="hidden" name="' . safe($key) . '" value="' . base64_encode($_POST[$key]) . '">';
					}
				}
				echo '</table>';
				if ($billic->module_exists('Coupons')) {
					echo '<br><div class="input-group mb-4" style="margin:auto"><input type="text" name="coupon" class="form-control" placeholder="Promo Code" value="' . safe($_POST['coupon']) . '" style="text-align:center"><div class="input-group-append"><button type="submit" name="apply_coupon" class="btn btn-primary">Apply</button></div></div><br>';
				}
				echo '<table class="table table-striped"><tr><th colspan="2">Verify you are human</th></tr>';
				$billic->modules['FormBuilder']->output(array(
					'form' => $form_check,
				));
				echo '<tr><td colspan="2" align="center"' . $billic->highlight('tos_agree') . '><input type="checkbox" name="tos_agree" value="1"' . ($_POST['tos_agree'] == 1 ? ' checked' : '') . '> I have read and agree to the Terms and Conditions</tr>';
				echo '<tr><td colspan="2">';
				if (!empty($orderformitems)) {
					echo '<input type="button" class="btn btn-warning" value="&laquo; Change Order" onClick="changeOrder();">';
				}
				echo '<input type="hidden" name="Order" value="1"><input type="submit" class="btn btn-success pull-right" value="Complete Order &raquo;"></tr></table></form>';
				$this->fraud_warning();
				echo '<form id="changeOrderForm" method="POST"><input type="hidden" name="changeOrder" value="1">';
				foreach ($_POST as $k => $v) {
					echo '<input type="hidden" name="' . safe($k) . '" value="' . safe($v) . '">';
				}
				echo '</form>';
				echo '<script>addLoadEvent(function() { $(\'#billingcycles\').submit(function(){ $(\'input[type=submit]\', this).attr(\'disabled\', \'disabled\'); $(\'input[type=submit]\', this).attr(\'value\', \'Please wait...\'); }); });
function changeOrder() {
	$( "#changeOrderForm" ).submit();
}</script>';
				exit;
			}
		}
		$form_order['Continue'] = array(
			'type' => 'hidden',
			'value' => 1,
		);
		$billic->show_errors();
		echo '<div class="row"><div id="orderFormContainer" class="col-md-7">';
		if (empty($orderform['title'])) {
			$orderform['title'] = 'Configure your order';
		}
		$billic->modules['FormBuilder']->output(array(
			'form' => $form_order,
			//'button' => 'Continue',
			'id' => 'orderForm',
			'title' => $orderform['title'],
		));
		echo '</div>'; // orderFormContainer
		echo '<script>
var summaryNames = new Object();var summaryPrices = new Object();var summaryTypes = new Object();var summaryOptionsNames = new Object();var summaryOptionsPrices = new Object();';
		foreach ($orderformitems as $item) {
			echo 'summaryNames[' . $item['id'] . '] = "' . safe($item['name']) . '";';
			echo 'summaryPrices[' . $item['id'] . '] = "' . $item['price'] . '";';
			echo 'summaryTypes[' . $item['id'] . '] = "' . $item['type'] . '";';
			if ($item['type'] == 'dropdown') {
				echo 'summaryOptionsNames[' . $item['id'] . '] = new Object();';
				echo 'summaryOptionsPrices[' . $item['id'] . '] = new Object();';
				$options = $db->q('SELECT `id`, `name`, `price` FROM `orderformoptions` WHERE `parent` = ? ORDER BY `order` ASC', $item['id']);
				foreach ($options as $option) {
					echo 'summaryOptionsNames[' . $item['id'] . '][' . $option['id'] . '] = "' . safe($option['name']) . '";';
					echo 'summaryOptionsPrices[' . $item['id'] . '][' . $option['id'] . '] = "' . $option['price'] . '";';
				}
			}
		}
		echo 'function orderSummary() {
			var total = Number(\'' . $baseprice . '\');
	var html = \'<table class="table table-striped"><tr><th colspan="3">Order Summary</th></tr><tr><td colspan="2"><b>Plan Cost</b></td><td>' . get_config('billic_currency_prefix') . $baseprice . get_config('billic_currency_suffix') . '</td></tr>\';
	$(\'#orderFormContainer input, #orderFormContainer select, #orderFormContainer textarea\').each(function(index){ 
		//html = html + k + \' \' + v + \' \';
		var el = $(this);
		var name = el.attr(\'name\');
		if ( name === undefined || name == \'Continue\' ) {
			return true;
		}
		var val = \'\';
		var price = \'0.00\';
		//console.log(summaryTypes[name]);
		if (summaryTypes[name] == \'dropdown\') {
			var optionid = el.val();
			//console.log(optionid);
			val = summaryOptionsNames[name][optionid];
			price = summaryOptionsPrices[name][optionid];
		} else
		if (summaryTypes[name] == \'slider\') {
			val = el.val();
			price = (summaryPrices[name] * val);
		} else
		if (summaryTypes[name] == \'checkbox\') {
			if (el.is(\':checked\')) {
				val = \'<span class="label label-success">Yes</span>\';
				price = summaryPrices[name];
			} else {
				val = \'<span class="label label-danger">No</span>\';
			}
		} else {
			if (el.val()==\'\') {
				val = \'N/A\';
			} else {
				//console.log(el.val());
				val = $(\'<div/>\').text(el.val()).html(); // safe encode
				price = summaryPrices[name];
			}
		}

		total = (total+Number(price));
		html = html + \'<tr><td><b>\' + summaryNames[name] + \'</b></td><td>\'+val+\'</td><td>' . get_config('billic_currency_prefix') . '\'+$.number(price, 2)+\'' . get_config('billic_currency_suffix') . '</td></tr>\';
	});
	var html = html + \'<tr><td colspan="2" align="right">Total:</td><td>' . get_config('billic_currency_prefix') . '\'+$.number(total, 2)+\'' . get_config('billic_currency_suffix') . '</td></tr></table>\';
	$( "#orderSummary" ).html(html);
}
addLoadEvent(function() {
';
?>
	// jQuery Number Format
	!function(e){"use strict";function t(e,t){if(this.createTextRange){var a=this.createTextRange();a.collapse(!0),a.moveStart("character",e),a.moveEnd("character",t-e),a.select()}else this.setSelectionRange&&(this.focus(),this.setSelectionRange(e,t))}function a(e){var t=this.value.length;if(e="start"==e.toLowerCase()?"Start":"End",document.selection){var a,i,n,l=document.selection.createRange();return a=l.duplicate(),a.expand("textedit"),a.setEndPoint("EndToEnd",l),i=a.text.length-l.text.length,n=i+l.text.length,"Start"==e?i:n}return"undefined"!=typeof this["selection"+e]&&(t=this["selection"+e]),t}var i={codes:{46:127,188:44,109:45,190:46,191:47,192:96,220:92,222:39,221:93,219:91,173:45,187:61,186:59,189:45,110:46},shifts:{96:"~",49:"!",50:"@",51:"#",52:"$",53:"%",54:"^",55:"&",56:"*",57:"(",48:")",45:"_",61:"+",91:"{",93:"}",92:"|",59:":",39:'"',44:"<",46:">",47:"?"}};e.fn.number=function(n,l,s,r){r="undefined"==typeof r?",":r,s="undefined"==typeof s?".":s,l="undefined"==typeof l?0:l;var u="\\u"+("0000"+s.charCodeAt(0).toString(16)).slice(-4),h=new RegExp("[^"+u+"0-9]","g"),o=new RegExp(u,"g");return n===!0?this.is("input:text")?this.on({"keydown.format":function(n){var u=e(this),h=u.data("numFormat"),o=n.keyCode?n.keyCode:n.which,c="",v=a.apply(this,["start"]),d=a.apply(this,["end"]),p="",f=!1;if(i.codes.hasOwnProperty(o)&&(o=i.codes[o]),!n.shiftKey&&o>=65&&90>=o?o+=32:!n.shiftKey&&o>=69&&105>=o?o-=48:n.shiftKey&&i.shifts.hasOwnProperty(o)&&(c=i.shifts[o]),""==c&&(c=String.fromCharCode(o)),8!=o&&45!=o&&127!=o&&c!=s&&!c.match(/[0-9]/)){var g=n.keyCode?n.keyCode:n.which;if(46==g||8==g||127==g||9==g||27==g||13==g||(65==g||82==g||80==g||83==g||70==g||72==g||66==g||74==g||84==g||90==g||61==g||173==g||48==g)&&(n.ctrlKey||n.metaKey)===!0||(86==g||67==g||88==g)&&(n.ctrlKey||n.metaKey)===!0||g>=35&&39>=g||g>=112&&123>=g)return;return n.preventDefault(),!1}if(0==v&&d==this.value.length?8==o?(v=d=1,this.value="",h.init=l>0?-1:0,h.c=l>0?-(l+1):0,t.apply(this,[0,0])):c==s?(v=d=1,this.value="0"+s+new Array(l+1).join("0"),h.init=l>0?1:0,h.c=l>0?-(l+1):0):45==o?(v=d=2,this.value="-0"+s+new Array(l+1).join("0"),h.init=l>0?1:0,h.c=l>0?-(l+1):0,t.apply(this,[2,2])):(h.init=l>0?-1:0,h.c=l>0?-l:0):h.c=d-this.value.length,h.isPartialSelection=v==d?!1:!0,l>0&&c==s&&v==this.value.length-l-1)h.c++,h.init=Math.max(0,h.init),n.preventDefault(),f=this.value.length+h.c;else if(45!=o||0==v&&0!=this.value.indexOf("-"))if(c==s)h.init=Math.max(0,h.init),n.preventDefault();else if(l>0&&127==o&&v==this.value.length-l-1)n.preventDefault();else if(l>0&&8==o&&v==this.value.length-l)n.preventDefault(),h.c--,f=this.value.length+h.c;else if(l>0&&127==o&&v>this.value.length-l-1){if(""===this.value)return;"0"!=this.value.slice(v,v+1)&&(p=this.value.slice(0,v)+"0"+this.value.slice(v+1),u.val(p)),n.preventDefault(),f=this.value.length+h.c}else if(l>0&&8==o&&v>this.value.length-l){if(""===this.value)return;"0"!=this.value.slice(v-1,v)&&(p=this.value.slice(0,v-1)+"0"+this.value.slice(v),u.val(p)),n.preventDefault(),h.c--,f=this.value.length+h.c}else 127==o&&this.value.slice(v,v+1)==r?n.preventDefault():8==o&&this.value.slice(v-1,v)==r?(n.preventDefault(),h.c--,f=this.value.length+h.c):l>0&&v==d&&this.value.length>l+1&&v>this.value.length-l-1&&isFinite(+c)&&!n.metaKey&&!n.ctrlKey&&!n.altKey&&1===c.length&&(p=d===this.value.length?this.value.slice(0,v-1):this.value.slice(0,v)+this.value.slice(v+1),this.value=p,f=v);else n.preventDefault();f!==!1&&t.apply(this,[f,f]),u.data("numFormat",h)},"keyup.format":function(i){var n,s=e(this),r=s.data("numFormat"),u=i.keyCode?i.keyCode:i.which,h=a.apply(this,["start"]),o=a.apply(this,["end"]);0!==h||0!==o||189!==u&&109!==u||(s.val("-"+s.val()),h=1,r.c=1-this.value.length,r.init=1,s.data("numFormat",r),n=this.value.length+r.c,t.apply(this,[n,n])),""===this.value||(48>u||u>57)&&(96>u||u>105)&&8!==u&&46!==u&&110!==u||(s.val(s.val()),l>0&&(r.init<1?(h=this.value.length-l-(r.init<0?1:0),r.c=h-this.value.length,r.init=1,s.data("numFormat",r)):h>this.value.length-l&&8!=u&&(r.c++,s.data("numFormat",r))),46!=u||r.isPartialSelection||(r.c++,s.data("numFormat",r)),n=this.value.length+r.c,t.apply(this,[n,n]))},"paste.format":function(t){var a=e(this),i=t.originalEvent,n=null;return window.clipboardData&&window.clipboardData.getData?n=window.clipboardData.getData("Text"):i.clipboardData&&i.clipboardData.getData&&(n=i.clipboardData.getData("text/plain")),a.val(n),t.preventDefault(),!1}}).each(function(){var t=e(this).data("numFormat",{c:-(l+1),decimals:l,thousands_sep:r,dec_point:s,regex_dec_num:h,regex_dec:o,init:this.value.indexOf(".")?!0:!1});""!==this.value&&t.val(t.val())}):this.each(function(){var t=e(this),a=+t.text().replace(h,"").replace(o,".");t.number(isFinite(a)?+a:0,l,s,r)}):this.text(e.number.apply(window,arguments))};var n=null,l=null;e.isPlainObject(e.valHooks.text)?(e.isFunction(e.valHooks.text.get)&&(n=e.valHooks.text.get),e.isFunction(e.valHooks.text.set)&&(l=e.valHooks.text.set)):e.valHooks.text={},e.valHooks.text.get=function(t){var a,i=e(t),l=i.data("numFormat");return l?""===t.value?"":(a=+t.value.replace(l.regex_dec_num,"").replace(l.regex_dec,"."),(0===t.value.indexOf("-")?"-":"")+(isFinite(a)?a:0)):e.isFunction(n)?n(t):void 0},e.valHooks.text.set=function(t,a){var i=e(t),n=i.data("numFormat");if(n){var s=e.number(a,n.decimals,n.dec_point,n.thousands_sep);return e.isFunction(l)?l(t,s):t.value=s}return e.isFunction(l)?l(t,a):void 0},e.number=function(e,t,a,i){i="undefined"==typeof i?"1000"!==new Number(1e3).toLocaleString()?new Number(1e3).toLocaleString().charAt(1):"":i,a="undefined"==typeof a?new Number(.1).toLocaleString().charAt(1):a,t=isFinite(+t)?Math.abs(t):0;var n="\\u"+("0000"+a.charCodeAt(0).toString(16)).slice(-4),l="\\u"+("0000"+i.charCodeAt(0).toString(16)).slice(-4);e=(e+"").replace(".",a).replace(new RegExp(l,"g"),"").replace(new RegExp(n,"g"),".").replace(new RegExp("[^0-9+-Ee.]","g"),"");var s=isFinite(+e)?+e:0,r="",u=function(e,t){return""+ +(Math.round((""+e).indexOf("e")>0?e:e+"e+"+t)+"e-"+t)};return r=(t?u(s,t):""+Math.round(s)).split("."),r[0].length>3&&(r[0]=r[0].replace(/\B(?=(?:\d{3})+(?!\d))/g,i)),(r[1]||"").length<t&&(r[1]=r[1]||"",r[1]+=new Array(t-r[1].length+1).join("0")),r.join(a)}}(jQuery);
	<?php
		echo '
	
	$( "select" ).change(function() {
		orderSummary();
	});
	$( "input" ).change(function() {
		orderSummary();
	});
	orderSummary();
});
</script>';
		echo '<div class="col-md-5" style="margin-bottom:20px"><div id="orderSummary"></div></div></div><div align="center"><input type="button" class="btn btn-success" value="Continue to next stage &raquo;" onClick="submitOrderForm();"></div><br>';
		echo '<script>function submitOrderForm() { $( "#orderForm" ).submit(); }</script>';
		$this->fraud_warning();
	}
	function calc_prorata_price($amount, $plan) {
		$min_prorata_days = 1; // TODO: Add setting per plan
		$maxDaysInMonth = 31;
		$proratatime = mktime(0, 0, 1, date('m') , $plan['prorata_day']);
		$testdaynow = time();
		$priceperday = ($amount / $maxDaysInMonth);
		$days_until_renewal = abs($proratatime - time()) / 86400;
		if ($days_until_renewal < $min_prorata_days) {
			$days_until_renewal = $maxDaysInMonth; // charge full price
			
		}
		$dayofthemonth = date('j');
		if ($dayofthemonth == $plan['prorata_day']) {
			$amount_now = $amount;
		} else if ($dayofthemonth > $plan['prorata_day']) {
			$amount_now = round($amount - ($priceperday * $days_until_renewal) , 2);
		} else {
			$amount_now = round(($priceperday * $days_until_renewal) , 2);
		}
		return $amount_now;
	}
	function build_plan_export_array($plan_name) {
		global $billic, $db;
		$plan = $db->q('SELECT * FROM `plans` WHERE `name` = ?', $plan_name);
		$plan = $plan[0];
		$orderform = $db->q('SELECT * FROM `orderforms` WHERE `id` = ?', $plan['orderform']);
		$orderform = $orderform[0];
		if (empty($orderform)) {
			return false;
		}
		$plan['orderform'] = $orderform;
		unset($plan['orderform']['id']);
		unset($plan['id']);
		unset($plan['hide']);
		$items = $db->q('SELECT * FROM `orderformitems` WHERE `parent` = ?', $orderform['id']);
		foreach ($items as $item) {
			$options = $db->q('SELECT * FROM `orderformoptions` WHERE `parent` = ?', $item['id']);
			foreach ($options as $option) {
				unset($option['id']);
				//unset($option['module_var']); // this get's replaced with the name so that it can be referenced by the RemoteBillicService module
				$item['options'][] = $option;
			}
			unset($item['id']);
			//unset($item['module_var']); // this get's replaced with the name so that it can be referenced by the RemoteBillicService module
			$plan['items'][] = $item;
		}
		$billingcycles = explode(',', $plan['billingcycles']);
		$plan['billingcycles'] = array();
		foreach ($billingcycles as $billingcycle) {
			$billingcycle = $db->q('SELECT * FROM `billingcycles` WHERE `name` = ? AND `import_hash` = ?', $billingcycle, $plan['import_hash']);
			$billingcycle = $billingcycle[0];
			if (empty($billingcycle)) {
				continue;
			}
			$plan['billingcycles'][$billingcycle['name']] = $billingcycle;
		}
		// generate hash
		$plan['hash'] = hash('sha512', json_encode($plan));
		return $plan;
	}
	function api() {
		global $billic, $db;
		$billic->force_login();
		if ($_POST['request'] == 'list_plans') {
			$plans = $db->q('SELECT `name` FROM `plans` WHERE `hide` = ?', 0);
			foreach ($plans as $key => $plan) {
				$plan = $this->build_plan_export_array($plan['name']);
				if ($plan === false) {
					continue;
				}
				// don't send these to the remote billic, but we this data stored in exported_plans
				unset($plan['options']);
				unset($plan['orderform']['module']);
				$plans[$key] = $plan;
			}
			echo json_encode($plans);
		} else if ($_POST['request'] == 'export_plan') {
			$plan = $this->build_plan_export_array($_POST['plan']);
			$hash = $plan['hash'];
			unset($plan['hash']);
			$snapshot_exists = $db->q('SELECT COUNT(*) FROM `exported_plans` WHERE `hash` = ?', $hash);
			if ($snapshot_exists[0]['COUNT(*)'] == 0) {
				// create snapshot
				$db->insert('exported_plans', array(
					'hash' => $hash,
					'data' => json_encode($plan) ,
					'created' => time() ,
				));
			}
			$plan['hash'] = $hash;
			// don't send these to the remote billic, but we this data stored in exported_plans
			unset($plan['options']);
			unset($plan['orderform']['module']);
			echo json_encode($plan);
		} else if ($_POST['request'] == 'call_ordercheck') {
			$import_hash = trim($_POST['import_hash']);
			if (strlen($import_hash) != 128) {
				echo json_encode(array(
					'error' => 'import_hash is invalid'
				));
				return;
			}
			$exported_plan = $db->q('SELECT * FROM `exported_plans` WHERE `hash` = ?', $import_hash);
			$exported_plan = $exported_plan[0];
			if (empty($exported_plan)) {
				echo json_encode(array(
					'error' => 'Exported plan no longer exists at the remote Billic'
				));
				return;
			}
			$plan = json_decode($exported_plan['data'], true);
			if ($plan === null || empty($plan)) {
				echo json_encode(array(
					'error' => 'The exported plan data is corrupt'
				));
				return;
			}
			$plan['options'] = json_decode($plan['options'], true);
			if ($plan['options'] === null) {
				echo json_encode(array(
					'error' => 'The exported plan options data is corrupt'
				));
				return;
			}
			if (empty($plan['orderform']['module'])) {
				echo json_encode(array(
					'error' => 'The exported plan does not have a module configured. It must be assigned to the plan before it is exported. Please try deleting the imported plan and import it again.'
				));
				return;
			}
			$order_vars = json_decode(trim($_POST['order_vars']) , true);
			if ($order_vars === null) {
				echo json_encode(array(
					'error' => 'The order vars passed to the remote Billic were corrupted'
				));
				return;
			}
			// Build a list of expected module_vars
			$module_vars = array();
			if (is_array($plan['items'])) {
				foreach ($plan['items'] as $item) {
					foreach ($order_vars as $k => $v) {
						if ($item['name'] == $k) {
							if (empty($item['module_var'])) {
								continue;
							}
							if ($item['type'] == 'dropdown') {
								if (is_array($item['options'])) {
									foreach ($item['options'] as $option) {
										if ($option['name'] == $v) {
											$module_vars[$item['module_var']] = $option['module_var'];
										}
									}
								}
							} else {
								$module_vars[$item['module_var']] = $v;
							}
						}
					}
				}
			}
			// Replace any module_vars with hard coded values from the plan settings
			if (is_array($plan['options'])) {
				foreach ($plan['options'] as $k => $option) {
					if (isset($option['autogen']) && strpos($option['value'], '{$id}') !== false) {
						$option['value'] = str_replace('{$id}', '00000', $option['value']);
					}
					$module_vars[$k] = $option['value'];
				}
			}
			$billic->module($plan['orderform']['module']);
			$array = array(
				'vars' => $module_vars,
				'plan' => $plan,
			);
			$domain = call_user_func(array(
				$billic->modules[$plan['orderform']['module']],
				'ordercheck'
			) , $array);
			if (empty($billic->errors) && empty($domain)) {
				err('The module ' . $plan['orderform']['module'] . ' did not return the domain from ordercheck()');
			}
			if (!empty($billic->errors)) {
				foreach ($billic->errors as $error) {
					echo json_encode(array(
						'error' => $error
					));
					return;
				}
			}
			echo json_encode(array(
				'domain' => $domain
			));
			return;
		} else if ($_POST['request'] == 'call_placeorder') {
			$import_hash = trim($_POST['import_hash']);
			if (strlen($import_hash) != 128) {
				echo json_encode(array(
					'error' => 'import_hash is invalid'
				));
				return;
			}
			$exported_plan = $db->q('SELECT * FROM `exported_plans` WHERE `hash` = ?', $import_hash);
			$exported_plan = $exported_plan[0];
			if (empty($exported_plan)) {
				echo json_encode(array(
					'error' => 'Exported plan no longer exists at the remote Billic'
				));
				return;
			}
			$plan = json_decode($exported_plan['data'], true);
			if ($plan === null) {
				echo json_encode(array(
					'error' => 'The exported plan data is corrupt'
				));
				return;
			}
			if (empty($plan['orderform']['module'])) {
				echo json_encode(array(
					'error' => 'The exported plan does not have a module configured. It must be assigned to the plan before it is exported. Please try deleting the imported plan and import it again.'
				));
				return;
			}
			$billic->module($plan['orderform']['module']);
			$billic->module('Invoices');
			$order_vars = json_decode(trim($_POST['order_vars']) , true);
			if ($order_vars === null) {
				echo json_encode(array(
					'error' => 'The order vars passed to the remote Billic were corrupted'
				));
				return;
			}
			// Build a list of expected module_vars
			$module_vars = array();
			if (is_array($plan['items'])) {
				foreach ($plan['items'] as $item) {
					foreach ($order_vars as $k => $v) {
						if ($item['name'] == $k) {
							if (empty($item['module_var'])) {
								continue;
							}
							if ($item['type'] == 'dropdown') {
								if (is_array($item['options'])) {
									foreach ($item['options'] as $option) {
										if ($option['name'] == $v) {
											$module_vars[$item['module_var']] = $option['module_var'];
										}
									}
								}
							} else {
								$module_vars[$item['module_var']] = $v;
							}
						}
					}
				}
			}
			// Replace any module_vars with hard coded values from the plan settings
			if (is_array($plan['options'])) {
				foreach ($plan['options'] as $k => $option) {
					if (!empty($option['label']) || !empty($option['value'])) {
						$module_vars[$k] = $option['value'];
					}
				}
			}
			// Base Price
			if (method_exists($billic->modules[$plan['orderform']['module']], 'orderprice')) {
				$baseprice = $billic->modules[$plan['orderform']['module']]->orderprice($plan);
			} else {
				$baseprice = $plan['price'];
			}
			if ($baseprice == '') {
				$baseprice = 0;
			}
			/*
			   Work out the total price
			*/
			$price = $baseprice;
			$serviceoptions = array();
			foreach ($order_vars as $name => $value) {
				foreach ($plan['items'] as $item) {
					if ($item['name'] == $name) {
						if ($item['requirement'] == 'required' && empty($value)) {
							echo json_encode(array(
								'error' => 'The value of "' . $name . '" is required but was not provided'
							));
							return;
						} else if ($item['requirement'] == 'alphanumeric' && !ctype_alnum($value)) {
							echo json_encode(array(
								'error' => 'The value of "' . $name . '" is must be alphanumeric'
							));
							return;
						} else if ($item['requirement'] == 'email' && !valid_email($value)) {
							echo json_encode(array(
								'error' => 'The value of "' . $name . '" is not a valid email address'
							));
							return;
						} else {
							if (($item['type'] == 'text' || $item['type'] == 'textarea' || $item['type'] == 'password') && !empty($value)) {
							}
							if (($item['type'] == 'text' || $item['type'] == 'textarea' || $item['type'] == 'password') && !empty($value)) {
								$price+= $item['price'];
							} else if ($item['type'] == 'slider') {
								if ($value < $item['min']) {
									echo json_encode(array(
										'error' => 'The value of "' . $name . '" can not be less than ' . $item['min']
									));
									return;
								} else if ($value > $item['max']) {
									echo json_encode(array(
										'error' => 'The value of "' . $name . '" can not be more than ' . $item['max']
									));
									return;
								}
								$price+= ($item['price'] * $value);
							} else if ($item['type'] == 'dropdown') {
								$valid = false;
								if (is_array($item['options'])) {
									foreach ($item['options'] as $option) {
										if ($option['name'] == $value) {
											$valid = true;
											$price+= $option['price'];
											$value = $option['module_var'];
											break;
										}
									}
								}
								if ($valid === false) {
									echo json_encode(array(
										'error' => 'The value of "' . $name . '" is an invalid selection'
									));
									return;
								}
							} else if ($item['type'] == 'checkbox' && $value == 'Yes') {
								$price+= $item['price'];
							} else {
								echo json_encode(array(
									'error' => 'Undefined type for API Order "' . $item['type'] . '"'
								));
								return;
							}
						}
						$serviceoptions[] = array(
							//'type' => 'planstaticvar',
							'name' => $item['name'],
							'module_var' => $item['module_var'],
							'value' => $value,
							'show' => $item['show'],
						);
					}
				}
			}
			$plan['options'] = json_decode(trim($plan['options']) , true);
			if ($plan['options'] === null) {
				echo json_encode(array(
					'error' => 'The exported plan\'s options are corrupt'
				));
				return;
			}
			foreach ($plan['options'] as $module_var => $option) {
				$serviceoptions[] = array(
					'type' => 'planstaticvar',
					'name' => $option['label'],
					'module_var' => $module_var,
					'value' => $option['value'],
					'show' => $option['show'],
				);
			}
			if (empty($_POST['billingcycle'])) {
				echo json_encode(array(
					'error' => 'Billing Cycle name was not provided'
				));
				return;
			}
			if (!array_key_exists($_POST['billingcycle'], $plan['billingcycles'])) {
				echo json_encode(array(
					'error' => 'The passed billing cycle is an invalid choice'
				));
				return;
			}
			if ($billic->user['credit'] - $price < 0) {
				echo json_encode(array(
					'error' => 'You do not have enough credit in your account to place this order of ' . get_config('billic_currency_prefix') . $price . get_config('billic_currency_suffix')
				));
				return;
			}
			$time = time();
			$array = array(
				'vars' => $module_vars,
				'plan' => $plan,
			);
			$domain = call_user_func(array(
				$billic->modules[$plan['orderform']['module']],
				'ordercheck'
			) , $array);
			if (empty($billic->errors) && empty($domain)) {
				echo json_encode(array(
					'error' => 'The module ' . $plan['orderform']['module'] . ' did not return the domain from ordercheck()'
				));
				return;
			}
			$serviceid = $db->insert('services', array(
				'userid' => $billic->user['id'],
				'packageid' => $import_hash,
				'plan' => $plan['name'],
				'regdate' => $time,
				'domain' => $domain,
				'amount' => $price,
				'billingcycle' => $_POST['billingcycle'],
				'nextduedate' => $time,
				'domainstatus' => 'Pending',
				'module' => $plan['orderform']['module'],
				'tax_group' => $plan['tax_group'],
			));
			foreach ($serviceoptions as $option) {
				$db->insert('serviceoptions', array(
					'serviceid' => $serviceid,
					'name' => $option['name'],
					'module_var' => $option['module_var'],
					'value' => $option['value'],
					'show' => $option['show'],
				));
			}
			$service = $db->q('SELECT * FROM `services` WHERE `id` = ?', $serviceid);
			$service = $service[0];
			if (empty($service)) {
				echo json_encode(array(
					'error' => 'Sanity failure while fetching the newly created service. It does not exist?!'
				));
				return;
			}
			if ($plan['prorata_day'] > 0) {
				$prorata_amount = $this->calc_prorata_price($price, $plan);
				$prorata_time = mktime(0, 0, 1, date('m') , $plan['prorata_day']);
			} else {
				$prorata_time = 0;
			}
			$invoiceid = $billic->modules['Invoices']->generate(array(
				'service' => $service,
				'user' => $billic->user,
				'prorata_day' => $plan['prorata_day'],
				'prorata_amount' => $prorata_amount,
				'prorata_time' => $prorata_time,
			));
			$invoice = $db->q('SELECT * FROM `invoices` WHERE `id` = ?', $invoiceid);
			$invoice = $invoice[0];
			if (empty($invoice)) {
				echo json_encode(array(
					'error' => 'Sanity failure while fetching the newly created invoice. It does not exist?!'
				));
				return;
			}
			// pay invoice with credit
			$error = $billic->modules['Invoices']->addpayment(array(
				'gateway' => 'credit',
				'invoiceid' => $invoiceid,
				'amount' => $invoice['total'],
				'currency' => get_config('billic_currency_code') ,
				'transactionid' => 'credit',
			));
			if ($error !== true) {
				echo json_encode(array(
					'error' => 'Failed to apply credit to invoice: ' . $error
				));
				return;
			}
			echo json_encode(array(
				'remote_service_id' => $serviceid
			));
			return;
		}
	}
}
