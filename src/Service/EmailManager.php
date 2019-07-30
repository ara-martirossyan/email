<?php

namespace Drupal\email\Service;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManager;
use Drupal\Component\Utility\Mail as MailHelper;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Class EmailManager.
 */
class EmailManager extends MailManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, TranslationInterface $string_translation, RendererInterface $renderer) {
    parent::__construct($namespaces, $cache_backend, $module_handler, $config_factory, $logger_factory, $string_translation, $renderer);
  }

  /**
   * $body = $message['body'][0];
   * $message = $system->format($message);
   * $message['body'] = $body;
   *
   * To allow send html message instead of text plain
   *
   * @override
   */
  public function doMail($module, $key, $to, $langcode, $params = [], $reply = NULL, $send = TRUE) {
    $site_config = $this->configFactory->get('system.site');
    $site_mail = $site_config->get('mail');
    if (empty($site_mail)) {
      $site_mail = ini_get('sendmail_from');
    }

    // Bundle up the variables into a structured array for altering.
    $message = [
      'id' => $module . '_' . $key,
      'module' => $module,
      'key' => $key,
      'to' => $to,
      'from' => $site_mail,
      'reply-to' => $reply,
      'langcode' => $langcode,
      'params' => $params,
      'send' => TRUE,
      'subject' => '',
      'body' => [],
    ];

    // Build the default headers.
    $headers = [
      'MIME-Version' => '1.0',
      'Content-Type' => 'text/plain; charset=UTF-8; format=flowed; delsp=yes',
      'Content-Transfer-Encoding' => '8Bit',
      'X-Mailer' => 'Drupal',
    ];
    // To prevent email from looking like spam, the addresses in the Sender and
    // Return-Path headers should have a domain authorized to use the
    // originating SMTP server.
    $headers['Sender'] = $headers['Return-Path'] = $site_mail;
    // Make sure the site-name is a RFC-2822 compliant 'display-name'.
    $headers['From'] = MailHelper::formatDisplayName($site_config->get('name')) . ' <' . $site_mail . '>';
    if ($reply) {
      $headers['Reply-to'] = $reply;
    }
    $message['headers'] = $headers;

    // Build the email (get subject and body, allow additional headers) by
    // invoking hook_mail() on this module. We cannot use
    // moduleHandler()->invoke() as we need to have $message by reference in
    // hook_mail().
    if (function_exists($function = $module . '_mail')) {
      $function($key, $message, $params);
    }

    // Invoke hook_mail_alter() to allow all modules to alter the resulting
    // email.
    $this->moduleHandler->alter('mail', $message);

    // Retrieve the responsible implementation for this message.
    $system = $this->getInstance(['module' => $module, 'key' => $key]);

    // Attempt to convert relative URLs to absolute.
    foreach ($message['body'] as &$body_part) {
      if ($body_part instanceof MarkupInterface) {
        $body_part = Markup::create(Html::transformRootRelativeUrlsToAbsolute((string) $body_part, \Drupal::request()->getSchemeAndHttpHost()));
      }
    }

    // Format the message body.
    $body = $message['body'][0];
    $message = $system->format($message);
    $message['body'] = $body;

    // Optionally send email.
    if ($send) {
      // The original caller requested sending. Sending was canceled by one or
      // more hook_mail_alter() implementations. We set 'result' to NULL,
      // because FALSE indicates an error in sending.
      if (empty($message['send'])) {
        $message['result'] = NULL;
      }
      // Sending was originally requested and was not canceled.
      else {
        // Ensure that subject is plain text. By default translated and
        // formatted strings are prepared for the HTML context and email
        // subjects are plain strings.
        if ($message['subject']) {
          $message['subject'] = PlainTextOutput::renderFromHtml($message['subject']);
        }
        $message['result'] = $system->mail($message);
        // Log errors.
        if (!$message['result']) {
          $this->loggerFactory->get('mail')
            ->error('Error sending email (from %from to %to with reply-to %reply).', [
              '%from' => $message['from'],
              '%to' => $message['to'],
              '%reply' => $message['reply-to'] ? $message['reply-to'] : $this->t('not set'),
            ]);
          $this->messenger()->addError($this->t('Unable to send email. Contact the site administrator if the problem persists.'));
        }
      }
    }

    return $message;
  }

}
