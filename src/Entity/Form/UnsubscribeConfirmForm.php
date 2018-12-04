<?php

/*
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\apigee_m10n\Entity\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\apigee_m10n\ApigeeSdkControllerFactory;
use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;

/**
 * Unsubscribe entity form for subscriptions.
 */
class UnsubscribeConfirmForm extends EntityConfirmFormBase {

  /**
   * Drupal user.
   *
   * @var \Drupal\user\Entity\User|null
   */
  protected $developer;

  /**
   * Subscription entity.
   *
   * @var \Drupal\apigee_m10n\Entity\Subscription|null
   */
  protected $subscription;

  /**
   * Messanger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * SDK controller factory.
   *
   * @var \Drupal\apigee_m10n\ApigeeSdkControllerFactory
   */
  protected $sdkControllerFactory;

  /**
   * UnsubscribeConfirmForm constructor.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   Route match service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messanger service.
   * @param \Drupal\apigee_m10n\ApigeeSdkControllerFactory $sdkControllerFactory
   *   SDK Controller factory.
   */
  public function __construct(RouteMatchInterface $route_match, MessengerInterface $messenger, ApigeeSdkControllerFactory $sdkControllerFactory) {
    $this->developer = $route_match->getParameter('user');
    $this->subscription = $route_match->getParameter('subscription');
    $this->messenger = $messenger;
    $this->sdkControllerFactory = $sdkControllerFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('messenger'),
      $container->get('apigee_m10n.sdk_controller_factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Are you sure you would like to unsubscribe from this plan?');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('End This Plan');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Cancel <em>%label</em> plan', [
      '%label' => $this->subscription->getRatePlan()->getDisplayName()
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('apigee_monetization.my_subscriptions');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $form['end_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Plan End Date'),
      '#options' => [
        'now'     => $this->t('Now'),
        'on_date' => $this->t('Future Date')
      ],
      '#default_value' => 'now'
    ];
    $form['endDate'] = [
      '#type'  => 'date',
      '#title' => $this->t('Select End Date'),
      '#states' => [
        'visible' => [
          ':input[name="end_type"]' => ['value' => 'on_date'],
        ]
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $end_type = $values['end_type'] ?? 'now';

    $this->subscription->setEndDate($end_type == 'end_date'
      ? new \DateTimeImmutable($values['endDate'])
      : new \DateTimeImmutable('-1 day'));

    return $this->subscription;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      if ($this->entity->save()) {
        $this->messenger->addStatus($this->t('You have successfully cancelled <em>%label</em> plan', [
          '%label' => $this->entity->getRatePlan()->getDisplayName(),
        ]));
        Cache::invalidateTags(['apigee_my_subscriptions']);
        $form_state->setRedirect('apigee_monetization.my_subscriptions');
      }
    }
    catch (\Exception $e) {
      $this->messenger->addError($e->getMessage());
    }
  }

}
