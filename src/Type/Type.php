<?php
namespace StudioNet\GraphQL\Type;

/**
 * Type
 *
 * @see TypeInterface
 * @abstract
 */
abstract class Type implements TypeInterface {
	/**
	 * {@inheritDoc}
	 */
	public function getFields() {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function getArguments() {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function getFieldResolver($name, $field) {
		$method = studly_case('resolve-' . $name . '-field');

		// Assert $field is an array and try to find the resolve key
		if (is_array($field) and array_key_exists('resolve', $field)) {
			return $field['resolve'];
		}

		// Otherwise, fallback on an existing method
		else if (method_exists($this, $method)) {
			return [$this, $method];
		}

		// Prefer use the default resolver
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getBuiltFields() {
		$fields = $this->getFields();

		foreach ($fields as $name => $field) {
			$resolver = $this->getFieldResolver($name, $field);

			if (empty($resolver)) {
				continue;
			}

			if (is_array($field)) {
				$fields[$name]['resolve'] = $resolver;
			} else {
				$fields[$name] = [
					'type'    => $field,
					'resolve' => $resolver
				];
			}
		}

		return $fields;
	}
}
