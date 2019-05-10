<?php
/**
 * This extension allows CiviCRM to send emails and process bounces through
 * the SparkPost service.
 *
 * Copyright (c) 2016 IT Bliss, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * Support: https://github.com/cividesk/com.cividesk.email.sparkpost/issues
 * Contact: info@cividesk.com
 */

require_once 'sparkpost.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function sparkpost_civicrm_config(&$config) {
  _sparkpost_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function sparkpost_civicrm_xmlMenu(&$files) {
  _sparkpost_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function sparkpost_civicrm_install() {
  _sparkpost_civix_civicrm_install();
  // Check dependencies and display error messages
  sparkpost_check_dependencies();
  $mailingParams = array(
    'subject' => '***All Transactional Emails Through Sparkpost***',
    'name' => ts('Transaction Emails Sparkpost'),
    'url_tracking' => TRUE,
    'forward_replies' => FALSE,
    'auto_responder' => FALSE,
    'open_tracking' => TRUE,
    'is_completed' => FALSE,
  );

  // Create entry in civicrm_mailing
  $mailing = CRM_Mailing_BAO_Mailing::add($mailingParams, CRM_Core_DAO::$_nullArray);

  // FIXME: what is this for?
  $changeENUM = FALSE;
  if (version_compare('4.5alpha1', $civiVersion) > 0) {
    $changeENUM = TRUE;
  }
  CRM_Core_Smarty::singleton()->assign('changeENUM', $changeENUM);

  // Add entry in civicrm_mailing_job
  $saveJob = new CRM_Mailing_DAO_MailingJob();
  $saveJob->start_date = $saveJob->end_date = date('YmdHis');
  $saveJob->status = 'Complete';
  $saveJob->job_type = "Special: All transactional emails being sent through SparkPost";
  $saveJob->mailing_id = $mailing->id;
  $saveJob->save();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function sparkpost_civicrm_uninstall() {
  return _sparkpost_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function sparkpost_civicrm_enable() {
  return _sparkpost_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function sparkpost_civicrm_disable() {
  return _sparkpost_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function sparkpost_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sparkpost_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function sparkpost_civicrm_managed(&$entities) {
  return _sparkpost_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_navigationMenu
 *
 * Locate the 'Outbound Email' navigation menu (recursive)
 * Replace the menu label and destination url with our own form
 */
function sparkpost_civicrm_navigationMenu( &$param ) {
  foreach ($param as &$menu) {
    if (CRM_Utils_Array::value('attributes', $menu) &&
      CRM_Utils_Array::value('name', $menu['attributes']) == 'Outbound Email'
    ) {
      $menu['attributes']['url'] = 'civicrm/admin/setting/sparkpost';
      $menu['attributes']['label'] = ts('Outbound Email (SparkPost)');
    }
    if (CRM_Utils_Array::value('child', $menu)) {
      sparkpost_civicrm_navigationMenu($menu['child']);
    }
  }
}

/**
 * Implementation of hook_civicrm_alterContent
 *
 * Replace the link to Outbound Email Settings in the administration console
 */
function sparkpost_civicrm_alterContent( &$content, $context, $tplName, &$object ) {
  if ($tplName == 'CRM/Admin/Page/Admin.tpl') {
    $content = str_replace('civicrm/admin/setting/smtp', 'civicrm/admin/setting/sparkpost', $content);
  }
}

/**
 * Implementation of hook_civicrm_alterMailer
 */
function sparkpost_civicrm_alterMailer(&$mailer, $driver, $params) {
  require_once 'Mail/sparkpost.php';
  $sparkpost = new Mail_sparkpost($params);
  // $sparkpost->setBackupMailer($mailer);
  $mailer = $sparkpost;
}

/**
 * Implementation of hook_civicrm_check (4.6.3+)
 */
function sparkpost_civicrm_check(&$messages) {
  $sparkpost_messages = sparkpost_check_dependencies(FALSE);

  // We need to add the severity (only for 4.7+)
  $version = CRM_Utils_System::version();

  if (version_compare($version, '4.7') >= 0) {
    foreach ($sparkpost_messages as &$message) {
      $message->setLevel(5); // Cannot use \Psr\Log\LogLevel::CRITICAL as this is not in 4.6 code base
    }
  }

  $messages += $sparkpost_messages;
}

/**
 * Checks all dependencies for the extension
 *
 * @returns array  Array with one CRM_Utils_Check_Message object for each unmet dependency
 */
function sparkpost_check_dependencies($display = TRUE) {
  // Note: CRM_Utils_Check_Message exists in 4.4+, but does not include severity attribute
  $messages = array();
  $trailer = ts('This requirement is not met in your current configuration, the extension will not work.');
  // Make sure the PHP version is 5.4+
  if (version_compare(PHP_VERSION, '5.4') < 0) {
    $messages[] = new CRM_Utils_Check_Message(
      'sparkpost_phpversion',
      ts('The SparkPost extension requires PHP version 5.4 or higher.') . ' ' . $trailer,
      ts('SparkPost requirements not met')
    );
  }
  // Make sure the curl extension is enabled
  if (!function_exists('curl_version')) {
    $messages[] = new CRM_Utils_Check_Message(
      'sparkpost_curlextension',
      ts('The SparkPost extension requires the PHP curl library.') . ' ' . $trailer,
      ts('SparkPost requirements not met')
    );
  }
  // Now display a nice alert for all these messages
  if ($display) {
    foreach ($messages as $message) {
      CRM_Core_Session::setStatus($message->getMessage(), $message->getTitle(), 'error');
    }
  }
  return $messages;
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function sparkpost_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _sparkpost_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

function sparkpost_civicrm_alterMailParams(&$params, $context = NULL) {
  // Create meta data for transactional email
  if ($context != 'civimail') {
    $mail = new CRM_Mailing_DAO_Mailing();
    $mail->subject = "***All Transactional Emails Through SparkPost***";
    $mail->url_tracking = TRUE;
    $mail->forward_replies = FALSE;
    $mail->auto_responder = FALSE;
    $mail->open_tracking = TRUE;

    if ($mail->find(TRUE)) {
      if (isset($params['contact_id']) && !empty($params['contact_id'])) {//We could bring contact_id in params by customizing activity bao file
        $contactId = CRM_Utils_Array::value('contact_id', $params);
      } else if (isset($params['contactId']) && !empty($params['contactId'])) {//Contribution/Event confirmation
        $contactId = CRM_Utils_Array::value('contactId', $params);
      } else {//As last option from emall address
        $contactId = sparkpost_targetContactId($params);
      }
      
      if (!$contactId) {
        CRM_Core_Error::backtrace('backtrace before return in sparkpost alterMailParams', TRUE);
        CRM_Core_Error::debug_var('Contact Id not known to attach header for this transactional email by Sparkpost extension possbile duplicates email hence skiping', $params);
        return;
      }
      
      if ($contactId) {
        $eventQueueParams = array(
          'job_id' => CRM_Core_DAO::getFieldValue('CRM_Mailing_DAO_MailingJob', $mail->id, 'id', 'mailing_id'),
          'contact_id' => $contactId,
          'email_id' => CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $contactId, 'id', 'contact_id'),
        );
        $eventQueue = CRM_Mailing_Event_BAO_Queue::create($eventQueueParams);
        $params['returnPath'] = implode(CRM_Core_Config::singleton()->verpSeparator, array('m', $eventQueueParams['job_id'], $eventQueue->id, $eventQueue->hash));//Add m to differentiate from bulk mailing
      }
    }
  }
}

/**
 * Implementation of hook_civicrm_postEmailSend
 */
function sparkpost_civicrm_postEmailSend(&$params) {
  if (!empty($params['returnPath'])) {
    $header = explode(CRM_Core_Config::singleton()->verpSeparator, $params['returnPath']);

    // Do not use $params, it would cause problems to other hooks
    $p = array(
      'job_id' => $header[1],
      'event_queue_id' => $header[2],
      'hash' => $header[3],
    );
    CRM_Mailing_Event_BAO_Delivered::create($p);
  }
}

/*
 *
 * Function to get contact id from email
 *
 * @param array $params - array of mail params
 *
 * return contact Id
 */
function sparkpost_targetContactId($params) {
  $emailParams = array( 
    'email' => trim($params['toEmail']),
    'version' => 3,
    'sequential' => 1,
  );
  $result = civicrm_api('email', 'get', $emailParams);
  if (!$result['is_error'] && $result['count'] == 1) {
    $contactId = $result['values'][0]['contact_id'];
  } 
  return $contactId;
}
