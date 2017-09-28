<?php
namespace StudioNet\GraphQL\Generator\Mutation;

use GraphQL\Type\Definition\Type as GraphQLType;
use Illuminate\Database\Eloquent\Model;
use StudioNet\GraphQL\Definition\Type\EloquentObjectType;
use StudioNet\GraphQL\Generator\Generator;
use StudioNet\GraphQL\Support\Eloquent\ModelAttributes;
use GraphQL\Type\Definition\InputObjectType as GraphQLInputObjectType;

/**
 * Generate singular query from Eloquent object type
 *
 * @see Generator
 */
class NodeEloquentGenerator extends Generator {
	/**
	 * {@inheritDoc}
	 */
	public function supports($instance) {
		return ($instance instanceof EloquentObjectType);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getKey($instance) {
		return strtolower(str_singular($instance->name));
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate($instance) {
		return [
			'args'    => $this->getArguments($instance->getModel()),
			'type'    => $instance,
			'resolve' => $this->getResolver($instance->getModel())
		];
	}

	/**
	 * Return availabled arguments from model reflection database fields
	 *
	 * @param  Model $model
	 * @return array
	 */
	public function getArguments(Model $model) {
		$data       = [];
		$attributes = $this->app->make(ModelAttributes::class);
		$columns    = array_filter($attributes->getColumns($model));
		$fillable   = array_flip($model->getFillable());
		$guarded    = array_flip($model->getGuarded());
		$hidden     = array_flip($model->getHidden());
		$primary    = $model->getKeyName();
		$relations  = array_keys($attributes->getRelations($model));

		if (!empty($fillable)) {
			$columns = array_intersect_key($columns, $fillable);
		}

		else if (!empty($guarded)) {
			$columns = array_diff_key($columns, $guarded);
		}

		if (!empty($hidden)) {
			$columns = array_diff_key($columns, $hidden);
		}

		// Append primary key
		if (!array_key_exists($primary, $columns)) {
			unset($columns[$primary]);
		}

		// Parse each column in order to know which is fillable. To allow
		// model to be updated, we have to use a uniq id : the id
		foreach ($columns as $column => $type) {
			$data[$column] = ['type' => $type];
		}

		foreach ($relations as $column) {
			$data[$column] = [
				'type' => $this->app['graphql']->scalar('array'),
				'description' => $column . ' relationship'
			];
		}

		return [
			$primary => [
				'description' => 'Identifier',
				'type' => GraphQLType::id()
			],
			'with' => [
				'description' => 'Availabled fields',
				'type' => new GraphQLInputObjectType([
					'name' => ucfirst(str_singular($model->getTable())) . 'Arguments',
					'fields' => $data
				])
			]
		];
	}

	/**
	 * Resolve mutation
	 *
	 * @param  Model $model
	 * @return Model
	 *
	 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
	 */
	protected function getResolver(Model $model) {
		$attributes = $this->app->make(ModelAttributes::class);
		$relations  = array_flip(array_keys($attributes->getRelations($model)));
		$columns    = array_flip(array_keys($attributes->getColumns($model)));

		return function($root, array $args) use ($model, $relations, $columns) {
			$primary = $model->getKeyName();
			$primary = isset($args[$primary]) ? $args[$primary] : 0;
			$entity  = $model->findOrNew($primary);
			$related = array_intersect_key($args['with'], $relations);
			$data    = array_diff_key(
				array_intersect_key($args['with'], $columns),
				$relations
			);

			$entity->fill($data);
			$entity->save();

			foreach ($related as $column => $values) {
				$entity->{$column}()->sync($values);
			}

			return $entity;
		};
	}
}
