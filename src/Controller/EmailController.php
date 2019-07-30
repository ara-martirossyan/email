<?php

namespace Drupal\email\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Renderer;
use Drupal\email\Service\EmailManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zend\Diactoros\Response\JsonResponse;

/**
 * Class EmailController.
 */
class EmailController extends ControllerBase {

  /**
   * @var EmailManager
   */
  protected $emailManager;

  /**
   * @var Renderer
   */
  protected   $renderer;

  /**
   * Constructs a new EmailController object.
   */
  public function __construct(EmailManager $email_manager, Renderer $renderer) {
    $this->emailManager = $email_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('email.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Send Email.
   *
   * @return string
   *   Return Hello string.
   */
  public function send() {
    $send = $this->emailManager->mail(
      "email",
      "test",
      "ara@aralmighty.com",
      "fr",
      [
        'message' => $this->getMailMessage("Thank for your interest in my profile.<br>Please, find bellow my CV attached"),
        'subject' => "CV Ara Martirosyan",
        // admin/config/system/smtp turn ON inside install options
        'attachments' => [
          [
            // "sites/sombrero/files/cv.pdf"
            // "public://cv.pdf"
            'filepath' => \Drupal::moduleHandler()->getModule('email')->getPath() . "/files/cv.pdf",
            'filename' => "cv.pdf",
            'filemime' => "application/pdf",
          ],
        ],
      ],
      NULL,
      TRUE
    );

    return new JsonResponse($send['result']);
  }

  /**
   * @param $content
   * @return string
   * @throws \Exception
   */
  public function getMailMessage($content) {
    $renderable = [
      '#theme' => 'email',
      '#content' => $content,
    ];
    $message = $this->renderer->render($renderable)->__toString();

    return $message;
  }

}
