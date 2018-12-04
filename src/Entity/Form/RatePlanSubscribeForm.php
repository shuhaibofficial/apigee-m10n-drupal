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

use Drupal\apigee_m10n\ApigeeSdkControllerFactory;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\apigee_m10n\Entity\Subscription;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Cache\Cache;

/**
 * Unsubscribe entity form for subscriptions.
 */
class RatePlanSubscribeForm extends EntityForm {

  /**
   * Drupal user entity.
   *
   * @var \Drupal\user\Entity\User|null
   */
  protected $developer;

  /**
   * Rate Plan entity.
   *
   * @var \Drupal\apigee_m10n\Entity\RatePlan|null
   */
  protected $rate_plan;

  /**
   * Messanger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * SDK Controller factory.
   *
   * @var Drupal\apigee_m10n\ApigeeSdkControllerFactory
   */
  protected $sdkControllerFactory;

  /**
   * RatePlanSubscribeForm constructor.
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
    $this->rate_plan = $route_match->getParameter('rate_plan');
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
   * Provides a generic title callback for a single entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Entity\EntityInterface $_entity
   *   (optional) An entity, passed in directly from the request attributes.
   *
   * @return string|null
   *   The title for the entity view page, if an entity was found.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function title(RouteMatchInterface $route_match, EntityInterface $_entity = NULL) {
    return $this->t("Purchase <em>%label</em> plan", ['%label' => $this->rate_plan->getDisplayName()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    // By doing this we're able to pass required parameters from the form field formatter
    // and render subscribe form anywhere we render rate plan entity.
    $storage = $form_state->getStorage();
    if (!empty($storage['user'])) {
      $this->developer = $storage['user'];
    }
    if (!empty($storage['rate_plan'])) {
      $this->rate_plan = $storage['rate_plan'];
    }
    // This will help to solve issue with the #state API when an entity form
    // gets rendered on the page. e.g Packages page.
    $prefix = '_' . $this->rate_plan->id();
    $form_state->set('prefix', $prefix);

    $form[$prefix . 'start_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Plan Start Date'),
      '#options' => [
        'now'     => $this->t('Now'),
        'on_date' => $this->t('Future Date')
      ],
      '#default_value' => 'now'
    ];

    $form[$prefix . 'startDate'] = [
      '#type'  => 'date',
      '#title' => $this->t('Start Date'),
      '#states' => [
        'visible' => [
          ':input[name="' . $prefix . 'start_type"]' => ['value' => 'on_date'],
        ]
      ],
    ];

    $form['actions']['submit']['#value'] = !empty($storage['button_label']) ? $storage['button_label'] : $this->t('Purchase Plan');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $prefix = $form_state->get('prefix');
    $start_date_field = $prefix . 'startDate';
    if ($values[$prefix . 'start_type'] == 'on_date') {
      if (empty($values[$start_date_field])) {
        $form_state->setErrorByName($start_date_field, $this->t('Please make sure to specify date'));
      }
      $start_date = new \DateTimeImmutable($values[$start_date_field]);
      $current_date = new \DateTimeImmutable('now');
      if ($start_date->getTimestamp() < $current_date->getTimestamp()) {
        $form_state->setErrorByName($start_date_field, $this->t('Start Date should be future date'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Remove button and internal Form API values from submitted values.
    $form_state->cleanValues();
    $this->entity = $this->buildEntity($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $prefix = $form_state->get('prefix');
    $entity = Subscription::create([
      'developer' => $this->sdkControllerFactory->developerController()->load($this->developer->getEmail()),
      'startDate' => $values[$prefix . 'start_type'] == 'on_date' ? new \DateTimeImmutable($values[$prefix . 'startDate']) : new \DateTimeImmutable('now'),
      'ratePlan' => $this->rate_plan->decorated(),
    ]);

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    try {
      $display_name = $this->rate_plan->getDisplayName();
      if ($this->entity->save()) {
        $this->messenger->addStatus($this->t('You have purchased <em>%label</em> plan', [
          '%label' => $display_name,
        ]));
      }
      else {
        $this->messenger->addWarning($this->t('Unable purchase <em>%label</em> plan', [
          '%label' => $display_name,
        ]));
      }
      Cache::invalidateTags(['apigee_my_subscriptions']);
      $form_state->setRedirect('apigee_monetization.my_subscriptions');
    }
    catch (\Exception $e) {
      $this->messenger->addError($e->getMessage());
    }
  }

}
