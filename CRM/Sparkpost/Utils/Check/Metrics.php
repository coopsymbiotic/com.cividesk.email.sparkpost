<?php

use SparkPost\SparkPost;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

class CRM_Sparkpost_Utils_Check_Metrics {

  /**
   * Displays the available sending domains.
   */
  public static function check(&$messages) {
    // TODO: Refactor into a more generic function?
    require_once __DIR__ . '/../../../../vendor/autoload.php';
    $api_key = CRM_Sparkpost::getSetting('sparkpost_apiKey');
    $api_host = Civi::settings()->get('sparkpost_host');
    $httpClient = new GuzzleAdapter(new Client());
    $sparky = new SparkPost($httpClient, ['key' => $api_key, 'async' => FALSE, 'host' => "api.$api_host"]);

    // FIXME Add cycle roll day setting
    // If it's before the 27th, then we search Y-[m-1]-27
    // otherwise we check Y-m-27.
    $date = new DateTime();
    $date->setDate($date->format('Y'), $date->format('m'), 27);

    $current_day = date('d');

    if ($current_day <= 27) {
      $date->setDate($date->format('Y'), $date->format('m') - 1, 27);
    }

    // Metrics by Sending Domain
    try {
      $response = $sparky->request('GET', 'metrics/deliverability/sending-domain', [
        'from' => $date->format('Y-m-d') . 'T00:01',
        'metrics' => 'count_sent,count_bounce,count_rejected,count_admin_bounce,count_rejected,count_spam_complaint',
        'order_by' => 'count_sent',
        // TODO This is mostly relevant for the master account, which sees all domains
        // For now, we find it convenient to see stats for all domains.
        // 'limit' => 20,
      ]);

      $body = $response->getBody();

      $output = '';

      // https://www.sparkpost.com/docs/reporting/metrics-definitions/
      $metrics = [
        'sending_domain' => [
          'label' => ts('Domain:'),
        ],
        'count_sent' => [
          'quota_total' => 65000, // FIXME
          'label' => ts('Messages Sent:'),
        ],
        'count_bounce' => [
          'quota_pct' => 10,
          'label' => ts('Bounces:'),
        ],
        'count_admin_bounce' => [
          'quota_pct' => 10,
          'label' => ts('Bounced by Sparkpost:'),
        ],
        'count_rejected' => [
          'quota_pct' => 5,
          'label' => ts('Rejected by Sparkpost:'),
        ],
        'count_spam_complaint' => [
          'quota_pct' => 5,
          'label' => ts('Spam Complaints:'),
        ],
      ];

      $log_level = \Psr\Log\LogLevel::INFO;

      foreach ($body['results'] as $key => $val) {
        $stats = [];

        foreach ($metrics as $mkey => $mval) {
          if (isset($mval['quota_total']) && $val[$mkey] > $mval['quota_total']) {
            $pct = round($val[$mkey] / $mval['quota_total'] * 100, 2);
            $stats[] = "<li><strong>{$mval['label']} {$val[$mkey]} (max: {$mval['quota_total']}, $pct %)</strong></li>"; // FIXME ts
            $log_level = \Psr\Log\LogLevel::CRITICAL;
          }
          elseif (isset($mval['quota_pct'])) {
            $pct_val = round($val[$mkey] / $val['count_sent'] * 100, 2);

            // For most metrics, under 50 complaints/bounces, there is too much of a high noize ratio.
            if ($val[$mkey] > 50 && $pct_val > $mval['quota_pct']) {
              $stats[] = "<li><strong>{$mval['label']} {$val[$mkey]} ({$pct_val}%)</strong></li>";
              $log_level = \Psr\Log\LogLevel::CRITICAL;
            }
            else {
              $stats[] = "<li>{$mval['label']} {$val[$mkey]} ({$pct_val}%)</li>";
            }
          }
          else {
            $stats[] = "<li>" . $mval['label'] . ' ' . $val[$mkey] . '</li>';
          }
        }

        $output .= '<ul>' . implode('', $stats) . '</ul>';
      }

      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_metrics',
        ts('Emails sent from %1 to today: %2', [1 => $date->format('Y-m-d'), 2 => $output]), // FIXME E::ts
        ts('SparkPost - Metrics'),
        $log_level,
        'fa-envelope'
      );
    }
    catch (Exception $e) {
      $code = $e->getCode();
      $body = $e->getBody();

      $messages[] = new CRM_Utils_Check_Message(
        'sparkpost_metrics',
        ts('Metrics: ERROR: %1, %2', [1 => $e->getCode(), 2 => print_r($body, 1)]),
        ts('SparkPost - Metrics'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-envelope'
      );
    }
  }

}
