<?php
namespace StudioNet\GraphQL;

use GraphQL\GraphQL as GraphQLBase;
use GraphQL\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type as GraphQLType;
use Illuminate\Foundation\Application;

/**
 * GraphQL implementation singleton
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class GraphQL {
	/** @var Application $app */
	private $app;

	/** @var array $schemas */
	private $schemas = [];

	/** @var array $types */
	private $types = [];

	/** @var ScalarType[] $scalars */
	private $scalars = [];

	/** @var array $transformers */
	private $transformers = [
		'type'     => [],
		'query'    => [],
		'mutation' => []
	];

	/** @var array $generators */
	private $generators = [
		'query'    => [],
		'mutation' => []
	];

	/**
	 * __construct
	 *
	 * @param  Application $app
	 * @return void
	 */
	public function __construct(Application $app) {
		$this->app = $app;
	}

	/**
	 * Return built schema
	 *
	 * @param  string $name
	 * @return Schema
	 */
	public function getSchema($name) {
		if (!$this->hasSchema($name)) {
			throw new Exception\SchemaNotFoundException('Cannot find schema ' . $name);
		}

		// Represents an array like
		//
		// [
		//    'query'    => [],
		//    'mutation' => []
		// ]
		$schema = $this->schemas[$name];

		// Compute query and mutation fields
		$schema['query']    = $this->manageQuery($schema['query']);
		$schema['mutation'] = $this->manageMutation($schema['mutation']);

		return new Schema($schema);
	}

	/**
	 * Return existing type
	 *
	 * @param  string $name
	 * @return ObjectType
	 */
	public function type($name) {
		$name = strtolower($name);

		if (array_key_exists($name, $this->types)) {
			return $this->types[$name];
		}

		throw new Exception\TypeNotFoundException('Cannot find type ' . $name);
	}

	/**
	 * Return existing type as lifeOf
	 *
	 * @param  string $name
	 * @return ListOf
	 */
	public function listOf($name) {
		return GraphQLType::listOf($this->type($name));
	}

	/**
	 * Return existing scalar
	 *
	 * @param  string $name
	 * @return ScalarType
	 */
	public function scalar($name) {
		$name = strtolower($name);

		if (array_key_exists($name, $this->scalars)) {
			return $this->scalars[$name];
		}

		throw new Exception\ScalarNotFoundException('Cannot find scalar ' . $name);
	}

	/**
	 * Execute query
	 *
	 * @param  string $query
	 * @param  array  $variables
	 * @param  array  $opts
	 *
	 * @return array
	 */
	public function execute($query, $variables = [], $opts = []) {
		$root       = array_get($opts, 'root', null);
		$context    = array_get($opts, 'context', null);
		$schemaName = array_get($opts, 'schema', null);
		$operation  = array_get($opts, 'operationName', null);
		$schema     = $this->getSchema($schemaName);
		$result     = GraphQLBase::executeAndReturnResult($schema, $query, $root, $context, $variables, $operation);
		$data       = ['data' => $result->data];

		if (!empty($result->errors)) {
			$data['errors'] = $result->errors;
		}

		return $data;
	}

	/**
	 * Manage query
	 *
	 * @param  string[] $queries
	 * @return array
	 */
	public function manageQuery(array $queries) {
		$data = [];

		// Parse each query class and build it within the ObjectType
		foreach ($queries as $name => $query) {
			if (is_numeric($name)) {
				$name = strtolower(with(new \ReflectionClass($query))->getShortName());
			}

			// We don't want \ReflectionException exception : just override it
			// and return ours
			try {
				$query = $this->applyTransformers('query', $query);
			} catch (\ReflectionException $e) {
				throw new Exception\TypeNotFoundException($e->getMessage());
			}

			$data = $data + [$name => $query];
		}

		return new ObjectType([
			'name'   => 'Query',
			'fields' => $this->applyGenerators('query', $data)
		]);
	}

	/**
	 * TODO
	 *
	 * @param  array $mutations
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function manageMutation(array $mutations) {
		$data = [];

		// Parse each query class and build it within the ObjectType
		foreach ($mutations as $name => $mutation) {
			if (is_numeric($name)) {
				$name = strtolower(with(new \ReflectionClass($mutation))->getShortName());
			}

			// We don't want \ReflectionException exception : just override it
			// and return ours
			try {
				$mutation = $this->applyTransformers('mutation', $mutation);
			} catch (\ReflectionException $e) {
				throw new Exception\TypeNotFoundException($e->getMessage());
			}

			$data = $data + [$name => $mutation];
		}

		return new ObjectType([
			'name'   => 'Mutation',
			'fields' => $this->applyGenerators('mutation', $data)
		]);
	}

	/**
	 * Register a schema
	 *
	 * @param  string $name
	 * @param  array  $data
	 *
	 * @return void
	 */
	public function registerSchema($name, array $data) {
		$this->schemas[$name] = array_merge([
			'query'    => [],
			'mutation' => [],
		], $data);
	}

	/**
	 * Register a type
	 *
	 * @param  string|null $name
	 * @param  mixed $type
	 *
	 * @return void
	 */
	public function registerType($name, $type) {
		try {
			$type = $this->applyTransformers('type', $type);
		} catch (\ReflectionException $e) {
			throw new Exception\TypeNotFoundException($e->getMessage());
		}

		// Assert that object is an ObjectType
		if ((!$type instanceof ObjectType)) {
			throw new Exception\TypeException('Given type doesn\'t extends from typeType');
		}

		// If there's no name, just guess it from built typeType or fallback
		// on type name
		if (empty($name) and empty($type->name)) {
			$name = with(new \ReflectionClass($type))->getShortName();
			$type->name = $name;
		}

		$this->types[strtolower($type->name)] = $type;
	}

	/**
	 * Register scalar
	 *
	 * @param  string|null $name
	 * @param  ScalarType $scalar
	 *
	 * @return void
	 */
	public function registerScalar($name, $scalar) {
		if (is_string($scalar)) {
			$scalar = $this->app->make($scalar);
		}

		// Assert that given scalar extends from ScalarType
		if (!$scalar instanceof ScalarType) {
			throw new Exception\ScalarException('Given scalar doesn\'t extend from ScalarType');
		}

		// Append name if doesn't exists or is numeric
		if (empty($name) or is_numeric($name)) {
			$name = $scalar->name;
		}

		$this->scalars[strtolower($name)] = $scalar;
	}

	/**
	 * Register transformer. A transformer performs transactions between an
	 * Object to another. Each transformer is applied on specific type of data :
	 * type, query or mutation. It cannot handle either.
	 *
	 * @param  string $category
	 * @param  string $transformer
	 * @return void
	 */
	public function registerTransformer($category, $transformer) {
		if (!in_array($category, ['type', 'query', 'mutation'])) {
			throw new Exception\TransformerException('Unable to find given category');
		}

		$this->transformers[$category][] = $this->app->make($transformer);
	}

	/**
	 * Apply transformation. When a transformer can handle the given class, the
	 * while will break and return the current state
	 *
	 * @param  string $type
	 * @param  mixed  $cls
	 * @return mixed
	 */
	private function applyTransformers($type, $cls) {
		if (!array_key_exists($type, $this->transformers)) {
			throw new Exception\TransformerException('Cannot transform given type');
		}

		// Convert string to instance if possible
		if (is_string($cls)) {
			$cls = $this->app->make($cls);
		}

		foreach ($this->transformers[$type] as $transformer) {
			if ($transformer->supports($cls)) {
				return $transformer->transform($cls);
			}
		}

		// No transformer was found. Let's throw an error : the given class is
		// not supported at all
		throw new Exception\TransformerNotFoundException('There\'s no transformer for given class');
	}

	/**
	 * Register generator. A generator performs generations based on given
	 * ObjectType. Instead of transformer, if multiple generators can handles a
	 * given type, all will be called and will be merged
	 *
	 * A generator cannot be used to generate ObjectType
	 *
	 * @param  string $category
	 * @param  string $generator
	 * @return void
	 */
	public function registerGenerator($category, $generator) {
		if (!in_array($category, ['query', 'mutation'])) {
			throw new Exception\GeneratorException('Unable to find given category');
		}

		$generator    = $this->app->make($generator);
		$dependencies = $generator->dependsOn();

		// Assert generator has all is types dependencies
		if (!empty($dependencies)) {
			foreach ($dependencies as $dependency) {
				$dependency = strtolower($dependency);

				if (!array_key_exists($dependency, $this->types)) {
					return;
				}
			}
		}

		$this->generators[$category][] = $generator;
	}

	/**
	 * Apply generators
	 *
	 * @param  string $type
	 * @param  string $cls
	 * @param  array  $data
	 *
	 * @return array
	 */
	private function applyGenerators($type, array $data = []) {
		if (!array_key_exists($type, $this->generators)) {
			throw new Exception\GeneratorException('Cannot generate given type');
		}

		// A generator can only handle types
		$types = $this->types;

		foreach ($this->generators[$type] as $generator) {
			foreach ($types as $type) {
				if ($generator->supports($type)) {
					$key   = $generator->getKey($type);
					$field = $generator->generate($type);

					if (array_key_exists($key, $data)) {
						throw new Exception\GeneratorException('Cannot override existing field');
					}

					$data[$key] = $field;
				}
			}
		}

		return $data;
	}

	/**
	 * Assert schema exists
	 *
	 * @param  string $name
	 * @return bool
	 */
	public function hasSchema($name) {
		return array_key_exists($name, $this->schemas);
	}
}
