<?php

namespace Src\Controller;

use Src\Gateway\OrchardPaymentGateway;
use Src\Controller\VoucherPurchase;

class PaymentController
{
    private $voucher;

    public function __construct()
    {
        $this->voucher = new VoucherPurchase();
    }

    public function vendorPaymentProcess($data)
    {
        if (!empty($data)) {
            $trans_id = time();
            if ($trans_id) return $this->voucher->SaveFormPurchaseData($data, $trans_id);
            else
                return array("success" => false, "message" => "Transaction ID failed!");
        }
    }

    public function verifyPurchaseStatus(int $transaction_id)
    {
        // Fetch transaction ID AND STATUS from DB
        $data = $this->voucher->getTransactionStatusFromDB($transaction_id);

        if (empty($data)) return array("success" => false, "message" => "Invalid transaction ID passed for processing!");
        if (strtoupper($data[0]["status"]) == "FAILED") return array("success" => false, "message" => "Sorry, your transaction failed!");
        if (strtoupper($data[0]["status"]) == "PENDING") return array("success" => false, "message" => "Sorry, transaction is currently pending! This might be due to insufficient fund in your mobile wallet or your payment session expired.");
        if (strtoupper($data[0]["status"]) == "COMPLETED") return array("success" => true, "message" => "Transaction completed! Form purchase was successful.");
        return array("success" => false, "message" => "Process failed! Could complete process at this time!");
    }

    public function setOrchardPaymentGatewayParams($payload, $endpointUrl)
    {
        $client_id = getenv('ORCHARD_CLIENT');
        $client_secret = getenv('ORCHARD_SECRET');
        $signature = hash_hmac("sha256", $payload, $client_secret);

        $secretKey = $client_id . ":" . $signature;
        try {
            $pay = new OrchardPaymentGateway($secretKey, $endpointUrl, $payload);
            return $pay->initiatePayment();
        } catch (\Exception $e) {
            throw $e;
            return "Error: " . $e;
        }
    }

    /**
     * @param int $transaction_id
     * @return mixed
     */
    private function getTransactionStatusFromOrchard(int $transaction_id)
    {
        $payload = json_encode(array(
            "exttrid" => $transaction_id,
            "trans_type" => "TSC",
            "service_id" => getenv('ORCHARD_SERVID')
        ));
        $endpointUrl = "https://orchard-api.anmgw.com/checkTransaction";
        return $this->setOrchardPaymentGatewayParams($payload, $endpointUrl);
    }

    public function processTransaction(int $transaction_id)
    {
        $data = $this->voucher->getTransactionStatusFromDB($transaction_id);

        if (empty($data)) return array("success" => false, "message" => "Invalid transaction ID! Code: -1");

        if (strtoupper($data[0]["status"]) == "FAILED") return array("success" => false, "message" => "Sorry, your transaction failed!");
        if (strtoupper($data[0]["status"]) == "COMPLETED") return array("success" => true, "message" => "Transaction already performed! Check mail and/or SMS inbox for login details. Code: 1");

        $response = json_decode($this->getTransactionStatusFromOrchard($transaction_id));

        if (empty($response)) return array("success" => false, "message" => "Invalid transaction Parameters! Code: -2");

        if (isset($response->trans_status)) {
            $status_code = substr($response->trans_status, 0, 3);
            if ($status_code == '000') return $this->voucher->genLoginsAndSend($transaction_id);
            $this->voucher->updateTransactionStatusInDB('FAILED', $transaction_id);
            return array("success" => false, "message" => "Payment failed! Code: " . $status_code);
        } elseif (isset($response->resp_code)) {
            if ($response->resp_code == '084') return array(
                "success" => false,
                "message" => "Payment pending! This might be due to insufficient fund in your mobile wallet or your payment session expired. Code: " . $response->resp_code
            );
            return array("success" => false, "message" => "Payment process failed! Code: " . $response->resp_code);
        }
        return array("success" => false, "message" => "Bad request: Payment process failed!");
    }

    public function orchardPaymentController($data)
    {
        if (empty($data)) return array("success" => false, "message" => "Process failed! Invalid data received.");

        $trans_id = time();
        $payload = json_encode(array(
            "amount" => $data["amount"],
            "callback_url" => "https://api.rmuictonline.com/confirm.php",
            "exttrid" => $trans_id,
            "reference" => "RMU Forms Online",
            "service_id" => getenv('ORCHARD_SERVID'),
            "trans_type" => "CTM",
            "nickname" => "RMU",
            "landing_page" => "https://api.rmuictonline.com/step-final.php",
            "ts" => date("Y-m-d H:i:s"),
            "payment_mode" => $data["pay_method"],
            "currency_code" => "GHS",
            "currency_val" => $data["amount"]
        ));

        $endpointUrl = "https://payments.anmgw.com/third_party_request";
        $response = json_decode($this->setOrchardPaymentGatewayParams($payload, $endpointUrl));

        if ($response->resp_code == "000" && $response->resp_desc == "Passed") {
            //save Data to database
            $saved = $this->voucher->SaveFormPurchaseData($data, $trans_id);
            if (!$saved["success"]) return $saved;
            return array("success" => true, "status" => $response->resp_code, "message" => $response->redirect_url);
        }
        //echo $response->resp_desc;
        return array("success" => false, "status" => $response->resp_code, "message" => $response->resp_desc);
    }
}
