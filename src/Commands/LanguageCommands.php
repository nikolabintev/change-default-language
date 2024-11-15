<?php

namespace Drupal\change_default_language\Commands;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class LanguageCommands extends DrushCommands {

  /**
   * The entity type manager service.
   *
   * @var EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /*
   * The default language service.
   *
   * @var LanguageDefault
   */
  protected LanguageDefault $languageDefault;

  /**
   * The language manager service.
   *
   * @var LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The config factory service.
   *
   * @var ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler service.
   *
   * @var ModuleHandler
   */
  protected ModuleHandler $moduleHandler;

  /**
   * Constructs a new SetDefaultLanguageCommands object.
   *
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param LanguageDefault $languageDefault
   *   Default language service.
   * @param LanguageManagerInterface $languageManager
   *  The language manager service.
   * @param ConfigFactoryInterface $configFactory
   *   The module handler service.
   * @param ModuleHandler $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LanguageDefault $languageDefault,
    LanguageManagerInterface $languageManager,
    ConfigFactoryInterface $configFactory,
    ModuleHandler $moduleHandler
  ) {
    parent::__construct();

    $this->entityTypeManager = $entityTypeManager;
    $this->languageDefault = $languageDefault;
    $this->languageManager = $languageManager;
    $this->configFactory = $configFactory;
    $this->moduleHandler = $moduleHandler;
  }

  #[CLI\Command(name: 'language:set:default', aliases: ['lsd'])]
  #[CLI\Argument(name: 'langcode', description: 'Language code.')]
  #[CLI\Argument(name: 'name', description: 'Language name.')]
  #[CLI\Argument(name: 'direction', description: 'Language direction.')]
  public function setDefaultLanguage($langcode, $name, $direction = LanguageInterface::DIRECTION_LTR): void {
    if ($langcode === $this->languageDefault->get()->getId()) {
      $this->output()->writeln(dt('The language is already set as default.'));
      return;
    }

    $currentDefaultLanguage = $this->languageManager->getDefaultLanguage();
    $direction = !in_array($direction, [LanguageInterface::DIRECTION_LTR, LanguageInterface::DIRECTION_RTL]) ? LanguageInterface::DIRECTION_LTR : $direction;
    $defaultLanguage = ConfigurableLanguage::load($langcode);
    if (!$defaultLanguage) {
      $defaultLanguage = ConfigurableLanguage::create([
        'id' => $langcode,
        'label' => $name,
        'direction' => $direction,
      ]);
      try {
        $defaultLanguage->save();
        $this->output()->writeln(dt('Created "@langcode" language.', ['@langcode' => $langcode]));
      } catch (EntityStorageException $e) {
        $this->output()->writeln(dt('The language could not be saved due to the following error: @error', [
          '@error' => $e->getMessage(),
        ]));
        return;
      }
    }

    $this->languageDefault->set($defaultLanguage);
    $this->configFactory->getEditable('system.site')->set('default_langcode', $defaultLanguage->id())->save();
    $this->languageManager->reset();

    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      if (!is_subclass_of($entityType->getOriginalClass(), ContentEntityBase::class)) {
        continue;
      }

      if ($entityType->hasKey('langcode')) {
        try {
          $storage = $this->entityTypeManager->getStorage($entityTypeId);
          $entities = $storage->loadMultiple();

          foreach ($entities as $entity) {
            /**
             * In case of asymmetric translations, the entities are created in language different from the default one.
             * We don't want to update they langcode. For example, inline content blocks.
             */
            if ($entity->get('langcode')->value === $currentDefaultLanguage->getId()) {
              $entity->set('langcode', $defaultLanguage->id());
              $entity->save();
              $this->updateTranslations($entity, $defaultLanguage);
            }
          }

        } catch (InvalidPluginDefinitionException | PluginNotFoundException | EntityStorageException $e) {
          $this->logger()->error($e->getMessage());
        }
      }
    }
  }

  /**
   * Updates the source language of the translation entities.
   *
   * @param EntityInterface $entity
   *   The entity to get translations for.
   * @param LanguageInterface $defaultLanguage
   *   The default language instance.
   *
   * @throws EntityStorageException
   *   Thrown when an error occurs while saving the entity.
   */
  protected function updateTranslations(EntityInterface $entity, LanguageInterface $defaultLanguage): void {
    $languages = $this->languageManager->isMultilingual() ?
      array_filter($this->languageManager->getLanguages(), function($lang) use ($defaultLanguage) {
        return $lang !== $defaultLanguage->id();
      }, ARRAY_FILTER_USE_KEY) : [];


    if ($entity->getEntityType()->isTranslatable() && $this->moduleHandler->moduleExists('content_translation')) {
      /** @var ContentTranslationManagerInterface $content_translation_manager */
      $content_translation_manager = \Drupal::service('content_translation.manager');
      foreach (array_keys($languages) as $langcode) {
        /** @var EntityInterface $translation */
        if ($entity->hasTranslation($langcode)) {
          $translation = $entity->getTranslation($langcode);
          $content_translation_manager->getTranslationMetadata($translation)->setSource($defaultLanguage->id());
          $translation->save();
        }
      }
    }
  }

}
