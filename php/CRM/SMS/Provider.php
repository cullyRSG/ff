<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.2                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2012                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2012
 * $Id$
 *
 */

abstract class CRM_SMS_Provider {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = array();
  CONST MAX_SMS_CHAR = 460;

  /**
   * singleton function used to manage this object
   *
   * @return object
   * @static
   *
   */
  static function &singleton($providerParams = array(
    ), $force = FALSE) {
    $mailingID    = CRM_Utils_Array::value('mailing_id', $providerParams);
    $providerID   = CRM_Utils_Array::value('provider_id', $providerParams);
    
    // make clickatell default provider for now
    $providerName = CRM_Utils_Array::value('provider', $providerParams, 'clickatell');

    if (!$providerID && $mailingID) {
      $providerID = CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_Mailing', $mailingID, 'sms_provider_id', 'id');
      $providerParams['provider_id'] = $providerID;
    }
    if ($providerID) {
      $providerName = CRM_SMS_BAO_Provider::getProviderInfo($providerID, 'name');
    }

    if (!$providerName) {
      CRM_Core_Error::fatal('Provider not known or not provided.');
    }

    $providerName = CRM_Utils_Type::escape($providerName, 'String');
    $providerName = ucfirst($providerName);
    $cacheKey     = "{$providerName}_" . (int) $providerID . "_" . (int) $mailingID;

    if (!isset(self::$_singleton[$cacheKey]) || $force) {
      self::$_singleton[$cacheKey] = eval('return ' . "CRM_SMS_Provider_{$providerName}" . '::singleton( $providerParams, $force );');
    }
    return self::$_singleton[$cacheKey];
  }

  /**
   * Send an SMS Message via the API Server
   *
   * @access public
   */
  abstract function send($recipients, $header, $message, $dncID = NULL);

  /**
   * Function to return message text. Child class could override this function to have better control over the message being sent.
   *
   * @access public
   */
  function getMessage($message, $contactID, $contactDetails) {
    $html = $message->getHTMLBody();
    $text = $message->getTXTBody();

    return $html ? $html : $text;
  }

  function getRecipientDetails($fields, $additionalDetails) {
    // we could do more altering here
    $fields['To'] = $fields['phone'];
    return $fields;
  }

  function createActivity($apiMsgID, $message, $headers = array(
    ), $jobID = NULL, $userID = NULL) {
    if ($jobID) {
      $sql = "
SELECT scheduled_id FROM civicrm_mailing m
INNER JOIN civicrm_mailing_job mj ON mj.mailing_id = m.id AND mj.id = %1";
      $sourceContactID = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($jobID, 'Integer')));
    }
    elseif($userID) {
      $sourceContactID=$userID;  
    }else{
      $session = CRM_Core_Session::singleton();
      $sourceContactID = $session->get('userID');
    }

    $activityTypeID = CRM_Core_OptionGroup::getValue('activity_type', 'SMS delivery', 'name');
    // note: lets not pass status here, assuming status will be updated by callback
    $activityParams = array(
      'source_contact_id' => $sourceContactID,
      'target_contact_id' => $headers['contact_id'],
      'activity_type_id' => $activityTypeID,
      'activity_date_time' => date('YmdHis'),
      'details' => $message,
      'result' => $apiMsgID,
    );
    return CRM_Activity_BAO_Activity::create($activityParams);
  }

  function retrieve($name, $type, $abort = TRUE, $default = NULL, $location = 'REQUEST') {
    static $store = NULL;
    $value = CRM_Utils_Request::retrieve($name, $type, $store,
      FALSE, $default, $location
    );
    if ($abort && $value === NULL) {
      CRM_Core_Error::debug_log_message("Could not find an entry for $name in $location");
      echo "Failure: Missing Parameter<p>";
      exit();
    }
    return $value;
  }

    static function removeCountryCode($number, $code){
        $codeLength=strlen($code);
        if(substr($number, 0, $codeLength) == $code){
            return substr($number, $codeLength);
        } 
        else{
            return $number;
        }
    }

  function inbound($from, $body, $to = NULL, $trackID = NULL) {
    $from = CRM_Utils_Type::escape($from, 'String');

    $default_country_code='44';

    $from = self::removeCountryCode($from, $default_country_code);
    $to = self::removeCountryCode($to, $default_country_code);
    
        
    $fromContactID = CRM_Core_DAO::singleValueQuery('SELECT contact_id FROM civicrm_phone JOIN civicrm_contact ON civicrm_contact.id = civicrm_phone.contact_id WHERE !civicrm_contact.is_deleted AND phone LIKE "%' . $from . '"');
    if ($to) {
        $to = CRM_Utils_Type::escape($to, 'String');
        $toContactID = CRM_Core_DAO::singleValueQuery('SELECT contact_id FROM civicrm_phone JOIN civicrm_contact ON civicrm_contact.id = civicrm_phone.contact_id WHERE !civicrm_contact.is_deleted AND phone LIKE "%' . $to . '"');
    }
    else {
      $toContactID = $fromContactID;
    }

    if ($fromContactID) {
      $actStatusIDs = array_flip(CRM_Core_OptionGroup::values('activity_status'));
      $activityTypeID = CRM_Core_OptionGroup::getValue('activity_type', 'Inbound SMS', 'name');

      // note: lets not pass status here, assuming status will be updated by callback
      $activityParams = array(
        'source_contact_id' => $toContactID,
        'target_contact_id' => $fromContactID,
        'activity_type_id' => $activityTypeID,
        'activity_date_time' => date('YmdHis'),
        'status_id' => $actStatusIDs['Completed'],
        'details' => $body,
      );
      if ($trackID) {
        $trackID = CRM_Utils_Type::escape($trackID, 'String');
        $activityParams['result'] = $trackID;
      }

      $result = CRM_Activity_BAO_Activity::create($activityParams);
      CRM_Core_Error::debug_log_message("Inbound SMS recorded for cid={$contactID}.");
      return $result;
    }
    else {
      // FIXME: should we just create a new contact with just phone no ? civicrm doesn't allow creating contact with just phone number.
      // probably we would need some dummy name / email ?
    }
  }

  function stripPhone($phone) {
    $newphone = preg_replace('/[^0-9x]/', '', $phone);
    while (substr($newphone, 0, 1) == "1") {
      $newphone = substr($newphone, 1);
    }
    while (strpos($newphone, "xx") !== FALSE) {
      $newphone = str_replace("xx", "x", $newphone);
    }
    while (substr($newphone, -1) == "x") {
      $newphone = substr($newphone, 0, -1);
    }
    return $newphone;
  }

  function formatPhone($phone, &$kind, $format = "dash") {
    $phoneA = explode("x", $phone);
    switch (strlen($phoneA[0])) {
      case 0:
        $kind = "XOnly";
        $area = "";
        $exch = "";
        $uniq = "";
        $ext  = $phoneA[1];
        break;

      case 7:
        $kind = $phoneA[1] ? "LocalX" : "Local";
        $area = "";
        $exch = substr($phone, 0, 3);
        $uniq = substr($phone, 3, 4);
        $ext  = $phoneA[1];
        break;

      case 10:
        $kind = $phoneA[1] ? "LongX" : "Long";
        $area = substr($phone, 0, 3);
        $exch = substr($phone, 3, 3);
        $uniq = substr($phone, 6, 4);
        $ext  = $phoneA[1];
        break;

      default:
        $kind = "Unknown";
        return $phone;
    }

    switch ($format) {
      case "like":
        $newphone = '%' . $area . '%' . $exch . '%' . $uniq . '%' . $ext . '%';
        $newphone = str_replace('%%', '%', $newphone);
        $newphone = str_replace('%%', '%', $newphone);
        return $newphone;

      case "dash":
        $newphone = $area . "-" . $exch . "-" . $uniq . " x" . $ext;
        $newphone = trim(trim(trim($newphone, "x"), "-"));
        return $newphone;

      case "bare":
        $newphone = $area . $exch . $uniq . "x" . $ext;
        $newphone = trim(trim(trim($newphone, "x"), "-"));
        return $newphone;

      case "area":
        return $area;

      default:
        return $phone;
    }
  }
}

