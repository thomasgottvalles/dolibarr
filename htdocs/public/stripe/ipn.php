<?php
/* Copyright (C) 2018-2020      Thibault FOUCART        <support@ptibogxiv.net>
 * Copyright (C) 2018       	Frédéric France         <frederic.france@netlogic.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('NOLOGIN')) {
	define("NOLOGIN", 1); // This means this output page does not require to be logged.
}
if (!defined('NOCSRFCHECK')) {
	define("NOCSRFCHECK", 1); // We accept to go on this page from external web site.
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1'); // Do not check IP defined into conf $dolibarr_main_restrict_ip
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

$entity = (!empty($_GET['entity']) ? (int) $_GET['entity'] : (!empty($_POST['entity']) ? (int) $_POST['entity'] : 1));
if (is_numeric($entity)) {
	define("DOLENTITY", $entity);
}

// So log file will have a suffix
if (!defined('USESUFFIXINLOG')) {
	define('USESUFFIXINLOG', '_stripeipn');
}

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/ccountry.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/prelevement/class/bonprelevement.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/includes/stripe/stripe-php/init.php';
require_once DOL_DOCUMENT_ROOT.'/stripe/class/stripe.class.php';


// You can find your endpoint's secret in your webhook settings
if (isset($_GET['connect'])) {
	if (isset($_GET['test'])) {
		$endpoint_secret = $conf->global->STRIPE_TEST_WEBHOOK_CONNECT_KEY;
		$service = 'StripeTest';
		$servicestatus = 0;
	} else {
		$endpoint_secret = $conf->global->STRIPE_LIVE_WEBHOOK_CONNECT_KEY;
		$service = 'StripeLive';
		$servicestatus = 1;
	}
} else {
	if (isset($_GET['test'])) {
		$endpoint_secret = $conf->global->STRIPE_TEST_WEBHOOK_KEY;
		$service = 'StripeTest';
		$servicestatus = 0;
	} else {
		$endpoint_secret = $conf->global->STRIPE_LIVE_WEBHOOK_KEY;
		$service = 'StripeLive';
		$servicestatus = 1;
	}
}

if (!isModEnabled('stripe')) {
	httponly_accessforbidden('Module Stripe not enabled');
}

if (empty($endpoint_secret)) {
	httponly_accessforbidden('Error: Setup of module Stripe not complete for mode '.dol_escape_htmltag($service).'. The WEBHOOK_KEY is not defined.', 400, 1);
}

if (!empty($conf->global->STRIPE_USER_ACCOUNT_FOR_ACTIONS)) {
	// We set the user to use for all ipn actions in Dolibarr
	$user = new User($db);
	$user->fetch($conf->global->STRIPE_USER_ACCOUNT_FOR_ACTIONS);
	$user->getrights();
} else {
	httponly_accessforbidden('Error: Setup of module Stripe not complete for mode '.dol_escape_htmltag($service).'. The STRIPE_USER_ACCOUNT_FOR_ACTIONS is not defined.', 400, 1);
}


// TODO Add a check on a security key



/*
 * Actions
 */

$payload = @file_get_contents("php://input");
$sig_header = empty($_SERVER["HTTP_STRIPE_SIGNATURE"]) ? '' : $_SERVER["HTTP_STRIPE_SIGNATURE"];
$event = null;

if (getDolGlobalString('STRIPE_DEBUG')) {
	$fh = fopen(DOL_DATA_ROOT.'/dolibarr_stripe.log', 'w+');
	if ($fh) {
		fwrite($fh, dol_print_date(dol_now('gmt'), 'standard').' HTTP_STRIPE_SIGNATURE='.$sig_header."\n");
		fwrite($fh, $payload);
		fclose($fh);
		dolChmod(DOL_DATA_ROOT.'/dolibarr_stripe.log');
	}
}

$error = 0;

try {
	$event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
	// Invalid payload
	httponly_accessforbidden('Invalid payload', 400);
} catch (\Stripe\Error\SignatureVerification $e) {
	httponly_accessforbidden('Invalid signature', 400);
} catch (Exception $e) {
	httponly_accessforbidden('Error '.$e->getMessage(), 400);
}

// Do something with $event

$langs->load("main");


if (isModEnabled('multicompany') && !empty($conf->stripeconnect->enabled) && is_object($mc)) {
	$sql = "SELECT entity";
	$sql .= " FROM ".MAIN_DB_PREFIX."oauth_token";
	$sql .= " WHERE service = '".$db->escape($service)."' and tokenstring LIKE '%".$db->escape($event->account)."%'";

	dol_syslog(get_class($db)."::fetch", LOG_DEBUG);
	$result = $db->query($sql);
	if ($result) {
		if ($db->num_rows($result)) {
			$obj = $db->fetch_object($result);
			$key = $obj->entity;
		} else {
			$key = 1;
		}
	} else {
		$key = 1;
	}
	$ret = $mc->switchEntity($key);
}

// list of  action
$stripe = new Stripe($db);

// Subject
$societeName = $conf->global->MAIN_INFO_SOCIETE_NOM;
if (!empty($conf->global->MAIN_APPLICATION_TITLE)) {
	$societeName = $conf->global->MAIN_APPLICATION_TITLE;
}

top_httphead();

dol_syslog("***** Stripe IPN was called with event->type = ".$event->type);


if ($event->type == 'payout.created') {
	$error = 0;

	$result = dolibarr_set_const($db, $service."_NEXTPAYOUT", date('Y-m-d H:i:s', $event->data->object->arrival_date), 'chaine', 0, '', $conf->entity);

	if ($result > 0) {
		$subject = $societeName.' - [NOTIFICATION] Stripe payout scheduled';
		if (!empty($user->email)) {
			$sendto = dolGetFirstLastname($user->firstname, $user->lastname)." <".$user->email.">";
		} else {
			$sendto = $conf->global->MAIN_INFO_SOCIETE_MAIL.'" <'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'>';
		}
		$replyto = $sendto;
		$sendtocc = '';
		if (!empty($conf->global->ONLINE_PAYMENT_SENDEMAIL)) {
			$sendtocc = $conf->global->ONLINE_PAYMENT_SENDEMAIL.'" <'.$conf->global->ONLINE_PAYMENT_SENDEMAIL.'>';
		}

		$message = "A bank transfer of ".price2num($event->data->object->amount / 100)." ".$event->data->object->currency." should arrive in your account the ".dol_print_date($event->data->object->arrival_date, 'dayhour');

		$mailfile = new CMailFile(
			$subject,
			$sendto,
			$replyto,
			$message,
			array(),
			array(),
			array(),
			$sendtocc,
			'',
			0,
			-1
		);

		$ret = $mailfile->sendfile();

		return 1;
	} else {
		$error++;
		http_response_code(500);
		return -1;
	}
} elseif ($event->type == 'payout.paid') {
	$error = 0;
	$result = dolibarr_set_const($db, $service."_NEXTPAYOUT", null, 'chaine', 0, '', $conf->entity);
	if ($result) {
		$langs->load("errors");

		$dateo = dol_now();
		$label = $event->data->object->description;
		$amount = $event->data->object->amount / 100;
		$amount_to = $event->data->object->amount / 100;
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

		$accountfrom = new Account($db);
		$accountfrom->fetch($conf->global->STRIPE_BANK_ACCOUNT_FOR_PAYMENTS);

		$accountto = new Account($db);
		$accountto->fetch($conf->global->STRIPE_BANK_ACCOUNT_FOR_BANKTRANSFERS);

		if (($accountto->id != $accountfrom->id) && empty($error)) {
			$bank_line_id_from = 0;
			$bank_line_id_to = 0;
			$result = 0;

			// By default, electronic transfert from bank to bank
			$typefrom = 'PRE';
			$typeto = 'VIR';

			if (!$error) {
				$bank_line_id_from = $accountfrom->addline($dateo, $typefrom, $label, -1 * price2num($amount), '', '', $user);
			}
			if (!($bank_line_id_from > 0)) {
				$error++;
			}
			if (!$error) {
				$bank_line_id_to = $accountto->addline($dateo, $typeto, $label, price2num($amount), '', '', $user);
			}
			if (!($bank_line_id_to > 0)) {
				$error++;
			}

			if (!$error) {
				$result = $accountfrom->add_url_line($bank_line_id_from, $bank_line_id_to, DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');
			}
			if (!($result > 0)) {
				$error++;
			}
			if (!$error) {
				$result = $accountto->add_url_line($bank_line_id_to, $bank_line_id_from, DOL_URL_ROOT.'/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');
			}
			if (!($result > 0)) {
				$error++;
			}
		}

		$subject = $societeName.' - [NOTIFICATION] Stripe payout done';
		if (!empty($user->email)) {
			$sendto = dolGetFirstLastname($user->firstname, $user->lastname)." <".$user->email.">";
		} else {
			$sendto = $conf->global->MAIN_INFO_SOCIETE_MAIL.'" <'.$conf->global->MAIN_INFO_SOCIETE_MAIL.'>';
		}
		$replyto = $sendto;
		$sendtocc = '';
		if (!empty($conf->global->ONLINE_PAYMENT_SENDEMAIL)) {
			$sendtocc = $conf->global->ONLINE_PAYMENT_SENDEMAIL.'" <'.$conf->global->ONLINE_PAYMENT_SENDEMAIL.'>';
		}

		$message = "A bank transfer of ".price2num($event->data->object->amount / 100)." ".$event->data->object->currency." has been done to your account the ".dol_print_date($event->data->object->arrival_date, 'dayhour');

		$mailfile = new CMailFile(
			$subject,
			$sendto,
			$replyto,
			$message,
			array(),
			array(),
			array(),
			$sendtocc,
			'',
			0,
			-1
		);

		$ret = $mailfile->sendfile();

		return 1;
	} else {
		$error++;
		http_response_code(500);
		return -1;
	}
} elseif ($event->type == 'customer.source.created') {
	//TODO: save customer's source
} elseif ($event->type == 'customer.source.updated') {
	//TODO: update customer's source
} elseif ($event->type == 'customer.source.delete') {
	//TODO: delete customer's source
} elseif ($event->type == 'customer.deleted') {
	$db->begin();
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."societe_account WHERE key_account = '".$db->escape($event->data->object->id)."' and site='stripe'";
	$db->query($sql);
	$db->commit();
} elseif ($event->type == 'payment_intent.succeeded') {		// Called when making payment with PaymentIntent method ($conf->global->STRIPE_USE_NEW_CHECKOUT is on).
	dol_syslog("object = ".var_export($event->data, true));

	/* TODO LMR We must retreive the invoice and payment amount from the id = ext_payment_id into llx_prelevement_demande
	include_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
	$object = $event->data->object;
	$invoice_id = $object->metadata->dol_id;
	$ipaddress = $object->metadata->ipaddress;
	$invoice_amount = $object->amount / 100;
	$TRANSACTIONID = $object->id;
	$customer_id = $object->customer;
	$errorforinvoice = 0;
	$paymentmethod = 'stripe';
	$now = dol_now();
	$currencyCodeType = strtoupper($object->currency);
	$paymentmethodstripeid = $object->payment_method;

	global $stripearrayofkeysbyenv;
	$stripeacc = $stripearrayofkeysbyenv[$servicestatus]['secret_key'];

	dol_syslog("Try to create payment with data = ".json_encode($dataforcard));

	$s = new \Stripe\StripeClient($stripeacc);

	$paymentmethodstripe = $s->paymentMethods->retrieve($paymentmethodstripeid);

	$paymentTypeId =  $paymentmethodstripe->type;
	if ($paymentTypeId == "ban") {
		$paymentTypeId = "PRE"
	} elseif ($paymentTypeId == "sepa_debit") {	// is this used ? how ?
		$paymentTypeId = "BANCON";
	} elseif ($paymentTypeId == "card") {
		$paymentTypeId = "CB";
	}

	// TODO LMR Enable this only if this is a payment of a Dolibarr llx_prelevement_demande only

	$paiement = new Paiement($db);
	$paiement->datepaye = $now;
	$paiement->date = $now;
	if ($currencyCodeType == $conf->currency) {
		$paiement->amounts = [$invoice_id => $invoice_amount];   // Array with all payments dispatching with invoice id
	} else {
		$paiement->multicurrency_amounts = [$invoice_id => $invoice_amount];   // Array with all payments dispatching

		$postactionmessages[] = 'Payment was done in a different currency than currency expected of company';
		$ispostactionok = -1;
		// Not yet supported, so error
		$error++;
		$errorforinvoice++;
	}
	$paiement->paiementid = $paymentTypeId;
	$paiement->num_payment = '';
	$paiement->note_public = '';
	$paiement->note_private = 'StripeSepa payment ' . dol_print_date($now, 'standard') . ' using ' . $paymentmethod . ($ipaddress ? ' from ip ' . $ipaddress : '') . ' - Transaction ID = ' . $TRANSACTIONID;
	// TODO LMR Fill the  $paiement->ext_payment_id with an ID of payment intent (so 'pi_....'). Like this:
	$paiement->ext_payment_id = $TRANSACTIONID.':'.$customer_id.'@'.$stripearrayofkeysbyenv[$servicestatus]['publishable_key'];		// May be we should store py_... instead of pi_... but we started with pi_... so we continue.
	$paiement->ext_payment_site = $service;						// 'StripeLive' or 'Stripe' if test


	$db->begin();


	if (!$errorforinvoice) {
		dol_syslog('* Record payment for invoice id ' . $invoice_id . '. It includes closing of invoice and regenerating document');

		// This include closing invoices to 'paid' (and trigger including unsuspending) and regenerating document
		$paiement_id = $paiement->create($user, 1);
		if ($paiement_id < 0) {
			$postactionmessages[] = $paiement->error . ($paiement->error ? ' ' : '') . join("<br>\n", $paiement->errors);
			$ispostactionok = -1;
			$error++;
			$errorforinvoice++;
		} else {
			$postactionmessages[] = 'Payment created';
		}

		dol_syslog("The payment has been created for invoice id " . $invoice_id);
	}

	if (!$errorforinvoice && isModEnabled('banque')) {
		dol_syslog('* Add payment to bank');

		// The bank used is the one defined into Stripe setup
		$bankaccountid = 0;
		if ($paymentmethod == 'stripe') {
			$bankaccountid = $conf->global->STRIPE_BANK_ACCOUNT_FOR_PAYMENTS;
		}

		if ($bankaccountid > 0) {
			$label = '(CustomerInvoicePayment)';
			$result = $paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, $customer_id, '');
			if ($result < 0) {
				$postactionmessages[] = $paiement->error . ($paiement->error ? ' ' : '') . join("<br>\n", $paiement->errors);
				$ispostactionok = -1;
				$error++;
				$errorforinvoice++;
			} else {
				$postactionmessages[] = 'Bank transaction of payment created (by makeStripeSepaRequest)';
			}
		} else {
			$postactionmessages[] = 'Setup of bank account to use in module ' . $paymentmethod . ' was not set. No way to record the payment.';
			$ispostactionok = -1;
			$error++;
			$errorforinvoice++;
		}
	}

	if (!$errorforinvoice && isModEnabled('prelevement')) {
		$bon = new BonPrelevement($db);
		$idbon = 0;
		$sql = "SELECT dp.fk_prelevement_bons as idbon";
		$sql .= " FROM ".MAIN_DB_PREFIX."prelevement_demande as dp";
		$sql .= " WHERE dp.fk_facture = '".$db->escape($invoice_id)."'";
		$sql .= " AND dp.sourcetype = 'facture'";
		$sql .= " AND dp.traite = 1";
		$result = $db->query($sql);
		if ($result) {
			if ($db->num_rows($result)) {
				$obj = $db->fetch_object($result);
				$idbon = $obj->idbon;
			}
		} else {
			$postactionmessages[] = $db->lasterror();
			$ispostactionok = -1;
			$error++;
			$errorforinvoice++;
		}

		if (!empty($idbon)) {
			$bon->fetch($idbon);
			$sql = "UPDATE ".MAIN_DB_PREFIX."prelevement_bons";
			$sql .= " SET fk_user_credit = ".$user->id;
			$sql .= ", statut = '".$db->escape($bon::STATUS_CREDITED)."'";
			$sql .= ", date_credit = '".$db->idate($now)."'";
			$sql .= ", credite = 1";
			$sql .= " WHERE rowid=".((int) $bon->id);
			$sql .= " AND statut = '".$db->escape($bon::STATUS_TRANSFERED)."'";

			$db->begin();
			$result = $db->query($sql);
			if (!$result) {
				$db->rollback();
				$postactionmessages[] = $db->lasterror();
				$ispostactionok = -1;
				$error++;
				$errorforinvoice++;
			} else {
				$db->commit();
			}
		}
	}
	*/
} elseif ($event->type == 'payment_intent.payment_failed') {
	// TODO: Redirect to paymentko.php
} elseif ($event->type == 'checkout.session.completed') {		// Called when making payment with new Checkout method ($conf->global->STRIPE_USE_NEW_CHECKOUT is on).
	// TODO: create fees
	// TODO: Redirect to paymentok.php
} elseif ($event->type == 'payment_method.attached') {
	require_once DOL_DOCUMENT_ROOT.'/societe/class/companypaymentmode.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societeaccount.class.php';
	$societeaccount = new SocieteAccount($db);

	$companypaymentmode = new CompanyPaymentMode($db);

	$idthirdparty = $societeaccount->getThirdPartyID($db->escape($event->data->object->customer), 'stripe', $servicestatus);
	if ($idthirdparty > 0) {
		// If the payment mode attached is to a stripe account owned by an external customer in societe_account (so a thirdparty that has a Stripe account),
		// we can create the payment mode
		$companypaymentmode->stripe_card_ref = $db->escape($event->data->object->id);
		$companypaymentmode->fk_soc          = $idthirdparty;
		$companypaymentmode->bank            = null;
		$companypaymentmode->label           = null;
		$companypaymentmode->number          = $db->escape($event->data->object->id);
		$companypaymentmode->last_four       = $db->escape($event->data->object->card->last4);
		$companypaymentmode->card_type       = $db->escape($event->data->object->card->branding);
		$companypaymentmode->proprio         = $db->escape($event->data->object->billing_details->name);
		$companypaymentmode->exp_date_month  = $db->escape($event->data->object->card->exp_month);
		$companypaymentmode->exp_date_year   = $db->escape($event->data->object->card->exp_year);
		$companypaymentmode->cvn             = null;
		$companypaymentmode->datec           = $db->escape($event->data->object->created);
		$companypaymentmode->default_rib     = 0;
		$companypaymentmode->type            = $db->escape($event->data->object->type);
		$companypaymentmode->country_code    = $db->escape($event->data->object->card->country);
		$companypaymentmode->status          = $servicestatus;

		// TODO Check that a payment mode $companypaymentmode->stripe_card_ref does not exists yet to avoid to create duplicates
		// so we can remove the test on STRIPE_NO_DUPLICATE_CHECK
		if (getDolGlobalString('STRIPE_NO_DUPLICATE_CHECK')) {
			$db->begin();
			$result = $companypaymentmode->create($user);
			if ($result < 0) {
				$error++;
			}
			if (!$error) {
				$db->commit();
			} else {
				$db->rollback();
			}
		}
	}
} elseif ($event->type == 'payment_method.updated') {
	require_once DOL_DOCUMENT_ROOT.'/societe/class/companypaymentmode.class.php';
	$companypaymentmode = new CompanyPaymentMode($db);
	$companypaymentmode->fetch(0, '', 0, '', " AND stripe_card_ref = '".$db->escape($event->data->object->id)."'");
	if ($companypaymentmode->id > 0) {
		// If we found a payment mode with the ID
		$companypaymentmode->bank            = null;
		$companypaymentmode->label           = null;
		$companypaymentmode->number          = $db->escape($event->data->object->id);
		$companypaymentmode->last_four       = $db->escape($event->data->object->card->last4);
		$companypaymentmode->proprio         = $db->escape($event->data->object->billing_details->name);
		$companypaymentmode->exp_date_month  = $db->escape($event->data->object->card->exp_month);
		$companypaymentmode->exp_date_year   = $db->escape($event->data->object->card->exp_year);
		$companypaymentmode->cvn             = null;
		$companypaymentmode->datec           = $db->escape($event->data->object->created);
		$companypaymentmode->default_rib     = 0;
		$companypaymentmode->type            = $db->escape($event->data->object->type);
		$companypaymentmode->country_code    = $db->escape($event->data->object->card->country);
		$companypaymentmode->status          = $servicestatus;

		$db->begin();
		if (!$error) {
			$result = $companypaymentmode->update($user);
			if ($result < 0) {
				$error++;
			}
		}
		if (!$error) {
			$db->commit();
		} else {
			$db->rollback();
		}
	}
} elseif ($event->type == 'payment_method.detached') {
	$db->begin();
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."societe_rib WHERE number = '".$db->escape($event->data->object->id)."' and status = ".((int) $servicestatus);
	$db->query($sql);
	$db->commit();
} elseif ($event->type == 'charge.succeeded') {
	// TODO: create fees
	// TODO: Redirect to paymentok.php
} elseif ($event->type == 'charge.failed') {
	// TODO: Redirect to paymentko.php
} elseif (($event->type == 'source.chargeable') && ($event->data->object->type == 'three_d_secure') && ($event->data->object->three_d_secure->authenticated == true)) {
	// This event is deprecated.
}

// End of page. Default return HTTP code will be 200
