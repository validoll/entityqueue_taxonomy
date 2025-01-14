<?php

namespace Drupal\entityqueue_taxonomy\Plugin\EntityQueueHandler;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\entityqueue\Entity\EntitySubqueue;
use Drupal\entityqueue\EntityQueueInterface;
use Drupal\entityqueue\Plugin\EntityQueueHandler\Multiple;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @EntityQueueHandler(
 *  id = "taxonomy_term",
 *  title = @Translation("Taxonomy Subqueues per term"),
 * )
 */
class Taxonomy extends Multiple implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new Taxonomy object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * @inheritdoc
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration() + [
      'vocabulary' => NULL,
    ];
    return $config;
  }

  /**
   * @inheritdoc
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $vocabularies = Vocabulary::loadMultiple();
    $options = array_map(function($element){
      return $element->label();
    }, $vocabularies);
    $form['vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Vocabulary'),
      '#description' => $this->t('Select vocabulary to create automatic subqueues for'),
      '#options' => $options,
      '#default_value' => $this->configuration['vocabulary'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $this->configuration['vocabulary'] = $form_state->getValue('vocabulary');
    }
  }

  /**
   * @inheritdoc
   */
  public function hasAutomatedSubqueues() {
    return TRUE;
  }

  /**
   * @inheritdoc
   */
  public function onQueuePostSave(EntityQueueInterface $queue, EntityStorageInterface $storage, $update = TRUE) {
    parent::onQueuePostSave($queue, $storage, $update);

    $terms = $this->getSubqueues($queue);
    $subqueue_storage = $this->entityTypeManager->getStorage('entity_subqueue');
    $subqueues = $subqueue_storage->loadByProperties([$this->entityTypeManager->getDefinition('entity_subqueue')->getKey('bundle') => $queue->id()]);

    // Remove old.
    foreach ($subqueues as $subqueue_index => $subqueue) {
      if (in_array($subqueue->id(), array_keys($terms))) {
        unset($terms[$subqueue->id()]);
      }
      else {
        $subqueue->delete();
      }
    }

    // Add new.
    foreach ($terms as $id => $term) {
      $subqueue = EntitySubqueue::create([
        'queue' => $queue->id(),
        'name' => $id,
        'title' => $term,
        'langcode' => $queue->language()->getId(),
      ]);
      $subqueue->save();
    }

  }

  /**
   * {@inheritdoc}
   */
  public function onQueuePostDelete(EntityQueueInterface $queue, EntityStorageInterface $storage) {
    // Delete all the subqueues when the parent queue is deleted.
    $subqueue_storage = $this->entityTypeManager->getStorage('entity_subqueue');

    $subqueues = $subqueue_storage->loadByProperties([$this->entityTypeManager->getDefinition('entity_subqueue')->getKey('bundle') => $queue->id()]);
    $subqueue_storage->delete($subqueues);
  }

  /**
   * Get subqueues of the queue.
   *
   * @param EntityQueueInterface $queue
   *   The queue object.
   *
   * @return array
   *   An array of subqueues.
   */
  public function getSubqueues(EntityQueueInterface $queue) {
    // Run an entity query to get all taxonomy terms in the vocabulary found
    // in the $queue's settings.
    $subqueues = [];
    $vocabulary = $this->configuration['vocabulary'];
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadTree($vocabulary);
    foreach ($terms as $term) {
      $subqueues[$queue->id() . '_' . $term->tid] = $term->name;
    }
    return $subqueues;
  }

}
