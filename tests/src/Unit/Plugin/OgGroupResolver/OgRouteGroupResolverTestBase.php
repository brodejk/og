<?php

namespace Drupal\Tests\og\Unit\Plugin\OgGroupResolver;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\og\GroupTypeManager;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgGroupAudienceHelperInterface;
use Drupal\og\OgResolvedGroupCollectionInterface;
use Symfony\Component\Routing\Route;

/**
 * Base class for testing OgGroupResolver plugins that depend on the route.
 */
abstract class OgRouteGroupResolverTestBase extends OgGroupResolverTestBase {

  /**
   * Mocked test entities.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface[]
   */
  protected $testEntities;

  /**
   * A list of link templates that belong to entity types used in the tests.
   *
   * This mocks the data returned by EntityTypeInterface::getLinkTemplates().
   *
   * @var array
   *   A list of link templates, keyed by entity type ID.
   *
   * @see \Drupal\Core\Entity\EntityTypeInterface::getLinkTemplates()
   */
  protected $linkTemplates = [
    'node' => [
      'canonical' => '/node/{node}',
      'delete-form' => '/node/{node}/delete',
      'edit-form' => '/node/{node}/edit',
      'version-history' => '/node/{node}/revisions',
      'revision' => '/node/{node}/revisions/{node_revision}/view',
    ],
    'entity_test' => [
      'canonical' => '/entity_test/{entity_test}',
      'add-form' => '/entity_test/add',
      'edit-form' => '/entity_test/manage/{entity_test}/edit',
      'delete-form' => '/entity_test/delete/entity_test/{entity_test}',
    ],
    'taxonomy_term' => [
      'canonical' => '/taxonomy/term/{taxonomy_term}',
      'delete-form' => '/taxonomy/term/{taxonomy_term}/delete',
      'edit-form' => '/taxonomy/term/{taxonomy_term}/edit',
    ],
  ];

  /**
   * The mocked route matcher.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $routeMatch;

  /**
   * The mocked OG group type manager.
   *
   * @var \Drupal\og\GroupTypeManager|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $groupTypeManager;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $entityTypeManager;

  /**
   * The OG membership manager.
   *
   * @var \Drupal\og\MembershipManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $membershipManager;

  /**
   * The OG group audience helper.
   *
   * @var \Drupal\og\OgGroupAudienceHelperInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $groupAudienceHelper;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Instantiate mocks of the classes that the plugins rely on.
    $this->routeMatch = $this->prophesize(RouteMatchInterface::class);
    $this->groupTypeManager = $this->prophesize(GroupTypeManager::class);
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
    $this->membershipManager = $this->prophesize(MembershipManagerInterface::class);
    $this->groupAudienceHelper = $this->prophesize(OgGroupAudienceHelperInterface::class);

    // Create mocked test entities.
    /** @var \Drupal\Core\Entity\ContentEntityInterface[] $test_entities */
    $test_entities = [];
    foreach ($this->getTestEntityProperties() as $id => $properties) {
      $entity_type_id = $properties['type'];
      $bundle_id = $properties['bundle'];
      $is_group = !empty($properties['group']);
      $is_group_content = !empty($properties['group_content']);

      /** @var \Drupal\Core\Entity\ContentEntityInterface|\Prophecy\Prophecy\ObjectProphecy $entity */
      $entity = $this->prophesize(ContentEntityInterface::class);

      // In case this entity is questioned about its identity, it shall
      // willingly pony up the requested information.
      $entity->id()->willReturn($id);
      $entity->getEntityTypeId()->willReturn($properties['type']);
      $entity->bundle()->willReturn($properties['bundle']);
      $test_entities[$id] = $entity->reveal();

      // It is not being tight lipped about whether it is a group or group
      // content.
      $this->groupTypeManager->isGroup($entity_type_id, $bundle_id)
        ->willReturn($is_group);
      $this->groupAudienceHelper->hasGroupAudienceField($entity_type_id, $bundle_id)
        ->willReturn($is_group_content);

      // If the entity is group content it will spill the beans on which groups
      // it belongs to.
      if ($is_group_content) {
        $groups = [];
        foreach ($properties['group_content'] as $group_id) {
          $group = $test_entities[$group_id];
          $groups[$group->getEntityTypeId()][$group->id()] = $group;
        }
        $this->membershipManager->getGroups($entity)
          ->willReturn($groups);
      }
    }
    $this->testEntities = $test_entities;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Update documentation.
   *
   * @covers ::resolve
   * @dataProvider resolveProvider
   */
  public function testResolve($path = NULL, $route_object_id = NULL, array $expected_added_groups = [], array $expected_removed_groups = []) {
    if ($path) {
      // It is expected that the plugin will retrieve the current path from the
      // route matcher.
      $this->willRetrieveCurrentPathFromRouteMatcher($path);
      // It is expected that the plugin will retrieve the full list of content
      // entity paths, so it can check whether the current path is related to a
      // content entity.
      $this->willRetrieveContentEntityPaths();
    }

    if ($route_object_id) {
      // The plugin might retrieve the route object. This should only happen if
      // we are on an actual entity path.
      $this->mightRetrieveRouteObject($route_object_id);
      // If a route object is returned the plugin will need to inspect it to
      // check if it is a group.
      $this->mightCheckIfRouteObjectIsGroup($route_object_id);
    }

    // Add expectations for the groups that are added and removed by the plugin.
    $test_entities = $this->testEntities;
    foreach ([&$expected_added_groups, &$expected_removed_groups] as &$expected_groups) {
      // Replace the entity IDs from the data provider with actual test
      // entities.
      $expected_groups = array_map(function ($item) use ($test_entities) {
        return $test_entities[$item];
      }, $expected_groups);
    }

    // Add expectations for groups that should be added or removed.
    /** @var \Drupal\og\OgResolvedGroupCollectionInterface|\Prophecy\Prophecy\ObjectProphecy $collection */
    $collection = $this->prophesize(OgResolvedGroupCollectionInterface::class);

    foreach ($expected_added_groups as $expected_added_group) {
      $collection->addGroup($expected_added_group, ['route'])->shouldBeCalled();
    }

    foreach ($expected_removed_groups as $expected_removed_group) {
      $collection->removeGroup($expected_removed_group)->shouldBeCalled();
    }

    // Set expectations for when NO groups should be added or removed.
    if (empty($expected_added_groups)) {
      $collection->addGroup()->shouldNotBeCalled();
    }
    if (empty($expected_removed_groups)) {
      $collection->removeGroup()->shouldNotBeCalled();
    }

    // Launch the test. Any unmet expectation will cause a failure.
    $plugin = $this->getPluginInstance($this->getInjectedDependencies());
    $plugin->resolve($collection->reveal());
  }

  /**
   * {@inheritdoc}
   */
  protected function getPluginInstance(array $args = []) {
    $args = $args ?: $this->getInjectedDependencies();
    return parent::getPluginInstance($args);
  }

  /**
   * Returns the mocked classes that the plugin depends on.
   *
   * @return array
   *   The mocked dependencies.
   */
  protected function getInjectedDependencies() {
    return [];
  }

  /**
   * Returns properties used to create mock test entities.
   *
   * This is used to facilitate referring to entities in data providers. Since a
   * data provider is called before the test setup runs, we cannot return actual
   * entities in the data provider. Instead the data provider can refer to these
   * test entities by ID, and the actual entity mocks will be generated in the
   * test setup.
   *
   * The test groups should be declared first, the group content last.
   *
   * @return array
   *   An array of entity metadata, keyed by test entity ID. Each item is an
   *   array with the following keys:
   *   - type (required): The entity type ID.
   *   - bundle (required): The entity bundle.
   *   - group (optional): Whether or not the entity is a group.
   *   - group_content (optional): An array containing IDs of groups this group
   *     content belongs to.
   */
  abstract protected function getTestEntityProperties();

  /**
   * Returns a set of entity link templates for testing.
   *
   * This mimicks the data returned by EntityTypeInterface::getLinkTemplates().
   *
   * @param string $entity_type
   *   The entity type for which to return the link templates.
   *
   * @return array
   *   An array of link templates.
   *
   * @see \Drupal\Core\Entity\EntityTypeInterface::getLinkTemplates()
   */
  protected function getLinkTemplates($entity_type) {
    return $this->linkTemplates[$entity_type];
  }

  /**
   * Adds an expectation that the current path will be retrieved from the route.
   *
   * @param string $path
   *   The path that will be retrieved.
   */
  protected function willRetrieveCurrentPathFromRouteMatcher($path) {
    /** @var \Symfony\Component\Routing\Route|\Prophecy\Prophecy\ObjectProphecy $route */
    $route = $this->prophesize(Route::class);
    $route
      ->getPath()
      ->willReturn($path)
      ->shouldBeCalled();
    $this->routeMatch
      ->getRouteObject()
      ->willReturn($route->reveal())
      ->shouldBeCalled();
  }

  /**
   * Adds an expectation that the plugin will retrieve a list of entity paths.
   *
   * The plugin need to match the current path to this list of entity paths to
   * see if we are currently on an entity path of a group or group content
   * entity.
   * In order to retrieve the content entity paths, the plugin will have to
   * request a full list of all entity types, then request the "link templates"
   * from the content entities.
   */
  protected function willRetrieveContentEntityPaths() {
    // Provide some mocked content entity types.
    $entity_types = [];
    foreach (array_keys($this->linkTemplates) as $entity_type_id) {
      /** @var \Drupal\Core\Entity\EntityTypeInterface|\Prophecy\Prophecy\ObjectProphecy $entity_type */
      $entity_type = $this->prophesize(EntityTypeInterface::class);
      // The plugin will need to know if this is a content entity, so we will
      // provide this information. We are not requiring this to be called since
      // there are other ways of determining this (e.g. `instanceof`).
      $entity_type->isSubclassOf(ContentEntityInterface::class)->willReturn(TRUE);

      // The plugin will need to inquire about the link templates that the
      // entity provides. This should be called.
      $entity_type->getLinkTemplates()
        ->willReturn($this->getLinkTemplates($entity_type_id))
        ->shouldBeCalled();
      $entity_types[$entity_type_id] = $entity_type->reveal();
    }
    $this->entityTypeManager->getDefinitions()
      ->willReturn($entity_types)
      ->shouldBeCalled();
  }

  /**
   * Adds an expectation that the plugin will (not) retrieve the route object.
   *
   * If the current path is an entity path, the plugin should retrieve the
   * entity from the route so it can check if the entity is a group. If we are
   * not, then it should not attempt to retrieve it.
   *
   * @param string|null $route_object_id
   *   The ID of the entity that is present on the current route, or NULL if we
   *   are not on a content entity path. The ID may be any of the ones created
   *   in the test setup, e.g. 'group', 'group_content', 'non_group'.
   */
  protected function mightRetrieveRouteObject($route_object_id) {
    // The route object should only be retrieved if we are on a content entity
    // path.
    if ($route_object_id) {
      $this->routeMatch->getParameter($this->getTestEntityProperties()[$route_object_id]['type'])
        ->willReturn($this->testEntities[$route_object_id]);
    }
  }

  /**
   * Adds an expectation that checks if the route object is a group.
   *
   * If the plugin found a content entity on the route then it should check
   * whether the entity is a group or not. If no content entity was found, it
   * should not perform this check.
   *
   * @param string|null $route_object_id
   *   The ID of the entity that is present on the current route, or NULL if we
   *   are not on a content entity path. The ID may be any of the ones created
   *   in the test setup, e.g. 'group', 'group_content', 'non_group'.
   */
  protected function mightCheckIfRouteObjectIsGroup($route_object_id) {
    $properties = $this->getTestEntityProperties();

    $entity_type_id = $properties[$route_object_id]['type'];
    $bundle = $properties[$route_object_id]['bundle'];
    $this->groupTypeManager->isGroup($entity_type_id, $bundle)
      ->willReturn(!empty($properties[$route_object_id]['group']));
  }

}
