<?php

namespace Src\Controller;

use DateTime;
use Src\System\DatabaseMethods;
use Src\Controller\PaymentController;
use Src\Gateway\CurlGatewayAccess;

class ExposeDataController
{
    private $dm;

    public function __construct()
    {
        $this->dm = new DatabaseMethods();
    }

    public function genCode($length = 6)
    {
        $digits = $length;
        $first = pow(10, $digits - 1);
        $second = pow(10, $digits) - 1;
        return rand($first, $second);
    }

    public function validateEmail($input)
    {
        if (empty($input)) return false;
        $user_email = htmlentities(htmlspecialchars($input));
        $sanitized_email = filter_var($user_email, FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitized_email, FILTER_VALIDATE_EMAIL)) return false;
        return $user_email;
    }

    public function validateInput($input)
    {
        if (empty($input)) return false;
        $user_input = htmlentities(htmlspecialchars($input));
        $validated_input = (bool) preg_match('/^[A-Za-z0-9]/', $user_input);
        if ($validated_input) return $user_input;
        return false;
    }

    public function validateName($input): bool
    {
        if (empty($input)) return false;
        $user_input = htmlentities(htmlspecialchars($input));
        $validated_input = (bool) preg_match("/^[\p{L}'\s.-]+$/u", $user_input);
        if ($validated_input) return true;
        return false;
    }

    public function validateCountryCode($input)
    {
        if (empty($input)) return false;
        $user_input = htmlentities(htmlspecialchars($input));
        $validated_input = (bool) preg_match('/^[A-Za-z0-9()+]/', $user_input);
        if ($validated_input) return $user_input;
        return false;
    }

    public function validatePassword($input)
    {
        if (empty($input)) return false;
        $user_input = htmlentities(htmlspecialchars($input));
        $validated_input = (bool) preg_match('/^[A-Za-z0-9()+@#.-_=$&!`]/', $user_input);
        if ($validated_input) return $user_input;
        return false;
    }

    public function validatePhone($input): bool
    {
        if (empty($input)) return false;
        $user_input = htmlentities(htmlspecialchars($input));
        $validated_input = (bool) preg_match('/^[0-9]/', $user_input);
        if ($validated_input) return true;
        return false;
    }

    public function validateText($input)
    {
        if (empty($input)) return false;
        $user_input = htmlentities(htmlspecialchars($input));
        $validated_input = (bool) preg_match('/^[A-Za-z]+$/', $user_input);
        if ($validated_input) return $user_input;
        return false;
    }

    public function validateDate($date)
    {
        if (strtotime($date) === false) die("Invalid date!");
        list($year, $month, $day) = explode('-', $date);
        if (checkdate($month, $day, $year)) return $date;
    }

    public function validateDateTime($input, $format = 'Y-m-d H:i:s')
    {
        if (empty($input)) return false;
        $d = DateTime::createFromFormat($format, $input);
        return $d && $d->format($format) == $input;
    }

    public function getCurrentAdmissionPeriodID()
    {
        return $this->dm->getData("SELECT `id` FROM `admission_period` WHERE `active` = 1");
    }

    public function getIPAddress()
    {
        //whether ip is from the share internet  
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        //whether ip is from the proxy  
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        //whether ip is from the remote address  
        else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }

    public function getDeciveInfo()
    {
        return $_SERVER['HTTP_USER_AGENT'];
    }

    public function getFormPriceA(int $form_id)
    {
        return $this->dm->getData("SELECT * FROM `forms` WHERE `id` = :fi", array(":fi" => $form_id));
    }

    public function getFormDetailsByFormName($form_name)
    {
        return $this->dm->getData("SELECT * FROM `forms` WHERE `name` = :fn", array(":fn" => $form_name));
    }

    public function getAdminYearCode()
    {
        $sql = "SELECT EXTRACT(YEAR FROM (SELECT `start_date` FROM admission_period WHERE active = 1)) AS 'year'";
        $year = (string) $this->dm->getData($sql)[0]['year'];
        return (int) substr($year, 2, 2);
    }

    public function getAvailableForms()
    {
        return $this->dm->getData("SELECT * FROM `forms`");
    }

    public function getUndergradAndPostgradForms()
    {
        return $this->dm->getData("SELECT f.* FROM `forms` AS f, `form_categories` AS fc 
        WHERE f.form_category = fc.id AND fc.name IN ('UNDERGRADUATE', 'POSTGRADUATE')");
    }

    public function getOtherForms()
    {
        return $this->dm->getData("SELECT f.* FROM `forms` AS f, `form_categories` AS fc 
        WHERE f.form_category = fc.id AND fc.name NOT IN ('UNDERGRADUATE', 'POSTGRADUATE')");
    }

    public function sendHubtelSMS($url, $payload)
    {
        $client = getenv('HUBTEL_CLIENT');
        $secret = getenv('HUBTEL_SECRET');
        $secret_key = base64_encode($client . ":" . $secret);

        $httpHeader = array("Authorization: Basic " . $secret_key, "Content-Type: application/json");
        $gateAccess = new CurlGatewayAccess($url, $httpHeader, $payload);
        return $gateAccess->initiateProcess();
    }

    public function sendSMS($to, $message)
    {
        $url = "https://sms.hubtel.com/v1/messages/send";
        $payload = json_encode(array("From" => "RMU", "To" => $to, "Content" => $message));
        return $this->sendHubtelSMS($url, $payload);
    }

    public function getVendorPhone($vendor_id)
    {
        $sql = "SELECT `country_code`, `phone_number` FROM `vendor_details` WHERE `id`=:i";
        return $this->dm->getData($sql, array(':i' => $vendor_id));
    }

    public function vendorExist($vendor_id)
    {
        $str = "SELECT `id` FROM `vendor_details` WHERE `id`=:i";
        return $this->dm->getID($str, array(':i' => $vendor_id));
    }

    //
    public function activityLogger($request, $route, $api_user)
    {
        $query = "INSERT INTO `api_request_logs` (`request`, `route`, `api_user`) VALUES(:r, :t, :u)";
        $params = array(":r" => $request, ":t" => $route, ":u" => $api_user);
        return $this->dm->inputData($query, $params);
    }

    public function verifyAPIAccess($username, $password): int
    {
        $sql = "SELECT * FROM `api_users` WHERE `username`=:u";
        $data = $this->dm->getData($sql, array(':u' => $username));
        if (!empty($data)) if (password_verify($password, $data[0]["password"])) return (int) $data[0]["id"];
        return 0;
    }

    public function getAllAvaialbleForms()
    {
        return $this->dm->getData("SELECT `name` AS form, `amount` AS price FROM `forms`");
    }

    public function getPurchaseStatusByExtransID($externalTransID)
    {
        $query = "SELECT `status`, `ext_trans_id`, `ext_trans_datetime` AS trans_dt 
                FROM `purchase_detail` WHERE `ext_trans_id` = :t";
        return $this->dm->getData($query, array(':t' => $externalTransID));
    }

    public function getPurchaseInfoByExtransID($externalTransID)
    {
        $query = "SELECT CONCAT('RMU-', `app_number`) AS app_number, `pin_number`, 
                    `ext_trans_id`, `phone_number`, `ext_trans_datetime` AS trans_dt
                FROM `purchase_detail` WHERE `ext_trans_id` = :t";
        return $this->dm->getData($query, array(':t' => $externalTransID));
    }

    public function getVendorIdByAPIUser($api_user): mixed
    {
        $query = "SELECT `vendor_id` FROM `api_users` WHERE `id` = :a";
        return $this->dm->getData($query, array(':a' => $api_user));
    }

    public function verifyExternalTransID($externalTransID, $api_user)
    {
        $query = "SELECT pd.`id` FROM `purchase_detail` AS pd, api_users AS au 
        WHERE pd.`ext_trans_id` = :t AND au.`id` = :a AND pd.`vendor` = au.`vendor_id`";
        return $this->dm->getID($query, array(':t' => $externalTransID, ':a' => $api_user));
    }

    public function fetchCompanyIDByCode($companyCode, $apiUser): mixed
    {
        $query = "SELECT vd.`id` FROM vendor_details AS vd, api_users AS au 
                WHERE vd.`company_code` = :c AND au.id = :a AND branch = 'MAIN' AND au.vendor_id = vd.id";
        return $this->dm->getID($query, array(":c" => $companyCode, ":a" => $apiUser));
    }
}
